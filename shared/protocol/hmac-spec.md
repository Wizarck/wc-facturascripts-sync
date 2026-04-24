# HMAC signature spec for wc-facturascripts-sync callbacks

All cross-system HTTP callbacks (FS → WP and WP → FS webhooks) are authenticated via HMAC-SHA256.

## Shared secret

`WC_FS_BRIDGE_HMAC_SECRET` — 32-byte random hex string (64 chars).

Defined in SOPS `secrets.env`, distributed to:
- WP side: `wp-content/mu-plugins/palafito-secrets.php` via `scripts/sops-to-env.sh wp`
- FS side: `<fs-root>/MyFiles/secrets.ini` via `scripts/sops-to-env.sh fs`

Rotated on every major release. Runbook: `eligia-core/runbooks/runbook-rotate-secrets.md`.

## Request signing

Every POST request from FS → WP or WP → FS includes these headers:

```
Authorization: Bearer <token>
X-WC-FS-Timestamp: <unix-epoch-seconds>
X-WC-FS-Correlation-Id: <UUIDv7>
X-WC-FS-Signature: sha256=<HMAC-SHA256(secret, timestamp + "\n" + correlation_id + "\n" + request_body)>
```

## Verification algorithm (server side)

```php
function verify_hmac($headers, $body, $secret) {
    $timestamp = $headers['X-WC-FS-Timestamp'] ?? 0;
    $correlation_id = $headers['X-WC-FS-Correlation-Id'] ?? '';
    $signature = $headers['X-WC-FS-Signature'] ?? '';

    // 1. Reject if timestamp is older than 5 min (replay prevention)
    if (abs(time() - (int)$timestamp) > 300) return false;

    // 2. Compute expected signature
    $payload = $timestamp . "\n" . $correlation_id . "\n" . $body;
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    // 3. Constant-time comparison
    return hash_equals($expected, $signature);
}
```

## Idempotency

`X-WC-FS-Correlation-Id` (UUIDv7) is also the **idempotency key**. The receiving server MUST:

1. Check if it has processed this `correlation_id` before (via a dedupe table with 48h TTL).
2. If yes: return the cached response (HTTP 200 with the original result).
3. If no: process the request, persist the result keyed by `correlation_id`, and return.

This guarantees that network retries are safe.

## Related files (when implemented in Fase 1)

- `wp-plugin/src/Callbacks/HmacVerifier.php` — WP-side verification middleware
- `fs-plugin/Worker/HmacSigner.php` — FS-side request signer
- `shared/protocol/openapi.yaml` — REST contract (TBD)
