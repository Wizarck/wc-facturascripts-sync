<?php
/**
 * FacturaScripts REST API client.
 *
 * @package WcFacturascriptsSync\Core
 */

namespace WcFacturascriptsSync\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Class FsClient
 *
 * Thin wrapper on FacturaScripts /api/3 with:
 *   - Bearer token auth.
 *   - Retry middleware (3x exponential backoff on 5xx / network errors).
 *   - Idempotency lookup via numero2 = correlation_id before write.
 *
 * All public methods return decoded associative arrays or throw FsApiException.
 */
final class FsClient {

	private Client $http;
	private string $base_url;

	/**
	 * @param array{base_url:string, token:string} $config
	 */
	public function __construct( array $config ) {
		$this->base_url = rtrim( $config['base_url'] ?? '', '/' );
		$token          = $config['token'] ?? '';

		$stack = HandlerStack::create();
		$stack->push( Middleware::retry( $this->retry_decider(), $this->retry_delay() ) );

		$this->http = new Client(
			array(
				'base_uri'        => $this->base_url . '/',
				'timeout'         => 15,
				'connect_timeout' => 5,
				'handler'         => $stack,
				'headers'         => array(
					'Token'        => $token,
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
			)
		);
	}

	/**
	 * Find a record by numero2 (our correlation_id field) or return null.
	 *
	 * @param string $resource One of 'clientes', 'albaranclientes', 'facturaclientes'.
	 * @param string $correlation_id Propagated UUID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_correlation_id( string $resource, string $correlation_id ): ?array {
		$response = $this->request( 'GET', $resource, array( 'filter[numero2]' => $correlation_id ) );
		$items    = $this->decode_list( $response );
		return $items[0] ?? null;
	}

	/**
	 * POST create.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function create( string $resource, array $payload ): array {
		$response = $this->request( 'POST', $resource, array(), $payload );
		$body     = (string) $response->getBody();
		$decoded  = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new FsApiException( 'Non-JSON response on create ' . $resource . ': ' . substr( $body, 0, 200 ) );
		}
		return $decoded;
	}

	/**
	 * PUT update.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function update( string $resource, string $id, array $payload ): array {
		$response = $this->request( 'PUT', $resource . '/' . rawurlencode( $id ), array(), $payload );
		$decoded  = json_decode( (string) $response->getBody(), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Low-level request. Body is form-encoded as FS expects.
	 *
	 * @param array<string, scalar> $query
	 * @param array<string, mixed>  $body
	 */
	private function request( string $method, string $path, array $query = array(), array $body = array() ): ResponseInterface {
		$options = array();
		if ( ! empty( $query ) ) {
			$options['query'] = $query;
		}
		if ( ! empty( $body ) ) {
			$options['form_params'] = $body;
		}

		try {
			return $this->http->request( $method, $path, $options );
		} catch ( GuzzleException $e ) {
			throw new FsApiException( $method . ' ' . $path . ' failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Extract the list of items from an FS index response.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decode_list( ResponseInterface $response ): array {
		$body    = (string) $response->getBody();
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		// FS returns either a bare list or an envelope with data/items.
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			return $decoded['data'];
		}
		return array_is_list( $decoded ) ? $decoded : array( $decoded );
	}

	/**
	 * Retry decider: true = retry.
	 */
	private function retry_decider(): callable {
		return function ( int $retries, Request $request, ?ResponseInterface $response = null, ?\Throwable $error = null ): bool {
			if ( $retries >= 3 ) {
				return false;
			}
			if ( null !== $error ) {
				return true;
			}
			if ( null !== $response ) {
				$code = $response->getStatusCode();
				return $code >= 500 || 429 === $code;
			}
			return false;
		};
	}

	/**
	 * Exponential backoff with jitter: 1s, 2s, 4s +/- 250ms.
	 */
	private function retry_delay(): callable {
		return function ( int $retries ): int {
			return ( 1000 * ( 2 ** ( $retries - 1 ) ) ) + random_int( -250, 250 );
		};
	}
}
