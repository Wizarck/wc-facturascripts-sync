# Protocol — wc-facturascripts-sync

Bidirectional contract between the WordPress side (`wp-plugin/`) and the FacturaScripts side (`fs-plugin/`).

This document is the canonical spec. The OpenAPI machine-readable version is at [shared/protocol/openapi.yaml](../shared/protocol/openapi.yaml). Event registry is at [shared/protocol/events.yaml](../shared/protocol/events.yaml). HMAC algorithm reference is at [shared/protocol/hmac-spec.md](../shared/protocol/hmac-spec.md).

## Direction matrix

| Direction | Transport                          | Authentication                | Idempotency basis                  |
|-----------|------------------------------------|-------------------------------|------------------------------------|
| WP → FS   | HTTPS to FS REST `/api/3`          | Bearer token (FS RolAPI)      | `numero2` field on FS records      |
| FS → WP   | HTTPS to WP REST `/wp-json/wc-fs-sync/v1/callback` | HMAC-SHA256 + Bearer token | `(correlation_id, event_id)` dedupe table |

Both sides identify a logical operation by the same `correlation_id` (UUIDv7 generated WP-side at enqueue, propagated end-to-end).

## HMAC signing (FS → WP callbacks)

Algorithm: **HMAC-SHA256** over the canonical string `"<timestamp>\n<correlation_id>\n<body>"`. Shared secret rotated per major release.

### Headers required on every callback POST

| Header                  | Value                                                                                  |
|-------------------------|----------------------------------------------------------------------------------------|
| `Content-Type`          | `application/json`                                                                     |
| `Authorization`         | `Bearer <WC_FS_BRIDGE_HEALTH_TOKEN>` (optional but recommended; defense in depth)      |
| `X-WC-FS-Timestamp`     | Unix epoch seconds at signing time                                                     |
| `X-WC-FS-Correlation-Id`| UUIDv7 of the operation                                                                |
| `X-WC-FS-Signature`     | `sha256=<hex digest>` where digest = HMAC-SHA256(secret, "<ts>\n<cid>\n<body>")        |

### Verification on the WP side

[wp-plugin/src/Callbacks/HmacVerifier.php](../wp-plugin/src/Callbacks/HmacVerifier.php) does:

1. Reject if `|now - timestamp| > 300s` (replay window)
2. Reject if any required header missing
3. Reject if `hash_equals(expected, signature)` is false (constant-time)
4. **Accept both `x-wc-fs-*` and `x_wc_fs_*` header forms** — WP REST normalizes dashes to underscores; verifier tries both.

Failure modes return HTTP 401 with `{error: "<reason>"}` body.

### Headers gotcha (regression catch)

Original implementation only looked up headers with dashes (`x-wc-fs-timestamp`). All real callbacks failed because WP REST's `$request->get_headers()` returns underscored keys. Fix in commit f82e26b (2026-04-25) makes the lookup tolerant. Test `tests/Unit/HmacVerifierTest::test_underscore_header_keys_accepted` reproduces the regression.

## Inbound endpoint (FS → WP)

```
POST /wp-json/wc-fs-sync/v1/callback
Headers: as above
Body:
{
  "event_id": "albaran.fs.created",
  "correlation_id": "018f4a3b-00ff-7abc-8def-...",
  "emitted_at": "2026-04-25T00:00:00Z",
  "payload": {
    "fs_resource": "albaranclientes",
    "fs_id": 12345,
    "numero": "A-2026-0001",
    "wc_order_id": 4242,
    ...
  }
}

Responses:
  200 OK    {"ok": true}                 — processed for the first time
  200 OK    {"ok": true, "dedup": true}  — already seen this (correlation_id, event_id), no-op
  400       {"error": "invalid_body"}    — JSON parse failed or no event_id
  401       {"error": "<reason>"}        — HMAC verification failed
            reasons: missing_headers, replay_window_expired, signature_mismatch, secret_not_configured
```

## Event IDs (subset emitted by this plugin)

