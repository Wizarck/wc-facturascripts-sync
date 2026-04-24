# wc-facturascripts-sync

> **WooCommerce ↔ FacturaScripts bidirectional sync** — HPOS-compatible, VeriFactu-ready, designed for Spanish B2B.

[![License: LGPL-3.0](https://img.shields.io/badge/License-LGPL%203.0-blue.svg)](https://opensource.org/licenses/LGPL-3.0)

## What this monorepo contains

Two plugins that are two sides of the same protocol, versioned together:

| Directory | Target runtime | Distribution |
|---|---|---|
| [`wp-plugin/`](./wp-plugin/) | WordPress + WooCommerce | WordPress.org + GitHub releases |
| [`fs-plugin/`](./fs-plugin/) | FacturaScripts 2024.x+ | FacturaScripts marketplace |
| [`shared/protocol/`](./shared/protocol/) | Both sides | OpenAPI + HMAC spec + event registry |

A release `v1.x.y` publishes **two ZIPs** (one per marketplace) with matching protocol versions.

## What it syncs

```
WooCommerce (customer side)                FacturaScripts (accounting)
├─ customer create/update       ─────►    Cliente
├─ product (SKU, price, stock)  ─────►    Producto
├─ order status → entregado     ─────►    AlbaranCliente
├─ order status → facturado     ─────►    FacturaCliente + VeriFactu hash chain
└─ payment                       ◄────    ReciboCliente (reconciled from bank norma43)
                                  ◄────    invoice PDF + VeriFactu QR + AEAT ack
```

- **HPOS compatible** (WooCommerce High-Performance Order Storage) — uses `wc_get_order()` abstractions, never direct `wp_posts` access
- **Idempotent** — every cross-system operation carries a UUIDv7 `correlation_id` propagated via HMAC-signed HTTP callbacks
- **VeriFactu-ready** — hooks into the official FacturaScripts VeriFactu plugin for Spanish AEAT compliance (mandatory 1-jan-2027)
- **Company-agnostic** — no tenant-specific business logic; customizable via WordPress filters

## Installation

**Prerequisite**: FacturaScripts 2024.x or later + WooCommerce 9.x with HPOS enabled.

```bash
# WordPress side
wp plugin install https://github.com/Wizarck/wc-facturascripts-sync/releases/latest/download/wc-facturascripts-sync-wp.zip --activate

# FacturaScripts side (upload via marketplace or ZIP)
# See fs-plugin/README.md
```

## Configuration

All configuration via WordPress filters. Zero settings pages for the sync logic itself.

```php
// Example: set FS API endpoint
add_filter( 'wc_fs_sync_base_url', fn() => 'https://erp.example.com/api/3' );

// Example: per-line VAT resolver by product category
add_filter( 'wc_fs_sync_line_vat_rate', function ( $default, $order, $item ) {
    $map = [ 'pan' => 4, 'bolleria' => 10 ];
    $slugs = wp_get_post_terms( $item->get_product()->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
    foreach ( (array) $slugs as $s ) {
        if ( isset( $map[ $s ] ) ) {
            return (float) $map[ $s ];
        }
    }
    return $default;
}, 10, 3 );
```

See [shared/docs/filters.md](./shared/docs/filters.md) for the full list.

## Companion plugins

- [b2b-essentials](https://github.com/Wizarck/b2b-essentials) — B2B roles, moderated registration, hidden pricing, VIES validation (LGPL-3.0, public)
- `wc-ops-suite` — BizOps integration (observability, HITL UI, dashboard) for ELIGIA stack users (private, reach out)

## License

LGPL-3.0-or-later. See [LICENSE](./LICENSE).

## Status

**🚧 Early development**. Initial scaffold 2026-04-24. Target first release: 2026-07-01 (v0.1.0, Fase 1 scope: orders → invoices + VeriFactu voluntary mode).
