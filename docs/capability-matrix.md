# Capability matrix

**Last updated:** v1.6.0

A live map of what this module supports against the Meta WhatsApp Cloud API, kept up to date at every release. Update this file whenever a capability's status changes — it's the reference to check before triaging a new issue, not a one-off spec.

Status legend: ✅ Supported · ⚠️ Partial · 🕒 Planned · ❌ Not supported · 🚫 Blocked / out of scope
Effort legend (planned items only): S / M / L

## Inbound message types

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Text | ✅ | | | – |
| Button reply (`button.text`) | ✅ | Processed as a regular text message. Shipped v1.4.1. | #2, #14 | – |
| Image / video / audio / document | ✅ | Downloaded and stored as a FreeScout attachment; images get an inline thumbnail. Shipped v1.4.0. | #1 | – |
| Location | ✅ | Formatted as a Google Maps link (`https://www.google.com/maps?q=lat,lng`), with the shared name/address prefixed when present. Shipped v1.5. | #14 | – |
| Reaction (emoji) | ✅ | Reuses the text-message pipeline; an empty emoji (un-react) is worded distinctly. Shipped v1.5. | #14 | – |
| Sticker | ✅ | Reuses the existing media/image pipeline (WEBP, static or animated). Shipped v1.6.0. | #11, #14 | – |
| Contacts | ✅ | Name + first phone number of each shared contact, one per line. Shipped v1.6.0. | #14 | – |
| Order (`type:order`) | ❌ | Not yet supported. | | S |
| BSUID-only messages (no phone number) | ✅ | Resolves/creates a placeholder customer; merges into the real customer once the phone is revealed. Shipped v1.0.1 / v1.1.0. | #1 | – |

## Outbound message types

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Plain text | ✅ | Gated to the open 24h customer window. | | – |
| Media attachments (image/video/audio/document) | ✅ | One WhatsApp message per attachment; per-type size caps (5 MB image, 16 MB video/audio, 100 MB document). Shipped v1.4.0. | #1 | – |
| Pre-approved HSM template (expired-window recovery) | ✅ | Up to 5 statically-configured templates per account (name, language, button label, recovery text), sent manually via a banner when the 24h window looks expired. Shipped v1.3.0, extended to multi-template v1.6.0. | #2 | – |
| Dynamic template picker (live catalog + variables) | ✅ | Fetches the account's APPROVED templates straight from Meta, with one input per `{{n}}` variable detected. Complements, doesn't replace, the static list above. Shipped v1.6.0. | #2 | – |

## Message status handling

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Sent status | ✅ | | | – |
| Delivered / read timestamps | ✅ | `delivered_at` / `read_at` on `meta_whatsapp_messages`, with a fallback when `read` arrives without a prior `delivered`. Shipped v1.4.1. | #16 | – |
| Native "opened" indicator | ✅ | Meta's `read` status sets the thread's native `opened_at`. Shipped v1.2.0. | #3 | – |
| Failed-send error code | ✅ | `error_code` stored on the message row; full error text is logged, not persisted. | | – |
| Async delivery failure visibility | ✅ | A message Meta initially accepted but later reports as `failed` now creates a visible internal note in the conversation, not just a silent DB status change. Shipped v1.6.0. | | – |

## Conversation / account behavior

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Per-mailbox "start new conversation" setting | ✅ | Honors FreeScout core's chat setting. Shipped v1.2.0. | #1, #3 | – |
| Customizable conversation subject | ✅ | Per-account `conversation_subject_template` with `%YEAR%`/`:phone`; falls back to the default when empty. Shipped v1.4.1. | #15 | – |
| "Chat mode" quick-reply button on conversations | ❌ | Reporter hasn't confirmed it's still needed post-Media-MVP; not yet re-scoped. | #5 | – |
| Official/registered channel ID | ✅ | `103`/`104`, officially assigned by the FreeScout team. Shipped v1.5.1. | #4 | – |
| Auto-deactivation on invalid token (error 190) | ✅ | Account is marked inactive so no further calls are burned. | | – |
| Guided reactivation flow (UI, revalidation, audit trail) | ✅ | Test connection success on an inactive account reactivates it automatically; `reactivated_at`/`reactivated_by` shown on the health snapshot. Shipped v1.6.1. | #9 | – |
| Automatic webhook subscription | ✅ | `POST /{waba_id}/subscribed_apps` called automatically on account creation (best-effort), with a manual "Subscribe webhook" retry button. Shipped v1.6.0. | | – |

## Admin / diagnostics

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Debug-level payload logging (inbound + outbound) | ✅ | Logged at debug level; enable module-wide via `APP_LOG_LEVEL=debug`, or scoped to this module only via `METAWHATSAPP_DEBUG=true` (own daily-rotated log file, 7-day retention). Shipped v1.4.1, fixed a Monolog depth-truncation bug and added the scoped/rotated log v1.6.0. | #10, #11 | – |
| Connection test / account health snapshot | ✅ | Shipped v1.5 — one merged panel: live "Test connection" call (Graph API `GET /{phone_number_id}`) plus a snapshot of last inbound, last outbound attempt, and last error, read from existing `meta_whatsapp_messages` rows (no new migration). | #9 | – |
| Structured audit log (who changed what) | 🕒 | Planned v1.6 (stretch). | #9 | M |
| Configurable/translatable attachment placeholder text | ✅ | Already translatable via `metawhatsapp::metawhatsapp.media_preview_no_caption` (en/ca/es). Fixed v1.5: an empty translation no longer discards the message/attachment. | #11 | – |

## Docs / operational support

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| README (en/ca/es) | ✅ | | | – |
| CHANGELOG.md | ✅ | Started at v1.4.1. | | – |
| Bundled autoloader (no manual `composer` step) | ✅ | Module ships and commits its own `vendor/autoload.php`, required from the Service Provider — a manual copy-and-activate install no longer needs the FreeScout root autoloader refreshed. Shipped v1.5.0, fixed a `require`-before-`namespace` regression in the same release, corrected v1.5.1. | #13 | – |
| Capability matrix (this file) | ✅ | | | – |
| Incident notes | ✅ | Shipped v1.5 — lightweight `docs/incidents.md`. | | – |
| Security audit notes | ✅ | Shipped v1.6.0 — `docs/security-audit.md`, manual pass over webhook auth, outbound calls, secrets, and output escaping. | | – |
