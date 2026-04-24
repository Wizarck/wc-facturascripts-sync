<?php
/**
 * Drains the sync queue against FS.
 *
 * @package WcFacturascriptsSync\Sync
 */

namespace WcFacturascriptsSync\Sync;

use WcFacturascriptsSync\Adapters\WooCommerceOrderAdapter;
use WcFacturascriptsSync\Adapters\WooCommerceCustomerAdapter;
use WcFacturascriptsSync\Core\FsApiException;
use WcFacturascriptsSync\Core\FsClient;
use WcFacturascriptsSync\Core\SyncQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Class QueueProcessor
 *
 * Invoked from system cron via:
 *   wp wc-fs-sync process --limit=20
 *
 * Uses SELECT ... FOR UPDATE in SyncQueue::reserve() so multiple workers
 * never pick the same row. Idempotency on FS side via numero2 == correlation_id.
 */
final class QueueProcessor {

	public function __construct(
		private readonly SyncQueue $queue,
		private readonly FsClient $client,
	) {}

	public function drain( int $limit = 20 ): int {
		$jobs      = $this->queue->reserve( $limit );
		$processed = 0;

		foreach ( $jobs as $job ) {
			try {
				$this->process_one( $job );
				$processed++;
			} catch ( \Throwable $e ) {
				$became_dead = $this->queue->mark_failed( (int) $job['id'], $e->getMessage() );
				$this->emit_failure_event( $job, $e );

				if ( $became_dead ) {
					// Promote to L2 — this event carries hitl=amber in the registry,
					// so HitlListener will enqueue an approval without the processor
					// needing to know about HITL.
					do_action(
						'wc_ops_emit',
						'sync_queue.dead',
						array(
							'entity_type' => (string) $job['entity_type'],
							'entity_id'   => (int) $job['entity_id'],
							'action'      => (string) $job['action'],
							'last_error'  => $e->getMessage(),
						),
						(string) $job['correlation_id']
					);
				}
			}
		}

		return $processed;
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function process_one( array $job ): void {
		$entity_type = (string) $job['entity_type'];
		$action      = (string) $job['action'];
		$id          = (int) $job['id'];

		switch ( "$entity_type:$action" ) {
			case 'customer:create':
			case 'customer:update':
				$fs_id = $this->sync_customer( $job );
				$this->queue->mark_done( $id, (string) $fs_id );
				do_action( 'wc_ops_emit', 'customer.fs.synced', array( 'customer_id' => (int) $job['entity_id'], 'fs_id' => $fs_id ), (string) $job['correlation_id'] );
				break;

			case 'order:deliver':
				$fs_id = $this->sync_albaran( $job );
				$this->queue->mark_done( $id, (string) $fs_id );
				do_action( 'wc_ops_emit', 'albaran.fs.created', array( 'order_id' => (int) $job['entity_id'], 'fs_id' => $fs_id ), (string) $job['correlation_id'] );
				break;

			case 'order:invoice':
				$fs_id = $this->sync_invoice( $job );
				$this->queue->mark_done( $id, (string) $fs_id );
				do_action( 'wc_ops_emit', 'invoice.fs.created', array( 'order_id' => (int) $job['entity_id'], 'fs_id' => $fs_id ), (string) $job['correlation_id'] );
				break;

			default:
				throw new FsApiException( 'Unknown job type: ' . $entity_type . ':' . $action );
		}
	}

	private function sync_customer( array $job ): int {
		$correlation_id = (string) $job['correlation_id'];
		$wp_user_id     = (int) $job['entity_id'];

		// Look up by correlation_id (idempotent) or by existing fs_customer_id meta.
		$existing = $this->client->find_by_correlation_id( 'clientes', $correlation_id );
		if ( null === $existing ) {
			$fs_id = WooCommerceCustomerAdapter::get_fs_customer_id( $wp_user_id );
			if ( $fs_id ) {
				$existing = array( 'codcliente' => (string) $fs_id );
			}
		}

		$payload            = json_decode( (string) $job['payload'], true ) ?: array();
		$payload['numero2'] = $correlation_id;

		if ( is_array( $existing ) && isset( $existing['codcliente'] ) ) {
			$response = $this->client->update( 'clientes', (string) $existing['codcliente'], $payload );
		} else {
			$response = $this->client->create( 'clientes', $payload );
		}

		$fs_id = (int) ( $response['codcliente'] ?? $response['id'] ?? 0 );
		if ( $fs_id > 0 ) {
			WooCommerceCustomerAdapter::set_fs_customer_id( $wp_user_id, $fs_id );
		}
		return $fs_id;
	}

	private function sync_albaran( array $job ): int {
		$correlation_id = (string) $job['correlation_id'];

		$existing = $this->client->find_by_correlation_id( 'albaranclientes', $correlation_id );
		if ( is_array( $existing ) && isset( $existing['idalbaran'] ) ) {
			return (int) $existing['idalbaran'];
		}

		$order = wc_get_order( (int) $job['entity_id'] );
		if ( ! $order ) {
			throw new FsApiException( 'Order not found: ' . $job['entity_id'] );
		}

		$payload            = WooCommerceOrderAdapter::to_albaran_payload( $order );
		$payload['numero2'] = $correlation_id;

		$response = $this->client->create( 'albaranclientes', $payload );
		$fs_id    = (int) ( $response['idalbaran'] ?? $response['id'] ?? 0 );

		$numero = (string) ( $response['numero'] ?? '' );
		if ( '' !== $numero ) {
			WooCommerceOrderAdapter::set_fs_albaran( $order, $numero, $fs_id ?: null );
		}

		return $fs_id;
	}

	private function sync_invoice( array $job ): int {
		$correlation_id = (string) $job['correlation_id'];

		$existing = $this->client->find_by_correlation_id( 'facturaclientes', $correlation_id );
		if ( is_array( $existing ) && isset( $existing['idfactura'] ) ) {
			return (int) $existing['idfactura'];
		}

		$order = wc_get_order( (int) $job['entity_id'] );
		if ( ! $order ) {
			throw new FsApiException( 'Order not found: ' . $job['entity_id'] );
		}

		// FS invoice creation from an existing albarán is a dedicated endpoint.
		// For now, we post a payload equivalent to the albarán; the precise
		// AlbaranCliente → FacturaCliente pipe is plugin-specific and gets
		// refined once we test against a live FS instance.
		$payload            = WooCommerceOrderAdapter::to_albaran_payload( $order );
		$payload['numero2'] = $correlation_id;

		$response = $this->client->create( 'facturaclientes', $payload );
		$fs_id    = (int) ( $response['idfactura'] ?? $response['id'] ?? 0 );

		$numero  = (string) ( $response['numero'] ?? '' );
		$pdf_url = isset( $response['pdf_url'] ) ? (string) $response['pdf_url'] : null;
		if ( '' !== $numero ) {
			WooCommerceOrderAdapter::set_fs_invoice( $order, $numero, $fs_id ?: null, $pdf_url );
		}

		return $fs_id;
	}

	private function emit_failure_event( array $job, \Throwable $e ): void {
		$entity_type = (string) $job['entity_type'];
		$action      = (string) $job['action'];
		$event_id    = 'albaran.fs.failed';
		if ( 'order' === $entity_type && 'invoice' === $action ) {
			$event_id = 'invoice.fs.failed';
		}
		do_action( 'wc_ops_emit', $event_id, array(
			'entity_type' => $entity_type,
			'entity_id'   => (int) $job['entity_id'],
			'action'      => $action,
			'error'       => $e->getMessage(),
		), (string) $job['correlation_id'] );
	}
}
