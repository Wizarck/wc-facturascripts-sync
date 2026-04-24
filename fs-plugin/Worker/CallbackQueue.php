<?php
/**
 * FS-side outbound callback retry queue.
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Worker;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\ToolBox;

/**
 * Class CallbackQueue
 *
 * Persists pending callbacks so Model::save() never blocks on WP latency.
 * A cron worker (cron_job.php in the FS cron sidecar) calls drain() on a
 * schedule.
 *
 * Schema is created on plugin enable / update via Init::update().
 */
final class CallbackQueue
{
    private const TABLE = 'wc_fs_sync_out_queue';

    /**
     * Insert a pending callback row.
     */
    public static function enqueue(string $event_id, string $correlation_id, array $payload): void
    {
        self::ensure_schema();

        $db  = new DataBase();
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . self::TABLE . ' '
            . '(correlation_id, event_id, payload, status, attempts, next_attempt_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, \'pending\', 0, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)';

        $db->exec(
            $sql,
            array(
                $correlation_id,
                $event_id,
                json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
                $now,
                $now,
                $now,
            )
        );
    }

    /**
     * Drain up to $limit pending/ready rows. Called from cron.
     */
    public static function drain(int $limit = 20): int
    {
        self::ensure_schema();
        $db  = new DataBase();
        $now = date('Y-m-d H:i:s');

        $rows = $db->select(
            'SELECT * FROM ' . self::TABLE . ' '
            . "WHERE status IN ('pending','retry') AND next_attempt_at <= ? "
            . 'ORDER BY id ASC LIMIT ' . (int) $limit,
            array($now)
        );
        if (empty($rows)) {
            return 0;
        }

        $processed = 0;
        foreach ($rows as $row) {
            // Claim the row optimistically.
            $ok = $db->exec(
                'UPDATE ' . self::TABLE . " SET status = 'in_progress', updated_at = ? WHERE id = ? AND status IN ('pending','retry')",
                array($now, $row['id'])
            );
            if (! $ok) {
                continue;
            }

            $payload = json_decode((string) $row['payload'], true);
            $payload = is_array($payload) ? $payload : array();
            $success = WpBridge::send((string) $row['event_id'], (string) $row['correlation_id'], $payload);

            if ($success) {
                $db->exec('UPDATE ' . self::TABLE . " SET status = 'done', updated_at = ? WHERE id = ?", array(date('Y-m-d H:i:s'), $row['id']));
                $processed++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $is_dead  = $attempts >= 6;
                $delay    = min(6 * 3600, 60 * (2 ** ($attempts - 1)));
                $db->exec(
                    'UPDATE ' . self::TABLE . ' '
                    . 'SET status = ?, attempts = ?, next_attempt_at = ?, last_error = ?, updated_at = ? WHERE id = ?',
                    array(
                        $is_dead ? 'dead' : 'retry',
                        $attempts,
                        date('Y-m-d H:i:s', time() + $delay),
                        'HTTP delivery failed',
                        date('Y-m-d H:i:s'),
                        $row['id'],
                    )
                );
            }
        }
        return $processed;
    }

    /**
     * Idempotent table creation. FS uses MySQL/MariaDB typically; if PgSQL,
     * adjust types accordingly.
     */
    public static function ensure_schema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $db  = new DataBase();
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'correlation_id CHAR(36) NOT NULL,'
            . 'event_id VARCHAR(64) NOT NULL,'
            . 'payload LONGTEXT NULL,'
            . "status VARCHAR(16) NOT NULL DEFAULT 'pending',"
            . 'attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'next_attempt_at DATETIME NOT NULL,'
            . 'last_error TEXT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_correlation_event (correlation_id, event_id),'
            . 'KEY idx_status_next (status, next_attempt_at)'
            . ')';

        try {
            $db->exec($sql);
        } catch (\Throwable $e) {
            ToolBox::log()->warning('wc-facturascripts-sync schema create failed: ' . $e->getMessage());
        }
    }
}
