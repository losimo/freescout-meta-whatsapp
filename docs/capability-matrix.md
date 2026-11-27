# Capability matrix

**Last updated:** v3.0.0 (25 November 2026). The rows below were read against the code again at that release, after the whole-branch review found this file three major versions out of date in several places. Where a row's wording changed, the reason is in it.

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
| Order (`type:order`) | ⚠️ | The module cannot draw one, and since v3.0.0 it no longer drops it either: the message arrives in the conversation as an **Unsupported message** entry naming the type Meta gave, reopens a closed conversation and counts for the 24-hour window, like any other message the person sent. Reading the order's contents is still not supported. | | S |
| Group messages (`group_id`) | ❌ | Refused and logged, never filed. A group message carries a `from` that is the participant, not the group, so processing it would create a private conversation with that person and send any reply to them alone. Cloud API groups only exist if the business creates them through the API, which this module never does, so a number in one was put there from elsewhere; the account health panel reports how many, after a connection test. Group management is a chapter of its own, not yet planned. | | L |
| BSUID-only messages (no phone number) | ✅ | Named after the raw ID, and after nothing else: Meta's profile name is not read anywhere since v3.0.0, by the reporter's argument on #59 that a nickname looks like a real name without being one while an identifier reads as "not matched to anybody yet". Resolves or creates a customer for that ID and merges it into the real one once a phone number arrives, but only when it is a pure placeholder, meaning its single channel is that ID and it carries no e-mail and no phone. Shipped v1.0.1 / v1.1.0; the naming changed in v3.0.0. | #1, #59 | – |

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
| Async delivery failure visibility | ✅ | A message Meta initially accepted but later reports as `failed` creates a visible internal note in the conversation, not just a silent DB status change. Shipped v1.6.0. | | – |
| Failures logged on both channels | ✅ | Meta reports errors in the send response, over the statuses webhook, or both, and the documented channel is unreliable (131047 is documented synchronous, arrives asynchronous). One shared handler covers all outbound jobs and the webhook. Shipped v1.8.0. | #25 | – |
| Asynchronous 190 does not deactivate | ✅ | A token rejection attached to a delivery status is weaker evidence than one returned to our own call, so it is logged and persisted but leaves the account active. Synchronous 190 still deactivates. Shipped v1.8.0. | #25 | – |
| Reopen conversation on delivery failure | ✅ | A failed delivery sets the conversation back to `Active` via core's `setStatus()` (which also refreshes folder counters). Spam and deleted conversations are left alone; the assigned agent is never changed. Shipped v1.7.0. | #19 | – |
| Failure note quotes the message | ✅ | The note quotes a 60-char excerpt of the undelivered message instead of the raw `wamid`; falls back to the `wamid` when there's no text (media without a caption). Idempotency moved to a `failure_noted_at` column, so each failed message in a batch keeps its own note. Shipped v1.7.0. | #19 | – |
| Mark customer messages as read | ✅ | After an agent's reply is sent, the customer's most recent inbound message is marked as read via `POST /{phone_number_id}/messages` with `status:read`. Most recent by the time Meta sent it, which since v3.0.1 is the one rule the window clock and the reply's recipient also use: a delivery Meta retries arrives after one that did not fail, so the row written last is not the message sent last. No-op when there's no inbound message; Meta's errors are silently ignored. Shipped v1.7.0. | #23 | – |

## Conversation / account behavior

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Per-mailbox "start new conversation" setting | ✅ | Honors FreeScout core's chat setting. Shipped v1.2.0. | #1, #3 | – |
| Customizable conversation subject | ✅ | Per-account `conversation_subject_template` with `%YEAR%`/`:phone`; falls back to the default when empty. Shipped v1.4.1. | #15 | – |
| "Chat mode" quick-reply button on conversations | ✅ | Conversations now store the channel, which is all core needed to render its own Chat Mode button and WhatsApp tag, in both the conversation view and the list. No custom badge or URL. Not backfilled onto older conversations. Shipped v1.7.0. | #5 | – |
| Official/registered channel ID | ✅ | `103`/`104`, officially assigned by the FreeScout team. Shipped v1.5.1. | #4 | – |
| Auto-deactivation on invalid token (error 190) | ✅ | Account is marked inactive so no further calls are burned. | | – |
| Guided reactivation flow (UI, revalidation, audit trail) | ✅ | Test connection success on an inactive account reactivates it automatically; `reactivated_at`/`reactivated_by` shown on the health snapshot. Shipped v1.6.1. | #9 | – |
| Automatic webhook subscription | ✅ | `POST /{waba_id}/subscribed_apps` called automatically on account creation (best-effort), with a manual "Subscribe webhook" retry button. Shipped v1.6.0. The mirror call that removes it is sent when a channel is deleted, and only when it is the last one on that Messaging account: the endpoint is account-wide, so sending it while a sibling number is still there stopped that number's inbound in silence (fixed v3.0.0). | | – |

