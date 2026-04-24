<?php
/**
 * Anti-corruption layer over WP_User / WC customer meta.
 *
 * @package WcFacturascriptsSync\Adapters
 */

namespace WcFacturascriptsSync\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Class WooCommerceCustomerAdapter
 *
 * Reads/writes customer meta with stable prefixes. Every meta key used
 * anywhere in sync code is defined here once.
 */
final class WooCommerceCustomerAdapter {

	public static function get_fs_customer_id( int $user_id ): ?int {
		$id = (int) get_user_meta( $user_id, '_wc_fs_sync_customer_id', true );
		return $id > 0 ? $id : null;
	}

	public static function set_fs_customer_id( int $user_id, int $fs_id ): void {
		update_user_meta( $user_id, '_wc_fs_sync_customer_id', $fs_id );
	}

	public static function get_nif( int $user_id ): ?string {
		$nif = (string) get_user_meta( $user_id, '_b2b_billing_nif', true );
		return '' === $nif ? null : strtoupper( trim( $nif ) );
	}

	public static function is_vies_verified( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, '_b2b_billing_nif_vies_verified', true );
	}

	/**
	 * Build the payload a customer mapper will consume.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_payload( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		return array(
			'nombre'      => (string) get_user_meta( $user_id, 'billing_first_name', true ),
			'apellidos'   => (string) get_user_meta( $user_id, 'billing_last_name', true ),
			'razonsocial' => (string) get_user_meta( $user_id, 'billing_company', true ),
			'cifnif'      => self::get_nif( $user_id ) ?? '',
			'email'       => $user->user_email,
			'telefono1'   => (string) get_user_meta( $user_id, 'billing_phone', true ),
			'direccion'   => (string) get_user_meta( $user_id, 'billing_address_1', true ),
			'ciudad'      => (string) get_user_meta( $user_id, 'billing_city', true ),
			'codpostal'   => (string) get_user_meta( $user_id, 'billing_postcode', true ),
			'provincia'   => (string) get_user_meta( $user_id, 'billing_state', true ),
			'codpais'     => (string) get_user_meta( $user_id, 'billing_country', true ),
			'wp_user_id'  => $user_id,
		);
	}
}
