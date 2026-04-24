<?php
/**
 * @package WcFacturascriptsSync\Tests\Unit
 */

namespace WcFacturascriptsSync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WcFacturascriptsSync\Callbacks\HmacVerifier;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

/**
 * Round-trip tests using the same canonical string the fs-plugin signer
 * produces. Mirrors shared/protocol/hmac-spec.md verbatim.
 */
final class HmacVerifierTest extends TestCase {

	private const SECRET = 'unit-test-secret-64-hex-chars-here';

	public function test_valid_signature_passes(): void {
		$ts             = (string) time();
		$correlation_id = '018f4a3b-00ff-7abc-8def-123456789abc';
		$body           = '{"event_id":"invoice.fs.created","payload":{}}';
		$signature      = 'sha256=' . hash_hmac( 'sha256', $ts . "\n" . $correlation_id . "\n" . $body, self::SECRET );

		$verifier = new HmacVerifier( self::SECRET );
		$result   = $verifier->verify(
			array(
				'X-WC-FS-Timestamp'      => $ts,
				'X-WC-FS-Correlation-Id' => $correlation_id,
				'X-WC-FS-Signature'      => $signature,
			),
			$body
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $correlation_id, $result['correlation_id'] );
	}

	public function test_tampered_body_fails(): void {
		$ts             = (string) time();
		$correlation_id = '018f4a3b-00ff-7abc-8def-123456789abc';
		$body           = '{"event_id":"invoice.fs.created"}';
		$signature      = 'sha256=' . hash_hmac( 'sha256', $ts . "\n" . $correlation_id . "\n" . $body, self::SECRET );

		$verifier = new HmacVerifier( self::SECRET );
		$result   = $verifier->verify(
			array(
				'X-WC-FS-Timestamp'      => $ts,
				'X-WC-FS-Correlation-Id' => $correlation_id,
				'X-WC-FS-Signature'      => $signature,
			),
			$body . 'TAMPERED'
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'signature_mismatch', $result['reason'] );
	}

	public function test_replay_window_rejected(): void {
		$ts             = (string) ( time() - 3600 );
		$correlation_id = '018f4a3b-00ff-7abc-8def-deadbeefcafe';
		$body           = '{}';
		$signature      = 'sha256=' . hash_hmac( 'sha256', $ts . "\n" . $correlation_id . "\n" . $body, self::SECRET );

		$verifier = new HmacVerifier( self::SECRET );
		$result   = $verifier->verify(
			array(
				'X-WC-FS-Timestamp'      => $ts,
				'X-WC-FS-Correlation-Id' => $correlation_id,
				'X-WC-FS-Signature'      => $signature,
			),
			$body
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'replay_window_expired', $result['reason'] );
	}

	public function test_missing_headers_rejected(): void {
		$verifier = new HmacVerifier( self::SECRET );
		$result   = $verifier->verify( array(), '{}' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'missing_headers', $result['reason'] );
	}

	public function test_empty_secret_rejected(): void {
		$ts = (string) time();
		$verifier = new HmacVerifier( '' );
		$result = $verifier->verify(
			array(
				'X-WC-FS-Timestamp'      => $ts,
				'X-WC-FS-Correlation-Id' => 'abc',
				'X-WC-FS-Signature'      => 'sha256=anything',
			),
			'{}'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'secret_not_configured', $result['reason'] );
	}
}
