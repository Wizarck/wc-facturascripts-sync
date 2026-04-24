<?php
/**
 * Anti-corruption layer over WC_Order.
 *
 * @package WcFacturascriptsSync\Adapters
 */

namespace WcFacturascriptsSync\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Class WooCommerceOrderAdapter
 *
 * Every access to order meta goes through here. When WC/HPOS change method
 * signatures upstream, only this file needs to adapt — the rest of the
 * sync code stays stable. CI grep guard enforces this (no direct
 * get_meta / update_meta_data outside Adapters/).
 */
final class WooCommerceOrderAdapter {

	public static function get_billing_nif( \WC_Order $order ): ?string {
		$nif = (string) $order->get_meta( '_b2b_billing_nif' );
		if ( '' !== $nif ) {
			return strtoupper( trim( $nif ) );
		}
		// Legacy fallback for orders created before b2b-essentials migration.
		$legacy = (string) $order->get_meta( 'billing_nif' );
		return '' === $legacy ? null : strtoupper( trim( $legacy ) );
	}

	public static function get_fs_customer_id( \WC_Order $order ): ?int {
		$id = (int) $order->get_meta( '_wc_fs_sync_customer_id' );
		return $id > 0 ? $id : null;
	}

	public static function set_fs_albaran( \WC_Order $order, string $numero ): void {
		$order->update_meta_data( '_wc_fs_sync_albaran_numero', $numero );
		$order->save();
	}

	public static function set_fs_invoice( \WC_Order $order, string $numero, ?string $pdf_url = null ): void {
		$order->update_meta_data( '_wc_fs_sync_invoice_numero', $numero );
		if ( $pdf_url ) {
			$order->update_meta_data( '_wc_fs_sync_invoice_pdf_url', $pdf_url );
		}
		// Legacy mirror so existing PDF templates / Kadence still render correctly.
		if ( ! $order->get_meta( '_wcpdf_invoice_number' ) ) {
			$order->update_meta_data( '_wcpdf_invoice_number', $numero );
		}
		$order->save();
	}

	/**
	 * Build the payload that the Albaran mapper will consume.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_albaran_payload( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$lines[] = array(
				'referencia'  => $product ? $product->get_sku() : '',
				'descripcion' => $item->get_name(),
				'cantidad'    => (float) $item->get_quantity(),
				'pvpunitario' => $order->get_item_total( $item, false, false ),
				'iva'         => self::resolve_vat_rate( $order, $item ),
			);
		}

		return array(
			'fecha'           => $order->get_date_created()?->format( 'Y-m-d' ),
			'codcliente'      => (string) self::get_fs_customer_id( $order ),
			'observaciones'   => $order->get_customer_note(),
			'lineas'          => $lines,
			'total'           => (float) $order->get_total(),
			'wc_order_id'     => $order->get_id(),
		);
	}

	/**
	 * Resolve the VAT rate for a given line item. Hookable so tenant-config
	 * can plug a custom resolver (e.g. per-customer overrides).
	 */
	public static function resolve_vat_rate( \WC_Order $order, \WC_Order_Item_Product $item ): float {
		$product = $item->get_product();
		$default = 21.0;

		if ( $product ) {
			$meta = $product->get_meta( '_wc_fs_sync_vat_rate' );
			if ( '' !== (string) $meta ) {
				$default = (float) $meta;
			}
		}

		/**
		 * @param float                    $default
		 * @param \WC_Order                $order
		 * @param \WC_Order_Item_Product   $item
		 */
		return (float) apply_filters( 'wc_fs_sync_line_vat_rate', $default, $order, $item );
	}
}
