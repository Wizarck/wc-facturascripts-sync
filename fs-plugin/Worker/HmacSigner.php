<?php
/**
 * HMAC-SHA256 request signer — matches shared/protocol/hmac-spec.md.
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Worker;

/**
 * Class HmacSigner
 *
 * Stateless. Builds the X-WC-FS-Signature header value given the request
 * body, correlation id, timestamp, and shared secret.
 *
 * Canonical string: "timestamp\ncorrelation_id\nbody"
 */
final class HmacSigner
{
    /**
     * Produce the canonical signature string "sha256=<hex>".
     */
    public static function sign(string $timestamp, string $correlation_id, string $body, string $secret): string
    {
        $canonical = $timestamp . "\n" . $correlation_id . "\n" . $body;
        return 'sha256=' . hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Build the four outgoing headers for a signed callback POST.
     *
     * @return array<string, string>
     */
    public static function headers(string $correlation_id, string $body, string $secret, string $bearer_token = ''): array
    {
        $ts        = (string) time();
        $signature = self::sign($ts, $correlation_id, $body, $secret);

        $headers = array(
            'Content-Type'           => 'application/json',
            'X-WC-FS-Timestamp'      => $ts,
            'X-WC-FS-Correlation-Id' => $correlation_id,
            'X-WC-FS-Signature'      => $signature,
        );

        if ('' !== $bearer_token) {
            $headers['Authorization'] = 'Bearer ' . $bearer_token;
        }

        return $headers;
    }
}
