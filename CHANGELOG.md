# Changelog

## 1.4.1

- Added support for inbound WhatsApp button messages by extracting `button.text` and processing it as a regular text message (#2, #14).
- Added debug-level payload logging for inbound webhook processing and outbound WhatsApp API requests/responses (#10, #11). Debug-level only — enable it via your log configuration when you need it.
- Added `delivered_at` and `read_at` timestamps to `meta_whatsapp_messages`, including fallback behavior when a `read` status arrives before a recorded `delivered` status (#16).
- Added per-account `conversation_subject_template` support with `%YEAR%` and `:phone` placeholders, while preserving the existing default subject format when left empty (#15).
- Added the required database migrations for the new message timestamps and conversation subject template fields.
