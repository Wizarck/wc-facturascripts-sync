<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package WcFacturascriptsSync\Core
 */

namespace WcFacturascriptsSync\Core;

use WcFacturascriptsSync\Callbacks\FsCallbackController;
use WcFacturascriptsSync\Callbacks\HmacVerifier;
use WcFacturascriptsSync\Sync\QueueProcessor;
use WcFacturascriptsSync\Sync\CustomerSyncManager;
use WcFacturascriptsSync\Sync\OrderSyncManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Single entry point from wc-facturascripts-sync.php. Registers hooks that
 * translate WooCommerce lifecycle events into queue entries; the queue is
 * drained by a separate WP-CLI / system cron command (not WP-Cron).
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private ?FsClient $client             = null;
	private ?SyncQueue $queue             = null;
	private ?CustomerSyncManager $customer = null;
	private ?OrderSyncManager $order       = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot hooks. Idempotent.
	 */
	public function boot(): void {
		// Self-heal schema on every boot (idempotent: bails immediately when
		// already at the current version). Belt-and-suspenders for the case
		// where the plugin was upgraded without an explicit reactivation and
		// SCHEMA_VERSION bumped.
		Schema::install();

		$config = $this->resolve_config();

		$this->client   = new FsClient( $config );
		$this->queue    = new SyncQueue();
		$this->customer = new CustomerSyncManager( $this->queue );
		$this->order    = new OrderSyncManager( $this->queue );

		$this->customer->register_hooks();
		$this->order->register_hooks();

		// Inbound callbacks from FS → WP.
		$hmac_secret = defined( 'WC_FS_BRIDGE_HMAC_SECRET' ) ? WC_FS_BRIDGE_HMAC_SECRET : (string) get_option( 'wc_fs_sync_hmac_secret', '' );
		$verifier    = new HmacVerifier( (string) apply_filters( 'wc_fs_sync_hmac_secret', $hmac_secret ) );
		( new FsCallbackController( $verifier ) )->register_hooks();

		// Daily cron: prune dedupe rows older than 48h.
		add_action( 'wc_fs_sync_prune_dedupe', array( Schema::class, 'prune_dedupe' ) );
		if ( ! wp_next_scheduled( 'wc_fs_sync_prune_dedupe' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'wc_fs_sync_prune_dedupe' );
		}

		// WP-CLI command registration (processor runs from system cron).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'wc-fs-sync process',
				function ( array $_args, array $assoc ): void {
					$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 20;
					( new QueueProcessor( $this->queue, $this->client ) )->drain( $limit );
				}
			);
		}
	}

	public function client(): ?FsClient {
		return $this->client;
	}

	public function queue(): ?SyncQueue {
		return $this->queue;
	}

	/**
	 * Resolve API base URL / token from env constants or options.
	 *
	 * @return array<string, string>
	 */
	private function resolve_config(): array {
		$base_url = defined( 'FS_API_BASE_URL' ) ? FS_API_BASE_URL : (string) get_option( 'wc_fs_sync_base_url', '' );
		$token    = defined( 'FS_API_TOKEN' ) ? FS_API_TOKEN : (string) get_option( 'wc_fs_sync_token', '' );

		return array(
			'base_url' => (string) apply_filters( 'wc_fs_sync_base_url', $base_url ),
			'token'    => (string) apply_filters( 'wc_fs_sync_token', $token ),
		);
	}
}
