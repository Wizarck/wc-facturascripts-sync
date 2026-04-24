<?php
/**
 * DB schema: wp_wc_fs_sync_queue.
 *
 * @package WcFacturascriptsSync\Core
 */

namespace WcFacturascriptsSync\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Schema
 *
 * dbDelta-managed. Version tracked in option 'wc_fs_sync_schema_version';
 * install() runs on plugin activation and whenever the stored version is
 * below SCHEMA_VERSION.
 */
final class Schema {

	public const OPTION_KEY    = 'wc_fs_sync_schema_version';
	public const SCHEMA_VERSION = '1.0.0';

	/**
	 * Activation entry point.
	 */
	public static function install(): void {
		$installed = (string) get_option( self::OPTION_KEY, '' );
		if ( version_compare( $installed, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table           = $wpdb->prefix . 'wc_fs_sync_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			correlation_id CHAR(36) NOT NULL,
			entity_type VARCHAR(32) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(32) NOT NULL,
			payload LONGTEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 6,
			next_attempt_at DATETIME NOT NULL,
			last_error TEXT NULL,
			fs_resource_id VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_correlation (correlation_id),
			KEY idx_status_next (status, next_attempt_at),
			KEY idx_entity (entity_type, entity_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPTION_KEY, self::SCHEMA_VERSION, false );
	}
}
