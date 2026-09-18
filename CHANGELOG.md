# Changelog

Every release, oldest at the bottom, a few lines each. This file is the complete list
and is never pruned; the "What's new" sections in the READMEs tell the recent ones
properly, for someone deciding whether to update. Kept current at every release.

## 1.13.0 (2026-09-18)

- **Fix**: on a FreeScout installed below the domain root, at `/tickets` for example, nothing in this module could be reached. FreeScout registers its own routes inside the subdirectory prefix and a module's routes are loaded outside it, so every route here answered 404: the settings screen, and the webhook too, which means Meta's deliveries never arrived either. Found by [@SenseiFreak](https://github.com/SenseiFreak) (#33).
- **Meta rejecting, pausing or disabling an approved template now shows on the account health panel**, naming the template, the language and what happened to it, instead of first appearing as a send that failed. The row goes away when Meta approves the same template again.
- **An optional record of what Meta reports about the account**, off by default and switched on once for the whole instance, with a screen to read it. Kept 90 days. It holds account-level facts only and never anything that identifies a customer.
- **Template category changes are now recorded**, both the warning sent 24 hours ahead and the change itself: the category is what sets a template's price. Nothing breaks, so this goes to the account event log and not the panel, which is where faults go.
- Every webhook field other than messages now goes through one router rather than being logged and dropped, which is what the remaining families will hang off.

- **A customer who writes without a phone number is named after their WhatsApp username** instead of their raw business-scoped ID, which told the agent nothing about who was on the other side. Meta sends the username in the same payload and it was not being read.
- The counter's note no longer lists where else a number might be sending from. Naming the WhatsApp Business app and "another tool" was both too narrow and too wide: without Meta's Coexistence, which needs a provider, a number on the Cloud API cannot be used from the app at all. It now says anywhere else.
- **Fix**: a business-scoped ID longer than 100 characters was thrown away, and when that message carried no phone number the message went with it: the customer wrote and nothing appeared anywhere. Meta's ceiling is 131 and the column now holds 191.

## 1.12.1 (2026-09-13)

- **Critical fix**: saving a WhatsApp channel from its form returned a 500 and lost the edit. 1.12.0 called `Request::boolean()`, which does not exist on the Laravel this runs on. If you installed 1.12.0, update. No test covered that route, which is how it went out; two now go through the form.

## 1.12.0 (2026-09-12)

- Outbound messages now record Meta's billing **category** (`service` or `template`). Nothing is backfilled: rows written before this stay unknown and are never counted.
- **Monthly service message counter**, per account and off by default, in the account health panel. It counts what left through FreeScout, which is all we can honestly see; if the same number is also used from the WhatsApp Business app the real total at Meta is higher, and the panel says so. Failed sends are not counted, since Meta bills per delivered message.
- **Messages sent in a group are refused and logged** instead of being filed as a private conversation with whoever wrote them, which is what happened until now and would have sent an agent's reply to that person alone. The log carries the group id and never the participant's phone number.
- The account health panel reports **how many groups the number belongs to**, checked during a connection test rather than on every page load. The module never creates groups, so anything above zero was done through the API from elsewhere.
- **Fix**: every webhook event that is not a message was reported in the log as a `phone_number_id` mismatch, which names a serious cross-channel problem, for what is simply an event kind we do not handle. Subscribing a WABA subscribes it to every field Meta offers, so template status and category changes, quality ratings and account alerts all arrive here. They now say what they are, by field name.
- **Dutch brought up to date**, contributed by [@jeroenedig](https://github.com/jeroenedig): the pricing notice on the Dutch README, the 28 strings that had fallen behind since 1.10.0, and the README sections that changed since that page went in (#34, #35).
- The outdated-core notice no longer names which FreeScout versions closed security issues. A list of version numbers inside a translated string goes stale every time FreeScout ships a fix, and it had: it stopped at 1.8.237 while six advisories have been published since 29 August.
- The README carries a notice about Meta's **1 October 2026 pricing change**: service messages and utility templates sent inside the 24-hour window become chargeable, with a monthly allowance of 1,000 service messages per business phone number.

## 1.11.0 (2026-09-10)

- Opening the webhook URL in a browser now answers that the module is installed and the entry point responds, instead of a bare `403` that said nothing either way. The courtesy applies only to a request with no parameters, which is never Meta; it answers `200` on purpose, because shared hosts replace error pages with their own (#33).
- The detailed log is now switched on from the panel, with a window and a retention in days, instead of editing `METAWHATSAPP_DEBUG` in FreeScout's `.env`, which is out of reach on shared hosting.
- New `docs/personal-data.md`: every field the module stores, verified against the code and the database, gaps included.
- The translation invitation now explains that FreeScout's own translation screen reads community modules, so no PHP is needed to contribute a language.

## 1.10.0 (2026-09-03)

- Warns before the access token expires, rather than at the first failed message. Needs the new optional **App ID** field; accounts without it say "not checked" and nothing changes for them.
- Reports an invalid token or missing permissions when the channel is saved, not at the first send.
- Warns when FreeScout is older than the module expects. Nothing is blocked; the module falls back where newer core APIs are missing.
- A reply that cannot be sent now leaves a note on the conversation. Two paths used to end in silence: a contact with no phone number, and an attachment that no longer resolves.
- Fix: five log lines about undelivered or discarded messages were written at `warning` level and vanished on installations that filter warnings.
- **Requires FreeScout 1.8.234 or newer.**

## 1.9.1 (2026-08-31)

- Fix: every mailbox the module created carried two copies of each shared folder, because the account form created folders that core's `MailboxObserver` had already made. Present since the first release (#30).
- Repair migration: duplicates are removed and any conversation in the copy that goes away is moved into the one that stays. Mailboxes the module never created are untouched.
- Dutch translation, contributed by [@jeroenedig](https://github.com/jeroenedig) (#31).

## 1.9.0 (2026-08-25)

- One source of truth for templates: the legacy `template_name`/`template_lang` pair is folded into the first free slot and both columns are dropped.
- The static template buttons and the live picker no longer appear together for agents.
- The template section of the form now explains which configuration wins.
- Fix: nothing was sent while a channel was inactive and only templates said so. All outbound paths now share one check, record the failure and leave a note.
- Fix: the conversation banner offered agents admin-only links, which produced a 403.

## 1.8.1 (2026-08-25)

- Fix: the last log messages still written in Catalan are now in English. The earlier fix translated the `Log::` calls and left the exception messages, which only fire on transient errors and went unnoticed for two months.
- Fix: sending a template on an inactive account left no trace while the conversation still looked delivered.

## 1.8.0 (2026-08-24)

- Delivery failures are logged whichever way Meta reports them. `131047` is documented as synchronous and arrives over the webhook, so the branch that carried the error semantics never ran for text messages. All outbound jobs and the webhook now share one failure handler (#25).
- A second, different error code for the same message is reported instead of quietly replacing the first.
- `error_data.details` is used for the failure text, which is where the actionable wording lives.
- Fix: a token rejection arriving over the webhook no longer deactivates the account. Only a rejection returned to our own call does (#25).
- Fix: dashboard cards for WhatsApp mailboxes no longer keep the grey "inactive" background.
- Docs: multiple numbers must belong to the same business portfolio, or the same person gets a different business-scoped ID per number (#27).

## 1.7.0 (2026-08-23)

- Inbound WhatsApp formatting: `*bold*`, `_italic_`, `~strikethrough~` and ` ```monospace``` ` render as formatted text, following WhatsApp's rules rather than CommonMark (#21).
- Native channel badge and Chat Mode button, in the conversation view and the list. Not retroactive for older conversations (#5).
- Customer messages are marked as read after an agent's reply goes out (#23).
- A delivery failure sets the conversation back to `Active`. Spam and deleted conversations are left alone and the assigned agent never changes (#19).
- Failure notes quote a 60-character excerpt of the undelivered message instead of the raw `wamid` (#19).
- Fix: dashboard mailbox counters were hidden for WhatsApp mailboxes.
- Fix: the Cc/Bcc fields could flash into view before being hidden (#17).

## 1.6.2 (2026-08-19)

- Fix: the "message not delivered" note for error `131047` was logged at `warning` level and could be dropped on installations with a higher `log_level` (#18).
- Removed stray em dashes from user-facing strings (#20).

## 1.6.1 (2026-08-16)

- Guided account reactivation: a successful "Test connection" reactivates an auto-deactivated account, with an audit trail of who and when (#9).

## 1.6.0 (2026-08-16)

- Up to 5 configured templates per account for the expired-window banner, instead of one (#2).
- Dynamic template picker: fetches the account's APPROVED templates live from Meta and fills in `{{n}}` variables (#2).
- Sticker messages supported (#11, #14).
- Contact cards show the shared contact's name and phone numbers (#14).
- Reactions quote a short excerpt of the message they reacted to.
- A message Meta accepts and later reports as failed now leaves a visible note.
- Adding an account subscribes it to Meta's webhooks automatically, with a manual retry button.
- Debug payloads are no longer truncated to "Over 9 levels deep"; module-only debug logging via `METAWHATSAPP_DEBUG` writes to its own daily-rotated file (#10, #11).
- Fix: the "Add new WhatsApp account" page could 500 on PHP 8.1+ (#13).

## 1.5.1 (2026-08-02)

- Official channel IDs `103`/`104` assigned by the FreeScout team, replacing the provisional `100`/`101`. Existing installations migrate automatically (#4).
- **Critical fix**: 1.5.0 shipped a `require_once` placed before the file's `namespace`, which is invalid PHP and stopped the module loading at all. If you installed 1.5.0, update immediately.

## 1.5.0 (2026-07-31)

- Inbound location messages become a Google Maps link; reactions, including removing one, are shown as text (#14).
- Per-account connection test and health snapshot panel.
- A media message with no caption is no longer discarded when the placeholder text is empty.
- New `docs/capability-matrix.md` and `docs/incidents.md`.
- Fix: the module declares its own `vendor/autoload.php` and requires it from the service provider, as FreeScout's module guide expects (#13).

## 1.4.1 (2026-07-28)

- Inbound WhatsApp `button` messages are processed as regular text (#2, #14).
- Debug-level payload logging for inbound webhook processing and outbound API calls (#10, #11).
- `delivered_at` and `read_at` on `meta_whatsapp_messages`, including the fallback when `read` arrives before `delivered` (#16).
- Per-account `conversation_subject_template` with `%YEAR%` and `:phone` placeholders (#15).
- Database migrations for both of the above.

## 1.4.0 (2026-07-21)

- Media support: inbound images, video, audio and documents are downloaded and stored as FreeScout attachments, with an inline thumbnail for images; outbound attachments are uploaded to Meta and sent one WhatsApp message per attachment, with per-type size caps (#1).

## 1.3.0 (2026-07-19)

- Expired window recovery: when Meta's 24-hour window looks expired, agents get a banner offering a single pre-approved template, configured per account. Guarded by a server-side re-check and a 60-second idempotency window, since each template send is billed (#2).
- Fix: `firePostEvents()` fired `conversation.status_changed` with a null user, which crashed with the Workflows module active and lost the inbound message (#7).
- Fix: an unsupported message type was logged at `info` with no sender, so it was invisible with the default log level (#8).
- Fix: the remaining Catalan log messages are in English (#6).

## 1.2.0 (2026-07-15)

- **Default behaviour change**: a conversation closed by an agent is now reopened when the customer writes again, which is FreeScout's standard chat behaviour. The mailbox option "Start a new conversation when chat is closed" restores the old behaviour (#1).
- Native read indicator: Meta's `read` status sets the thread's `opened_at`, so FreeScout shows its own read marker (#3).

## 1.1.0 (2026-07-15)

- BSUID phase 2: customer and conversation resolve by business-scoped user ID, so someone hiding their phone number gets a working conversation instead of a dead end (#1).
- A BSUID arriving alongside a phone number is learned onto the phone customer, in a dedicated "WhatsApp ID" channel.
- A placeholder customer who later reveals a phone belonging to an existing customer is merged into it, annotated and left inert, never deleted.
- BSUIDs longer than the core channel column are stored on the message but never learned as a channel.

## 1.0.1 (2026-07-13)

- BSUID phase 1: `contacts[].user_id` is accepted and stored alongside phone-based identity, in a new indexed `contact_user_id` column (#1).
- A message identified only by a BSUID is persisted and logged instead of rejected.
- A numeric `from` matching `contacts[].user_id` is treated as a BSUID, never as a phone number.

## 1.0.0 (2026-07-04)

- First public release. Plain-text WhatsApp Business channel for FreeScout 1.8.x via the Meta Cloud API, with no third-party gateways. Channel-first setup, no core modifications, fail-closed webhook with mandatory HMAC verification.
