<?php
/**
 * Enqueues customer sync jobs on WP lifecycle events.
 *
 * @package WcFacturascriptsSync\Sync
 */

namespace WcFacturascriptsSync\Sync;

use WcFacturascriptsSync\Adapters\WooCommerceCustomerAdapter;
use WcFacturascriptsSync\Core\SyncQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Class CustomerSyncManager
 *
 * WordPress is the master for customer identity. Whenever a user is created
 * or their billing data changes, enqueue an upsert job. The queue processor
 * resolves create-vs-update by looking up _wc_fs_sync_customer_id first and
 * falling back to FS correlation_id search.
 */
final class CustomerSyncManager {

	public function __construct( private readonly SyncQueue $queue ) {}

	/**
	 * Register hooks. Safe to call multiple times (WP de-duplicates).
	 */
	public function register_hooks(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ), 20 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20 );
		add_action( 'woocommerce_checkout_update_user_meta', array( $this, 'on_profile_update' ), 20 );
	}

	public function on_user_register( int $user_id ): void {
		$this->enqueue_if_b2b( $user_id, 'create' );
	}

	public function on_profile_update( int $user_id ): void {
		$this->enqueue_if_b2b( $user_id, 'update' );
	}

	private function enqueue_if_b2b( int $user_id, string $action ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$roles = (array) $user->roles;
		/**
		 * Which roles sync to FS? tenant-config can extend this list.
		 *
		 * @param string[] $roles_to_sync
		 */
		$sync_roles = (array) apply_filters( 'wc_fs_sync_customer_roles', array( 'b2b_customer', 'customer' ) );
		if ( empty( array_intersect( $roles, $sync_roles ) ) ) {
			return;
		}

		$payload = WooCommerceCustomerAdapter::to_payload( $user_id );
		if ( empty( $payload ) ) {
			return;
		}

		$this->queue->enqueue( 'customer', $user_id, $action, $payload );
	}
}
