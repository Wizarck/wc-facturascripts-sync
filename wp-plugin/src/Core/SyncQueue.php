<?php
/**
 * Persistence layer for wp_wc_fs_sync_queue.
 *
 * @package WcFacturascriptsSync\Core
 */

namespace WcFacturascriptsSync\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class SyncQueue
 *
 * Wraps the queue table. enqueue() is idempotent via (entity_type, entity_id,
 * action) — duplicate triggers within the same lifecycle yield the same row.
 *
 * Locking: reserve() uses FOR UPDATE SKIP LOCKED so multiple workers never
 * pick the same row.
 */
final class SyncQueue {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wc_fs_sync_queue';
	}

	/**
	 * Insert or return existing row for the given (entity, action). Idempotent.
	 *
	 * @param string               $entity_type e.g. 'customer' | 'order'.
	 * @param int                  $entity_id    WC id.
	 * @param string               $action       e.g. 'create' | 'deliver' | 'invoice'.
	 * @param array<string, mixed> $payload      Serialized to JSON.
	 * @return string Correlation id (UUID v7 if available).
	 */
	public function enqueue( string $entity_type, int $entity_id, string $action, array $payload ): string {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, correlation_id FROM {$this->table}
				 WHERE entity_type = %s AND entity_id = %d AND action = %s
				 AND status IN ('pending','retry','in_progress')
				 LIMIT 1",
				$entity_type,
				$entity_id,
				$action
			),
			ARRAY_A
		);

		if ( is_array( $existing ) && ! empty( $existing['correlation_id'] ) ) {
			return (string) $existing['correlation_id'];
		}

		$correlation_id = $this->generate_correlation_id();
		$now            = current_time( 'mysql', true );

		$wpdb->insert(
			$this->table,
			array(
				'correlation_id'  => $correlation_id,
				'entity_type'     => $entity_type,
				'entity_id'       => $entity_id,
				'action'          => $action,
				'payload'         => wp_json_encode( $payload ),
				'status'          => 'pending',
				'attempts'        => 0,
				'max_attempts'    => 6,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return $correlation_id;
	}

	/**
	 * Reserve up to $limit ready rows (pending | retry with next_attempt_at ≤ now).
	 *
	 * Returns the reserved rows with status already set to 'in_progress' so
	 * concurrent workers don't pick them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function reserve( int $limit ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE status IN ('pending','retry')
				   AND next_attempt_at <= %s
				 ORDER BY id ASC
				 LIMIT %d
				 FOR UPDATE",
				$now,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$wpdb->query( 'COMMIT' );
			return array();
		}

		$ids         = array_map( static fn( $r ) => (int) $r['id'], $rows );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'in_progress', updated_at = %s WHERE id IN ($placeholders)",
				array_merge( array( $now ), $ids )
			)
		);
		$wpdb->query( 'COMMIT' );

		return $rows;
	}

	/**
	 * Mark a reserved row as done.
	 */
	public function mark_done( int $id, ?string $fs_resource_id = null ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			array(
				'status'          => 'done',
				'fs_resource_id'  => $fs_resource_id,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record a failure, compute next_attempt_at via exponential backoff, and
	 * promote to 'dead' when max_attempts is reached.
	 */
	public function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, max_attempts FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		if ( empty( $row ) ) {
			return;
		}

		$attempts = (int) $row['attempts'] + 1;
		$max      = (int) $row['max_attempts'];
		$is_dead  = $attempts >= $max;

		$delay_seconds = min( 6 * 3600, 60 * ( 2 ** ( $attempts - 1 ) ) );
		$next_at       = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );

		$wpdb->update(
			$this->table,
			array(
				'status'          => $is_dead ? 'dead' : 'retry',
				'attempts'        => $attempts,
				'last_error'      => substr( $error, 0, 2000 ),
				'next_attempt_at' => $next_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Generate correlation id — prefers UUIDv7 for lexicographic time order.
	 */
	private function generate_correlation_id(): string {
		if ( class_exists( '\Ramsey\Uuid\Uuid' ) && method_exists( '\Ramsey\Uuid\Uuid', 'uuid7' ) ) {
			return \Ramsey\Uuid\Uuid::uuid7()->toString();
		}
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
