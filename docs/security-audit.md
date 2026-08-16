# Security audit — 2026-08-16

Manual review of the module's attack surface, prompted by seeing this same practice documented in another FreeScout WhatsApp module ([kapsowhatsapp](https://github.com/automaze-me/freescout-kapsowhatsapp)) and deciding it was worth adopting. Not a formal pentest — a focused pass over the areas that would actually matter if this module were compromised or misused: webhook authentication, outbound network calls, stored secrets, and output escaping.

## Webhook authentication (`Http/Controllers/WebhookController.php`)

- **Verification handshake (`GET /webhook`)**: fail-closed — the `hub_challenge` is only echoed back if `hub_verify_token` matches an **active** account's stored token. No token, wrong token, or an inactive account all return 403.
- **Event delivery (`POST /webhook`)**: fail-closed at every step —
  1. Raw body is read *before* any JSON parsing (the signature is computed over exact bytes; parsing first would let a byte-identical-looking-but-different payload pass).
  2. Invalid JSON → 403.
  3. Missing `phone_number_id` → 403 (can't resolve which account's secret to check against).
  4. Unknown or inactive account for that `phone_number_id` → 403.
  5. HMAC-SHA256 signature checked with `hash_equals()` (constant-time — no timing side-channel) against `decrypt($account->app_secret)`. Missing or mismatched signature → 403.
- Only after all five checks pass does the payload get dispatched to a queued job. The controller itself never processes untrusted data beyond what's needed to identify the account and check the signature.

## Outbound network calls (`Services/WhatsAppApiClient.php`)

- Every `curl_init()` call sets `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` explicitly (defaults are already secure, but explicit is auditable and survives a distro/php.ini default change).
- **SSRF check**: `downloadMedia($mediaId)` builds its first request against `config('metawhatsapp.api_base')` (fixed, admin-configured, not attacker-influenced) with `$mediaId` interpolated as a path segment — `$mediaId` comes from an HMAC-verified webhook payload, but even if it didn't, it can only affect the *path* on Meta's own host, never the host itself. The second request (`$downloadUrl`) does come entirely from Meta's own API response (a signed CDN URL), not from the webhook payload — the module never fetches a URL supplied directly by a third party.
- All outbound requests carry the account's own decrypted Bearer token; none of the client's methods accept a caller-supplied URL or host.

## Secrets at rest

- `access_token` and `app_secret` are the only two fields never in `$fillable` on `WhatsAppAccount` — they're assigned explicitly via `encrypt()` in the controller, and read back via `decrypt()` only where needed (webhook signature check, API client construction). No plaintext token ever touches a log line, view, or `dd()`/debug output that we could find.
- The new per-module debug log ([[feedback_test_before_publishing_release]]-adjacent work) JSON-encodes the *payload*, not the account credentials — access tokens are never part of `$context` in any `Logger::debugData()` call site.

## Output escaping

- Every inbound message body that becomes a `Thread::$body` goes through `nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))` before saving — including the reaction-excerpt quoting added recently, which decodes entities from a stored (already-escaped) source and re-escapes on save, so there's no double-encoding or injection path through that round-trip.
- The templates picker (`Views/templates_picker.blade.php`) renders Meta's own template body text through Blade's `{{ }}`, which auto-escapes.

## Not found

No SSRF, no broken auth, no unescaped sink, no secret leak. The one *process* gap (not a vulnerability) was the account-creation page's untested `old()` calls crashing on PHP 8.1+, fixed and documented separately in `docs/incidents.md`.

## Follow-up

None required. Worth re-running this same pass after any change that adds a new outbound HTTP call or a new place where user- or Meta-supplied text gets rendered.
