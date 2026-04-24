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
		// WP_REST_Request::get_headers() normalizes dashes to underscores
		// (x-wc-fs-timestamp → x_wc_fs_timestamp). Raw PHP server handlers
		// may return them in HTTP_X_WC_FS_* form. Build a lookup tolerant
		// to every variant we might see.
		$lookup = array();
		foreach ( $headers as $k => $v ) {
			$key            = strtolower( (string) $k );
			$key_underscore = str_replace( '-', '_', $key );
			$key_dash       = str_replace( '_', '-', $key );
			$value          = is_array( $v ) ? (string) reset( $v ) : (string) $v;
			$lookup[ $key ]            = $value;
			$lookup[ $key_underscore ] = $value;
			$lookup[ $key_dash ]       = $value;
		}

		$timestamp      = $lookup['x-wc-fs-timestamp']      ?? $lookup['x_wc_fs_timestamp']      ?? '';
		$correlation_id = $lookup['x-wc-fs-correlation-id'] ?? $lookup['x_wc_fs_correlation_id'] ?? '';
		$signature      = $lookup['x-wc-fs-signature']      ?? $lookup['x_wc_fs_signature']      ?? '';

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
