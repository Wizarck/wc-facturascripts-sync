<?php
/**
 * Sends HTTP POST callbacks from FS → WP.
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Worker;

use FacturaScripts\Core\Base\ToolBox;

/**
 * Class WpBridge
 *
 * Single outbound HTTP client used by all Extension\Model hooks. Reads
 * endpoint + shared secret from the secrets ini file that the
 * scripts/sops-to-env.sh distributor writes to
 * <fs-root>/MyFiles/secrets.ini.
 *
 * send() does NOT block the save() path — the Extension classes enqueue
 * via CallbackQueue and a cron worker drains the queue. This class is
 * called from that worker.
 */
final class WpBridge
{
    private const CURL_TIMEOUT = 8;

    /**
     * Attempt one POST. Returns true on 2xx, false otherwise.
     * Never throws; failures are logged and surfaced via return value
     * so the queue can increment attempt counter.
     */
    public static function send(string $event_id, string $correlation_id, array $payload): bool
    {
        $endpoint = self::config('WP_ENDPOINT');
        $secret   = self::config('WC_FS_BRIDGE_HMAC_SECRET');
        $bearer   = self::config('WC_FS_BRIDGE_HEALTH_TOKEN');

        if ('' === $endpoint || '' === $secret) {
            ToolBox::log()->warning('wc-facturascripts-sync: missing WP_ENDPOINT or HMAC secret in MyFiles/secrets.ini');
            return false;
        }

        $body_array = array(
            'event_id'       => $event_id,
            'correlation_id' => $correlation_id,
            'emitted_at'     => gmdate('c'),
            'payload'        => $payload,
        );
        $body = json_encode($body_array, JSON_UNESCAPED_UNICODE);
        if (false === $body) {
            return false;
        }

        $headers = HmacSigner::headers($correlation_id, $body, $secret, $bearer);
        $header_lines = array();
        foreach ($headers as $k => $v) {
            $header_lines[] = $k . ': ' . $v;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
        ));

        $response    = curl_exec($ch);
        $http_code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno  = curl_errno($ch);
        $curl_errmsg = curl_error($ch);
        curl_close($ch);

        if (0 !== $curl_errno) {
            ToolBox::log()->warning(sprintf('wc-facturascripts-sync cURL error %d: %s (%s)', $curl_errno, $curl_errmsg, $event_id));
            return false;
        }

        if ($http_code >= 200 && $http_code < 300) {
            return true;
        }

        ToolBox::log()->warning(sprintf(
            'wc-facturascripts-sync WP returned HTTP %d for %s — body: %s',
            $http_code,
            $event_id,
            substr((string) $response, 0, 300)
        ));
        return false;
    }

    /**
     * UUIDv7 if we can generate one via the PHP implementation below;
     * otherwise a v4 fallback.
     */
    public static function generate_correlation_id(): string
    {
        try {
            $data    = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (\Throwable $e) {
            return uniqid('wfs_', true);
        }
    }

    /**
     * Read a config value from MyFiles/secrets.ini with env fallback.
     */
    private static function config(string $key): string
    {
        static $ini = null;
        if (null === $ini) {
            $path = FS_FOLDER . '/MyFiles/secrets.ini';
            $ini  = is_readable($path) ? (parse_ini_file($path) ?: array()) : array();
        }
        if (isset($ini[$key])) {
            return (string) $ini[$key];
        }
        $env = getenv($key);
        return false === $env ? '' : (string) $env;
    }
}