## Webhook events other than messages

Subscribing a number to Meta's webhooks subscribes it to every field, so all of these arrive whether or not the module reads them.

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Template status changes (`message_template_status_update`) | ✅ | Meta rejecting, pausing or disabling an approved template shows on the account health panel, naming the template, the language and the state. Cleared when Meta approves the same template again. | | – |
| Template quality changes | 🕒 | Arrives on the same field as the status change, with `previous_quality_score`/`new_quality_score` and no `event`, and is told apart by that. Logged, not acted on. | | S |
| Template category changes (`template_category_update`) | ✅ | Meta recategorises a template on its own and the category decides its price. Nothing breaks, so it is recorded in the account event log (both the 24-hour warning and the change itself) and never put on the panel, which is where faults go. | | – |
| Throughput and messaging limit per number (`phone_number_quality_update`) | ✅ | Recorded in the account event log, on the channel that owns the number the payload names. Despite the field's name it carries no quality score: on Meta's own reference page it reports a business phone number's throughput level, with the events `ONBOARDING` and `THROUGHPUT_UPGRADE`. Shipped v3.1.0. | #66 | – |
| Messaging quality rating (green, yellow, red) | ❌ | Meta sends no webhook for it. It is readable only from the WhatsApp Manager or by asking the Phone Numbers API for `quality_rating`, so nothing arrives for this module to record. The only quality rating with a webhook is the calling one, on `account_update`, and this module makes no calls. | #66 | – |
| Account restrictions, violations, bans, offboarding (`account_update`) | ✅ | Recorded in the account event log, with the restriction types, the violation type and the ban state the payload carries, and with a severity per event rather than per field: the same field carries the ban and the reinstatement. An event this module has not met yet is recorded as a warning. Shipped v3.1.0. | #62, #66 | – |
| Account alerts (`account_alerts`) | ✅ | Messaging limit decisions, Official Business Account status and a deleted business profile photo, by alert type and severity. The free-text description Meta sends with them is deliberately not stored. Shipped v3.1.0. | #66 | – |
| Policy review decision (`account_review_update`) | ✅ | Recorded on every channel of the account, since a rejection means none of them can be used with Meta's APIs. Shipped v3.1.0. | #66 | – |
| Portfolio ceilings (`business_capability_update`) | 🕒 | The maximum conversations per number and per portfolio, rather than a change to what this account can do today. The decisions an administrator acts on arrive on `account_alerts`. | #66 | S |
| Message echoes (`message_echoes`) | 🕒 | Messages sent over the API by another app on the same WABA. Not a notice: it belongs in the conversations, not the panel. | | M |
| Optional record of account events | ✅ | `meta_whatsapp_account_events`, off by default, one switch per instance, kept 90 days, readable from a screen. Account-level facts only, never anything identifying a customer. | | – |

## Admin / diagnostics

| Capability | Status | Note | Issue(s) | Effort |
|---|---|---|---|---|
| Debug-level payload logging (inbound + outbound) | ✅ | Switched on from the module's own settings page, for a window of 1, 3, 7 or 30 days or until it is turned off, with a retention of up to 365 days or `0` for "keep every file forever". `METAWHATSAPP_DEBUG=true` in `.env` still forces it on and overrides the screen; file access is no longer needed either way. Own daily-rotated log file. Shipped v1.4.1, scoped and rotated v1.6.0, moved to the screen and the retention raised in v2.1.0. What it holds, and the trap that retention only deletes while the log is running, are in [personal-data.md](personal-data.md). | #10, #11 | – |
| Connection test / account health snapshot | ✅ | Shipped v1.5 — one merged panel: live "Test connection" call (Graph API `GET /{phone_number_id}`) plus a snapshot of last inbound, last outbound attempt, and last error, read from existing `meta_whatsapp_messages` rows (no new migration). | #9 | – |
| Structured audit log (who changed what) | ⚠️ | Two halves, and only one is built. What Meta reports about the account is recorded in `meta_whatsapp_account_events`, off by default and readable from a screen. Every identity change that concerns a contact leaves a row in FreeScout's own activity log under `metawhatsapp_identity` since v3.0.0, readable at **Manage » Logs**. Who changed a channel's own configuration is still not recorded. | #9, #60 | M |
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
