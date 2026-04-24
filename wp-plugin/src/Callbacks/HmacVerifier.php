<?php
/**
 * HMAC-SHA256 callback verifier (mirror of fs-plugin/Worker/HmacSigner).
 *
 * @package WcFacturascriptsSync\Callbacks
 */

namespace WcFacturascriptsSync\Callbacks;

defined( 'ABSPATH' ) || exit;

/**
 * Class HmacVerifier
 *
 * Verifies X-WC-FS-Signature against the canonical "timestamp\ncorrelation_id\nbody"
 * string using the shared HMAC secret. Rejects replays older than 5 min.
 */
final class HmacVerifier {

	private const MAX_SKEW_SECONDS = 300;

	public function __construct( private readonly string $secret ) {}

	/**
	 * @param array<string, string> $headers Raw HTTP headers (lowercased keys are fine).
	 * @param string                $body    Raw request body.
	 * @return array{ok:bool, reason?:string, correlation_id?:string}
	 */
	public function verify( array $headers, string $body ): array {
		$lookup = array();
		foreach ( $headers as $k => $v ) {
			$lookup[ strtolower( (string) $k ) ] = (string) $v;
		}

		$timestamp      = $lookup['x-wc-fs-timestamp']      ?? '';
		$correlation_id = $lookup['x-wc-fs-correlation-id'] ?? '';
		$signature      = $lookup['x-wc-fs-signature']      ?? '';

		if ( '' === $timestamp || '' === $correlation_id || '' === $signature ) {
			return array( 'ok' => false, 'reason' => 'missing_headers' );
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return array( 'ok' => false, 'reason' => 'replay_window_expired' );
		}

		if ( '' === $this->secret ) {
			return array( 'ok' => false, 'reason' => 'secret_not_configured' );
		}

		$canonical = $timestamp . "\n" . $correlation_id . "\n" . $body;
		$expected  = 'sha256=' . hash_hmac( 'sha256', $canonical, $this->secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return array( 'ok' => false, 'reason' => 'signature_mismatch' );
		}

		return array( 'ok' => true, 'correlation_id' => $correlation_id );
	}
}
