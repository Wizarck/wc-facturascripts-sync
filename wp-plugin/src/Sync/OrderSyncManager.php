<?php
/**
 * Enqueues albaran/invoice sync on WC order status transitions.
 *
 * @package WcFacturascriptsSync\Sync
 */

namespace WcFacturascriptsSync\Sync;

use WcFacturascriptsSync\Adapters\WooCommerceOrderAdapter;
use WcFacturascriptsSync\Core\SyncQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Class OrderSyncManager
 *
 * Transition rules (locked from the plan — change = new ADR):
 *   processing → entregado  ⇒ enqueue 'deliver'  (FS AlbaranCliente)
 *   entregado  → facturado  ⇒ enqueue 'invoice'  (FS FacturaCliente)
 *
 * Any other transition is a no-op. Pending / on-hold / cancelled never
 * touch fiscal books.
 */
final class OrderSyncManager {

	public function __construct( private readonly SyncQueue $queue ) {}

	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_change' ), 20, 4 );
	}

	public function on_status_change( int $order_id, string $from, string $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
		}

		$deliver_from_statuses = (array) apply_filters( 'wc_fs_sync_deliver_from', array( 'processing' ) );
		$invoice_from_statuses = (array) apply_filters( 'wc_fs_sync_invoice_from', array( 'entregado' ) );

		if ( 'entregado' === $to && in_array( $from, $deliver_from_statuses, true ) ) {
			$correlation_id = $this->queue->enqueue(
				'order',
				$order_id,
				'deliver',
				WooCommerceOrderAdapter::to_albaran_payload( $order )
			);
			do_action( 'wc_ops_emit', 'order.delivered', array( 'order_id' => $order_id ), $correlation_id );
			return;
		}

		if ( 'facturado' === $to && in_array( $from, $invoice_from_statuses, true ) ) {
			$correlation_id = $this->queue->enqueue(
				'order',
				$order_id,
				'invoice',
				array( 'order_id' => $order_id ) // Payload resolved fresh at process time.
			);
			do_action( 'wc_ops_emit', 'order.invoiced', array( 'order_id' => $order_id ), $correlation_id );
		}
	}
}
