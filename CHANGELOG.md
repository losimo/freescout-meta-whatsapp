# Changelog

Every release, oldest at the bottom, a few lines each. This file is the complete list
and is never pruned; the "What's new" sections in the READMEs tell the recent ones
properly, for someone deciding whether to update. Kept current at every release.

## 3.0.0 (2026-11-25)

The first digit moves for one reason and it is not the size of the list below: **the meaning of stored data changed.** Answering a right-of-erasure request now rewrites Meta's message identifiers in this module's own table, and a migration does the same to rows erased by earlier versions. Nothing an administrator configured changes, and the FreeScout floor stays at 1.8.234.

Most of this release comes from 41 tickets opened by [@MarkoD](https://github.com/MarkoD) after reading the code, plus what verifying them turned up. Thirty-nine were confirmed.

### Privacy and the right of erasure

- **Fix**: Meta's message identifiers carry the phone number inside them, base64 encoded. An erasure now replaces each one with a one-way fingerprint of itself, which keeps redeliveries recognisable without keeping the number readable. A migration does the same to rows that earlier versions erased. (The erasure screen itself arrived in 2.2.0; a draft of these notes announced it again here, which it should not have.)
- **Fix**: the search answered "this module holds nothing under that identifier" once the channel or its mailbox had been deleted, because those take the module's message rows with them and leave the contact links in `customer_channel`, which no FreeScout screen reaches either. It reads both now, so the one thing that can remove those rows can still find them.
- **Fix**: an erasure also takes the number off the customer record itself, in the two places this module is the reason it is there: the entry on the customer's phone list, which is the column FreeScout searches a number by, and the core's denormalised channel copy. Only the number asked about, never another number on the same record, and never the record itself. The receipt counts them.
- **Fix**: deleting a channel, or the mailbox that holds it, now tells Meta and cleans up after itself, and a mailbox with a WhatsApp channel can be deleted at all.
- **Fix**: deleting one channel no longer stops the other numbers on the same Meta Messaging account from receiving. The call that removes the webhook subscription is account-wide, not per number, so with two numbers under one WABA, which is what the README recommends, it silently took the sibling down with it. It is sent for the last channel on an account and not before.

### The 24-hour window

- **Fix**: the free reply window is counted from the time **Meta** records for a message rather than from the moment this module's queue worker wrote the row. Every delay between the two used to make the module believe the window was more open than it was.

### Replies that went nowhere

- **Fix**: eight paths that refused to send a reply left nothing in the conversation. Every one of them now leaves a note the agent can see, including a reply that dies because the channel was switched off, which previously left no trace anywhere at all.
- **Fix**: an agent's text is no longer lost with the attachment that was carrying it. When the file cannot be sent, the words go out on their own.
- **Fix**: a file in a subtype WhatsApp does not accept is sent as a document rather than as the image or audio it was going to be refused as, so it arrives; and if Meta refuses it anyway, the note the agent reads carries Meta's own reason instead of nothing at all. An earlier draft of this line said the file was no longer sent, which was never what the code did.
- **Fix**: the refusal note was suppressed for five minutes per conversation, so the second of two failed replies said nothing. One note per reply now, and per reason.

### Inbound messages

- **Fix**: a message whose sender the module could not read took every other message in the same delivery with it. People who write with a WhatsApp username and no phone number available are now filed correctly, which never worked before.
- **New**: a message of a type this module cannot draw (`order`, an `interactive` list reply, anything Meta delivers as `unsupported`) arrives in the conversation as an **Unsupported message** entry instead of being dropped into the log, and counts for the 24-hour window like any other. Announcements *about* a message, such as a deletion or an edit, are deliberately not shown.
- **Fix**: the caption survives when the attachment cannot be downloaded. The file is the module's problem; the sentence next to it is the customer's message.
- **Fix**: a delivery status arriving out of order no longer walks a message backwards, and a failure is final. A late `delivered` on a failed message used to take the breakage off the health panel and count the message as billable.
- **Fix**: a message Meta could not hand over (error `131060`) is no longer discarded. It is somebody writing in for the first time to a number also in use in the WhatsApp Business app, or from an unsupported companion device, and the module was dropping exactly the first message it had set out to stop losing. It now arrives in the conversation with Meta's own advice on where to read it.
- **Fix**: the time the 24-hour window counts from is read as a number and bounded at both ends. A payload value that was not one came out as 1970, so the window read as closed on a message that had just arrived and the module offered a paid template in place of the free reply the agent should have written.
- **New**: somebody opening a chat without writing, which Meta sends as `request_welcome` and typically comes from an ad that clicks to WhatsApp, arrives as a **Chat opened** entry rather than being dropped into the log. It is deliberately not described as a message nobody could show, because nobody sent one, and it counts for the 24-hour window: the event is what lets an agent greet them with a free-form reply.
- **Fix**: the attachment is no longer downloaded from Meta before checking whether the message had already been processed, which it was on every redelivery.
- **Fix**: inbound formatting no longer rewrites URLs and e-mail addresses, and invented file names no longer carry media type parameters.

### Who a message belongs to

- **New**: a WhatsApp message from a number that already belongs to a customer in FreeScout is filed under that customer instead of creating a second one. Matching is on the exact digits, through the core's own lookup, with the ITU `00` prefix as the one extra candidate.
- **New**: when Meta announces that a contact changed their phone number or business-scoped ID, the module follows it: the channel link, this module's own rows and the entry on the customer's phone list move to the new identifier, so an agent's reply does not go to a number its owner has given up, and a number the operator later reassigns does not land in its previous owner's record. Every announcement that concerns a contact also leaves a row in FreeScout's own activity log, under `metawhatsapp_identity`, readable at **Manage » Logs** and without any number in it.
- **Change**: a customer the module creates is named after their phone number, or after their business-scoped ID when Meta sends no number, and Meta's profile name is not used at all. A profile name is often initials or a nickname, so it looks like a real name without being one, while a customer still called `+34...` reads at a glance as one nobody has matched to a record yet. Asked for and argued by [@MarkoD](https://github.com/MarkoD).
- **Fix**: one person can hold two WhatsApp numbers without the first one being overwritten, and a conversation belongs to a number, so a reply cannot be delivered to whichever of their numbers wrote last. What the core supports here is an open question, asked in #61.

### Replies and the core's own e-mail copy

- **New**: an **E-mail copy** switch per channel, off by default. FreeScout 1.8.251 added a checkbox that also sends a chat reply by e-mail, already ticked, so an agent who did not notice it sent the same answer twice. With the switch off the module stops the copy and hides the checkbox; with it on the module steps aside entirely. It has an effect on 1.8.251 and later, and whether the copy arrives is the installation's outbound setup.

### Smaller things

- **Fix**: the note on a reply refused because the channel was switched off no longer asks the agent to send it again. That reply is not discarded, so following the note delivered the same answer to the customer twice.
- **Fix**: the Cc and Bcc fields are hidden, not only the toggle that reveals them.
- **Fix**: three-letter template language codes such as `fil` are accepted, and the template picker's **Back** link points at the conversation instead of at itself.
- **Fix**: automatic notes always carry an author the core's print view can read. Printing a conversation that held one used to fail.
- **Fix**: the module stops firing `conversation.status_changed` when a customer's message reopens a conversation. The core does not fire it for anything a customer does, and firing it meant inventing an agent to attribute it to.
- **Performance**: an index on `meta_whatsapp_messages.thread_id`.

## 2.4.0 (2026-11-16)

- **New**: the mailbox name in the left sidebar now carries the channel's phone number underneath, where a WhatsApp mailbox used to show nothing (#45). The module had been hiding the technical address with CSS, which collapsed that header by 27px and left the official Mailbox Icons module's hover icon partly covered. Reported with before and after screenshots by [@MarkoD](https://github.com/MarkoD).
- **Fix**: automatic notes carried the wrong name on the right. `threads.user_id` is the conversation's assignee mirrored onto the thread, which is what the core writes there, and this module was putting an agent in one case and whichever user had access to the mailbox in the other, so the same name appeared on every note (#43).
- **Fix**: the install section said the module creates two tables and listed two; it creates three (#47).
- The quick start and the settings page now say which webhook fields Meta needs before the account events panel can fill. Without `message_template_status_update` and `template_category_update` enabled at Meta, that switch does nothing and says nothing (#47).
- The October pricing change is written as history rather than as a warning, in its own section beside the 24-hour window. The payment-method note stays at the top, shorter, because an account without one stops having messages delivered and that failure is a quiet one.
- CONTRIBUTING now says what the version numbers mean. The rule existed and lived only in the maintainers' heads.

## 2.3.0 (2026-11-15)

- **Fix**: agents never received a notification for an inbound WhatsApp message, in any version of this module (#46). No email, no bell, no push. FreeScout's listener only buffers the event and the core flushes that buffer in two places, neither of which runs inside a queue worker, which is where this module processes webhooks. Reported with the full diagnosis by [@MarkoD](https://github.com/MarkoD).
- **New**: an administrator can choose which user the module's automatic notes are attributed to, from the settings page (#43). Leave it unset and nothing changes: the note carries the agent it concerns, as in 2.2.0. Pick a user, for example one named System or WhatsApp, and the notes carry that one instead, which avoids FreeScout showing the note to an agent as though they had written it. If that user is later deleted, the notes go back to the agent.
- The expired-window banner no longer says that sending a template to a contact known only by their WhatsApp ID is unsupported by Meta. Meta accepts a WhatsApp ID as a message recipient; this module has not built it yet, and now says so.

## 2.2.0 (2026-11-14)

- **New**: an administrator screen for answering a right-of-erasure request. Search what the module holds about one person by phone number or WhatsApp ID, erase it, and take away a one-time receipt that also states, honestly, what remains outside the module's reach and where each of those things is removed. A queued message waiting to be sent to an erased contact is refused instead of delivered, and says so in the conversation. This closes a gap the module had documented about itself: `meta_whatsapp_messages` was never cleaned when a customer or a conversation was deleted in FreeScout, so an administrator could believe they had answered an erasure request while the phone number and the WhatsApp ID stayed behind.
- **New**: starting a conversation is refused in a WhatsApp mailbox, by the sidebar button, by the new-conversation route and by Clone (#44). The form behind it could never work, since the Cloud API only allows replying inside the window the customer opened or sending an approved template, and an agent who used it got a conversation that looked saved while nothing was delivered and nothing was logged. The refusal explains itself rather than bouncing silently.
- **Fix**: an inbound message is no longer filed into a conversation an agent created by hand (#44). The conversation it reuses is now required to be a chat conversation, so the email-type one left behind by that button cannot swallow the customer's next message. This is also what protects installs that already carry such conversations.
- **New**: the module's automatic notes (a refused reply, a failed delivery) now carry the face and name of the agent they concern, like any other note, instead of appearing without an avatar (#43). Where no author can be resolved, the note still appears, exactly as before.
- The README now says why the module will not link a number through WhatsApp Web's unofficial companion-device protocol, which needs no Cloud API approval and can get the number suspended. CONTRIBUTING now names who maintains each language, Dutch included.

## 2.1.0 (2026-11-06)

- **New**: the window countdown and the recovery banner now update live while a conversation stays open, through FreeScout's own real-time channel — a customer reply or the window closing is reflected automatically, no page reload needed (#41, #42).
- **New**: recognizes Meta's `played` webhook status for voice notes, giving a played voice note the same "opened" treatment a read text message already had.
- **New**: debug log retention accepts `0` for "keep every file forever", and the ceiling is raised from 90 to 365 days (#10).

## 2.0.1 (2026-09-25)

- **Fix**: the window countdown was unreachable to screen readers — an `aria-label` on the same element as the visible text overrode it, so only the tooltip was announced, never the countdown itself.
- **Fix**: the countdown was hidden behind "Show Details" for any conversation with Chat Mode on, which every conversation this module creates has available. It now renders from a different hook that Chat Mode's collapse never wraps; the existing recovery banner is unaffected.

## 2.0.0 (2026-09-25)

Committed publicly on 2026-08-25 (issue #2): every open conversation now shows a real, live countdown to Meta's 24-hour window, not just a banner after it has already closed.

- **New**: a small always-visible indicator counts down the time left in the customer service window, from the customer's last message. It switches to a warning style in the last hour. Purely informational — it always reflects Meta's real rule and is completely independent from the existing recovery threshold, which keeps working exactly as before.
- The indicator carries a small clock icon, inlined from the [Lucide](https://lucide.dev) set, no external request. The first icon this module ships beyond FreeScout's own Glyphicons — a round-number release earns a bit more care in how something looks, not only what it does.
- First major version bump since 1.0.0, tied to this being a notable, publicly-promised feature rather than to anything breaking — the versioning convention reserves round numbers for this.

## 1.15.0 (2026-09-21)

- **Broken credentials now show on the accounts list and the edit screen**, not just in the log. `credentialsAreReadable()` existed since v1.14.0 but nothing called it outside the tests. Found by [@jeroenedig](https://github.com/jeroenedig) (#38).

## 1.14.1 (2026-09-21)

- **Fix**: the message telling an admin to re-enter a channel's token and secret after an `APP_KEY` change was in Catalan, glued onto an otherwise English log line and exception message. Found by [@jeroenedig](https://github.com/jeroenedig) reading the code for the Dutch translation (#38).

## 1.14.0 (2026-09-20)

Out of an audit asking what this module takes for granted about the machine it runs on. The subdirectory bug in 1.13.0 was one of those, found by a user; these are the rest, found before anyone had to report them.

- **The pricing notice now links Meta's own page**, which documents the 1 October change at last: "Effective October 1, 2026, Meta will charge for service messages". The caveat stays where it belongs, on the allowance: the figure of 1,000 is still on no Meta page. Two of Meta's own pages disagree as of today, and the notice says which to read for what.
- **Fix**: one line needed PHP 7.4 while the README promised 7.1, the floor FreeScout itself declares. On 7.1, 7.2 or 7.3 the file that processes webhooks did not parse, so the settings screen worked, the channel saved, Meta's handshake passed, and every inbound message died with a 500 invisible from inside FreeScout.
- **Fix**: the `wamid` column held 100 characters and Meta documents no maximum. FreeScout turns MySQL's strict mode off, so a longer id was cut and stored rather than refused, receipts stopped matching, and two ids sharing a prefix collided on the unique index, where the error reads as "already processed".
- **The settings screen now names what is wrong with the environment**, which the module cannot fix but can see: a queue worker that is not running (the worst of them, because a reply looks sent and simply never leaves), the `sync` driver, missing curl, an `APP_URL` that is not https, a log directory that cannot be written, and a memory limit too small for the attachments WhatsApp accepts. Red means the install cannot work; yellow means it works with a caveat.
- **When FreeScout's `APP_KEY` changes**, on a server move or a `key:generate` taken from a forum, the module says so instead of failing everywhere at once. Credentials are encrypted with that key, and until now the channel list rendered perfectly while every delivery answered 500, which Meta retries for up to seven days, so the backlog grew with nothing saying why. (This line said Meta eventually disabled the webhook; Meta's own documentation says it retries, and says nothing about disabling.)
- **Turning on detailed logging no longer stops the channel** when `storage/logs` is not writable. The log is written before a message is processed, so the diagnostic tool was killing the thing it was meant to diagnose. Turning it off always works.
- **Oversized incoming media is refused with its numbers** instead of taking the whole message with it. The file was held in memory while downloading, and a document larger than the memory limit killed the worker and lost the message, not just the attachment.
- **A new README section for what is not the module**, with what to check and what to send us. Some of it can only be fixed by the server's owner, and that is said plainly.

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
