<?php
/**
 * REST endpoint receiving FS → WP callbacks.
 *
 * @package WcFacturascriptsSync\Callbacks
 */

namespace WcFacturascriptsSync\Callbacks;

use WcFacturascriptsSync\Adapters\WooCommerceOrderAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Class FsCallbackController
 *
 * Route: POST /wp-json/wc-fs-sync/v1/callback
 *
 * Responsibilities:
 *   1. HMAC verification (via HmacVerifier).
 *   2. Idempotent dedupe by correlation_id + event_id (48h TTL table).
 *   3. Dispatch the event to wc-ops-suite via do_action('wc_ops_emit').
 *   4. Apply side effects:
 *      - albaran.fs.created          → persist FS albaran numero on WC order
 *      - invoice.fs.created          → persist FS invoice numero + PDF URL
 *      - verifactu.aeat.ack          → mark invoice verifactu_ok on order meta
 *      - bank.norma43.reconcile.hit  → transition WC order to 'completed'
 */
final class FsCallbackController {

	public function __construct( private readonly HmacVerifier $verifier ) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			'wc-fs-sync/v1',
			'/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // HMAC is our gate.
			)
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$body    = $request->get_body();
		$headers = array();
		foreach ( $request->get_headers() as $k => $v ) {
			$headers[ $k ] = is_array( $v ) ? (string) reset( $v ) : (string) $v;
		}

		$result = $this->verifier->verify( $headers, $body );
		if ( empty( $result['ok'] ) ) {
			return new \WP_REST_Response( array( 'error' => $result['reason'] ?? 'invalid' ), 401 );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) || empty( $json['event_id'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}

		$event_id       = (string) $json['event_id'];
		$correlation_id = (string) ( $json['correlation_id'] ?? $result['correlation_id'] ?? '' );
		$payload        = isset( $json['payload'] ) && is_array( $json['payload'] ) ? $json['payload'] : array();

		// Dedupe by (correlation_id, event_id) — same callback retried is a no-op.
		if ( $this->already_processed( $correlation_id, $event_id ) ) {
			return new \WP_REST_Response( array( 'ok' => true, 'dedup' => true ), 200 );
		}

		try {
			$this->apply_side_effects( $event_id, $payload );
		} catch ( \Throwable $e ) {
			error_log( '[wc-fs-sync] callback side-effect failed: ' . $e->getMessage() );
		}

		// Propagate to wc-ops-suite so dashboard / telegram / email channels fire.
		do_action( 'wc_ops_emit', $event_id, $payload, $correlation_id );

		$this->mark_processed( $correlation_id, $event_id );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Translate events into WC-visible side effects.
	 */
	private function apply_side_effects( string $event_id, array $payload ): void {
		$wc_order_id = (int) ( $payload['wc_order_id'] ?? 0 );
		$order       = $wc_order_id > 0 ? wc_get_order( $wc_order_id ) : null;

		switch ( $event_id ) {
			case 'albaran.fs.created':
				if ( $order instanceof \WC_Order ) {
					WooCommerceOrderAdapter::set_fs_albaran( $order, (string) ( $payload['numero'] ?? '' ) );
				}
				break;

			case 'invoice.fs.created':
				if ( $order instanceof \WC_Order ) {
					WooCommerceOrderAdapter::set_fs_invoice(
						$order,
						(string) ( $payload['numero'] ?? '' ),
						isset( $payload['pdf_url'] ) ? (string) $payload['pdf_url'] : null
					);
				}
				break;

			case 'verifactu.aeat.ack':
				if ( $order instanceof \WC_Order ) {
					$order->update_meta_data( '_wc_fs_sync_verifactu_ok', 1 );
					if ( ! empty( $payload['csv'] ) ) {
						$order->update_meta_data( '_wc_fs_sync_verifactu_csv', (string) $payload['csv'] );
					}
					$order->save();
				}
				break;

			case 'verifactu.aeat.rejected':
				if ( $order instanceof \WC_Order ) {
					$order->update_meta_data( '_wc_fs_sync_verifactu_error', (string) ( $payload['error'] ?? '' ) );
					$order->save();
				}
				break;

			case 'bank.norma43.reconcile.hit':
				$wc_order_id = $this->resolve_order_from_invoice( (int) ( $payload['idfactura'] ?? 0 ) );
				if ( $wc_order_id > 0 ) {
					$order = wc_get_order( $wc_order_id );
					if ( $order instanceof \WC_Order && ! in_array( $order->get_status(), array( 'completed', 'refunded' ), true ) ) {
						$order->payment_complete();
					}
				}
				break;

			default:
				// No direct WC side effect — wc-ops-suite still receives the event.
				break;
		}
	}

	/**
	 * When FS reports a recibo paid, we only know the FS idfactura. Map it
	 * back to a WC order by scanning our own meta.
	 */
	private function resolve_order_from_invoice( int $fs_invoice_id ): int {
		if ( $fs_invoice_id <= 0 ) {
			return 0;
		}
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_wc_fs_sync_invoice_id',
				'meta_value' => $fs_invoice_id,
				'limit'      => 1,
				'return'     => 'ids',
			)
		);
		return empty( $orders ) ? 0 : (int) $orders[0];
	}

	private function already_processed( string $correlation_id, string $event_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_fs_sync_dedupe';
		$row   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE correlation_id = %s AND event_id = %s LIMIT 1",
				$correlation_id,
				$event_id
			)
		);
		return ! empty( $row );
	}

	private function mark_processed( string $correlation_id, string $event_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_fs_sync_dedupe';
		$wpdb->insert(
			$table,
			array(
				'correlation_id' => $correlation_id,
				'event_id'       => $event_id,
				'processed_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}
}