Full registry: [wc-ops-suite/events/base-events.yaml](https://github.com/Wizarck/wc-ops-suite/blob/main/events/base-events.yaml). The events this plugin emits and their side effects:

| Event ID                          | Direction | Side effect on WP                                                                  |
|-----------------------------------|-----------|------------------------------------------------------------------------------------|
| `customer.fs.synced`              | WP→FS     | Persist `_wc_fs_sync_customer_id` on user                                          |
| `albaran.fs.created`              | WP→FS     | Persist `_wc_fs_sync_albaran_id` + `_wc_fs_sync_albaran_numero` on order           |
| `albaran.fs.failed`               | WP→FS     | Increment retry count; HITL amber on dead                                          |
| `invoice.fs.created`              | WP→FS or FS→WP | Persist `_wc_fs_sync_invoice_id` + `_wc_fs_sync_invoice_numero` + `_wc_fs_sync_invoice_pdf_url` |
| `invoice.fs.failed`               | WP→FS     | Same as above; HITL red on dead                                                    |
| `verifactu.aeat.ack`              | FS→WP     | Set `_wc_fs_sync_verifactu_ok = 1` + `_wc_fs_sync_verifactu_csv = <CSV>`           |
| `verifactu.aeat.rejected`         | FS→WP     | Set `_wc_fs_sync_verifactu_error = <reason>`; HITL amber 24h timeout              |
| `bank.norma43.reconcile.hit`      | FS→WP     | Look up order by `_wc_fs_sync_invoice_id`, call `$order->payment_complete()`      |
| `sync_queue.dead`                 | WP local  | Job exhausted retries; HITL amber                                                  |

## Idempotency contracts

### WP-side queue

[wp-plugin/src/Core/SyncQueue.php](../wp-plugin/src/Core/SyncQueue.php) — table `wp_wc_fs_sync_queue`:

- `UNIQUE` on `correlation_id`
- `enqueue(entity, action, payload)` returns existing `correlation_id` if a row in `pending|retry|in_progress` already exists for the same `(entity_type, entity_id, action)`
- `reserve(limit)` uses `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8.0+ / MariaDB 10.6+) so concurrent workers don't block
- `reclaim_stale(stale_seconds=900)` watchdog moves rows stuck `in_progress` back to `retry` (called at the top of every drain — self-heals from worker crashes)

### WP-side dedupe (callbacks in)

[wp-plugin/src/Core/Schema.php](../wp-plugin/src/Core/Schema.php) — table `wp_wc_fs_sync_dedupe`:

- `UNIQUE` on `(correlation_id, event_id)`
- 48-hour TTL via daily WP-Cron event `wc_fs_sync_prune_dedupe` calling `Schema::prune_dedupe()`
- Network retries that arrive at `/callback` after the original was processed return `200 {ok: true, dedup: true}` immediately

### FS-side idempotency

The plugin POSTs `numero2 = <correlation_id>` on every create. FS plugin's `Worker/CallbackQueue` checks `find_by_correlation_id(resource, correlation_id)` against `numero2` before insert; returns the existing FS record if found.

## Outbound endpoint (WP → FS)

WP plugin uses [wp-plugin/src/Core/FsClient.php](../wp-plugin/src/Core/FsClient.php) (Guzzle 7) to call FS `/api/3/<resource>`. Retry middleware: 3 attempts with exponential backoff on 5xx and 429.

| Resource                 | WP method                              | FS endpoint                       |
|--------------------------|----------------------------------------|-----------------------------------|
| `clientes`               | `find_by_correlation_id` + `create`/`update` | `GET/POST/PUT /api/3/clientes`    |
| `albaranclientes`        | `create`                               | `POST /api/3/albaranclientes`     |
| `facturaclientes`        | `create` (from albaran)                | `POST /api/3/facturaclientes`     |

## Event IDs declared in this plugin

For CI verification, `shared/protocol/events.yaml` declares every event the plugin emits. The CI guard in `.github/workflows/ci.yml` greps every `do_action('wc_ops_emit', '<id>'...)` call against the YAML — undeclared events fail the build.

## See also

- [shared/protocol/openapi.yaml](../shared/protocol/openapi.yaml) — machine-readable contract
- [shared/protocol/events.yaml](../shared/protocol/events.yaml) — events emitted by this plugin
- [shared/protocol/hmac-spec.md](../shared/protocol/hmac-spec.md) — HMAC algorithm reference
- [wc-ops-suite/events/base-events.yaml](https://github.com/Wizarck/wc-ops-suite/blob/main/events/base-events.yaml) — canonical full event registry
- [wc-ops-suite/docs/events-architecture.md](https://github.com/Wizarck/wc-ops-suite/blob/main/docs/events-architecture.md) — event bus + channels + HITL architecture
