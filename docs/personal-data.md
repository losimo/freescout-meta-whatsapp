# Personal data in this module

Where this module puts personal data, how long it stays, and what is removed when you delete a customer or a conversation, or when you answer a right-of-erasure request yourself.

This is a factual description, not legal advice. **You are the one answering for this data**, not us: the module runs on your server, with your Meta credentials, and no copy of anything reaches the authors. What follows is meant to let you answer a request about a person without having to read the source.

Every claim here was checked against the code and the database on 25 November 2026, for module version 3.0.0.

## What the module stores, and where

| Where | What is in it | Removed by |
|---|---|---|
| `meta_whatsapp_messages` (`contact_phone`, `contact_user_id`, `wamid`) | The customer's phone number and their business-scoped WhatsApp ID, plus Meta's message id, delivery status and timestamps. **No message text.** Meta's message id counts as identity here: it is base64 and the contact's phone number is inside it, which one `base64_decode` brings back out. | The module's own right-of-erasure screen (Manage → WhatsApp → Answer a right-of-erasure request), which clears the first two and replaces the third with a one-way fingerprint of itself. Also deleting the channel, or deleting the mailbox it is attached to: both take that channel's rows with them. Nothing in FreeScout reaches this table on its own, because deleting a customer or a conversation does not delete the person. |
| `meta_whatsapp_account_events` | **Nothing about any customer.** Only what Meta reports about the account itself: which template changed status, in which language, and why. Off by default, kept 90 days. It is listed here precisely because it holds nothing: otherwise the next person reading this inventory would wonder whether somebody forgot to check it. | Not personal data. Ages out automatically. |
| `customer_channel`, channels 103 and 104 | The phone number and the business-scoped ID, as the two channel identifiers this module writes for a customer. This is a core table; the module writes to it. Since issue #59 the phone row can land on a customer record the module did not create, when an existing customer already holds that number. | The same right-of-erasure screen, which deletes these rows by identifier rather than by customer (explained under "Answering a right-of-erasure request" below). Deleting the channel or the mailbox takes the module's own message rows with it and leaves these, which FreeScout's customer form cannot reach either, so the screen is the only thing that removes them. Until v3.0.0 its search read only the message table, so once those were gone it answered that nothing was held and could no longer remove anything. |
| `meta_whatsapp_accounts` | Your own credentials, not the customer's. Access token and app secret are stored encrypted. | You, by deleting the channel account from the module's settings. |
| `customers.first_name` | The phone number as the customer's visible name, but only for a customer the module itself created | The right-of-erasure screen, when the name is the erased identity itself and there is no surname: that is a name this module wrote, and it is the most visible copy of all, since it shows in the customer list, in search results and against every conversation in the list. Any other name is left exactly as it is, because renaming a contact is the administrator's decision and FreeScout's own edit form is where it is made. Until v3.0.1 the screen left every name alone, on the argument that it could not tell a record it had created from one that already existed under an e-mail; that is true in general and it is false in the one case where the name is the identifier, which is the case that mattered. |
| `customers.phones` | The phone number on the customer's own phone list, for a customer the module created, and on a record that had no phone at all when a number change moved one | The right-of-erasure screen, which removes the entries that are the number being erased and leaves every other number on the record alone. A number change also moves the entry rather than leaving the old one behind: `Customer::byPhone()` searches this column, so a number its owner has given up goes on resolving to them, and the next owner of a reassigned number would land in their record. |
| `conversations.subject` | The phone number inside the conversation title ("WhatsApp +34…") for a conversation the module created | FreeScout, by deleting that conversation. |
| `threads.from` | The phone number on every inbound message thread | FreeScout, by deleting that conversation. |
| `customers.channel` / `customers.channel_id` | A denormalised copy of the phone number or the WhatsApp ID, written only when WhatsApp was that customer's *first* channel (`Customer::addChannel()` fills these two columns whenever *either* of them is still empty) | The right-of-erasure screen, when the copy is one of the identifiers being erased: the pair is handed to another of that customer's channel links if one is left, so it keeps meaning what the core means by it, and emptied when there is none. Nothing else reaches it, in this module or in any screen FreeScout ships, so a copy of somebody else's identifier stays. |
| Attachments on disk | Media received from WhatsApp, stored through FreeScout's own attachment handling. | FreeScout, when the conversation or thread they belong to is deleted. |
| `jobs` and `failed_jobs` | Whole webhook payloads while a job is queued (`ProcessInboundWebhook` is constructed with the full payload Meta sent), and the phone number baked into the constructor of every outbound send job | Nothing this module controls directly. A job that finishes leaves no row in `jobs`. One that exhausts its retries lands in `failed_jobs` instead, and sits there until FreeScout's own weekly `queue:flush` runs, which only happens if the server's cron is actually calling `schedule:run`, or until an administrator clears it by hand. |
| `storage/logs/laravel-*.log` | At FreeScout's default log level, three error lines include the sender's phone number: an unsupported message type, and two media download failures. Nothing else, and no message text. **If the installation runs with `APP_LOG_LEVEL=debug`, complete webhook payloads land here too**, with message text and phone numbers, regardless of the module's own detailed-logging switch. | Log rotation on FreeScout's own schedule. |
| `storage/logs/metawhatsapp-debug-*.log` | **Full webhook payloads and outbound requests, which means message text and phone numbers.** Only written when detailed logging is switched on. See below. | Log rotation, if you have set a retention. A retention of `0` means the files are kept forever. See below; only the window is adjustable, never a targeted removal. |

## There is no action in FreeScout that deletes a customer

Looking for a "delete this customer" button is a reasonable thing to try, and FreeScout does not have one. There is no delete route for a customer (`routes/web.php` only has update, conversations, ajax_search, ajax and merge), no button for it anywhere under `resources/views/customers/`, and no Artisan command either. `Customer::deleteCustomer()` exists in the core, but nothing in the core calls it, and this module does not call it either: the right-of-erasure screen deliberately keeps the customer record and clears identity out of the module's own rows instead.

The only place the core itself deletes a customer record is a **merge**: `Customer::mergeWith()` deletes the losing record and its `customer_channel` rows, right after firing a `customer.deleting` event. Its email addresses are **not** deleted: they are reassigned to the surviving customer first (`$customer2->emails()->update(...)`), so a merge is not a way to remove an address. That event means **"these two records were merged,"** not **"forget this person"**: the surviving record is still a living customer, with its own phone number and WhatsApp ID untouched. Hooking that event to erase identity would have wiped a duplicate's history off the surviving customer during a routine cleanup action, which is exactly the kind of mistake this document exists to prevent. `meta_whatsapp_messages` is not touched by a merge either way, by design.

So, until this module added its own screen, the honest answer to "what happens when I try to make FreeScout forget a customer" was that there was nothing to try. That is what the right-of-erasure screen described below is for.

## Deleting a conversation does not reach this module's table

FreeScout deletes the conversation, its threads and its attachments. Permanent deletion goes through `Conversation::deleteConversationsForever()`, which deletes directly on the query builder; the core's own comment in that method says observers do not react to this kind of deleting, so a `conversation.deleting` listener would never run for it, even if this module added one.

**Deleting a conversation does not touch `meta_whatsapp_messages` at all.** That table has no foreign key to conversations and nothing cleans it up, so the phone number and the WhatsApp ID of that exchange stay there after the conversation is gone. The phone inside the conversation's own subject and in `threads.from` disappears with the conversation, since those columns belong to it; the two columns in the module's own table do not.

### Answering a right-of-erasure request

This is why the module ships its own screen for it, for administrators only, reachable from the module's settings page (**Manage → WhatsApp**, "Answer a right-of-erasure request"). Search by phone number or WhatsApp ID; it shows what the module holds about that person, including how many of those rows belong to a conversation that no longer exists, so a conversation already deleted from FreeScout does not hide anything from the search. Erasing clears the phone number and the WhatsApp ID from every one of that person's rows in `meta_whatsapp_messages`, and replaces Meta's message id on each of them with a one-way fingerprint of itself (the row itself stays, so Meta redelivering an old webhook cannot recreate the person, and the fingerprint is enough to recognise that redelivery) and deletes their `customer_channel` rows on this module's two channels. That holds whether the module created the customer record or only added its channel row to one that already existed, because the row is matched by the identifier; the customer record itself is never deleted by this module, and a phone number an administrator imported onto it stays theirs. Those channel rows are removed by matching the identifier, not by resolving "the" customer first: the module's own BSUID learning can deliberately leave the same WhatsApp ID on one customer record and the phone number on another without merging them, so going via a single customer would leave the other record's row behind with the identity still in it. Since v3.0.0 it also takes that number off the customer record itself, in the two places this module is the reason it is there: the entry on `customers.phones`, which is the column `Customer::byPhone()` searches, and the core's denormalised `customers.channel_id` copy. Since v3.0.1 it adds a third, the record's visible name, and only where that name is the erased identity itself with no surname beside it, which is the shape this module writes when it creates a customer and nothing else does. A name somebody has typed is left alone. The search looks at the name as well, which is what makes a record the v3.0.0 screen had already emptied reachable by asking for the same number again: a migration could not have done it, because a name that is a number looks the same whether the person was erased last month or writes every morning. Only the number asked about, never another number on the same record, and never the record itself. You get a one-time receipt: it is shown once, is not stored anywhere, and states plainly what the screen could not reach, so it never claims more than what was actually done.

### If that person writes to you again

Nothing matches any more, so the next message from that number makes a new
customer record and a new conversation. The module does not refuse the message
and it does not hand the person their old history back.

That is the behaviour on purpose, and the reasoning is worth having in writing
because somebody will ask. A person asks for erasure so that you no longer hold
their data and cannot reach them; if it is they who write to you afterwards,
that message is theirs to send and it starts a new exchange with its own
lawful basis, exactly as a message from somebody who had never written before
does. The right itself is about what you hold when the request arrives, not
about data created afterwards, which is how the UK's ICO states the scope of
it. What the right does not do is revive what was erased, and that is the half
this module has to get right: the old exchange stays unreadable, and the two
records are not joined behind anybody's back. If you want them joined, that is
a merge an agent does knowingly, and it is yours to decide rather than ours.

Nothing has to be kept in order to avoid contacting that person again. The
practice of keeping a minimal entry so that a request is not forgotten belongs
to direct marketing, where an objection is absolute and the next campaign would
otherwise reach them; a help desk does not send anything unprompted, and
keeping such a list here would mean holding more about them, not less.

What it leaves you with is two records for one person and nothing linking them.
The older one no longer carries the number as its name, because the erasure
renamed it, so what an agent finds there is a record with no identity on it and
a conversation whose threads still show the number in their `from` line until
somebody deletes that conversation.

**And the honest part.** We are not lawyers, this module carries no
certification of any kind, and none of the above is legal advice for your
installation. What the module can do is erase what it wrote, when you ask it
to, and tell you plainly where it cannot reach: those places are listed under
"Known gaps" below, and the receipt the screen gives you repeats them rather
than claiming the job was complete.

## When Meta says the number changed

Meta announces a change of phone number, or of business-scoped ID, with a system message on the same webhook. The module acts on it, and that moves personal data rather than adding any (issue #60).

The channel row on `customer_channel` is pointed at the new identifier, and the module's own rows in `meta_whatsapp_messages` have the old identifier replaced by the new one. Not only for the channel that received the announcement: `customer_channel` is instance-wide, so after the move every mailbox resolves the new identifier to that person, and rows left behind on a second WhatsApp channel would keep that mailbox replying to the abandoned number. A change of phone number also moves the business-scoped ID, because Meta regenerates it with the number and reports the previous one in the same message. The second part is not bookkeeping: the recipient of a reply is the conversation's last incoming number, so rows left behind would turn an existing conversation into a trap where an agent writes to a number its owner has given up.

Three consequences worth knowing:

- **A right-of-erasure request searched by the old number stops finding those rows**, because the old number is no longer in them. Search by the new one. The person's history has not moved anywhere else, and the module's screen also searches by WhatsApp ID.
- **The old number still exists inside Meta's message ids** of those rows, as it always did, because the id is base64 with the number inside it. Answering an erasure request replaces each id with a one-way fingerprint of itself, which is what closes that.
- **The entry on the customer's own phone list follows the number**, and every other number on the record is left alone. Until v3.0.0 the old number stayed and the new one was only added to a record with no phone at all, under the rule that a webhook does not edit an administrator's data. The rule holds and the conclusion was wrong: this is the one fact Meta is the authority on, because the person told WhatsApp the number is no longer theirs, and leaving it did two kinds of damage. An agent reads the record and calls a number nobody answers, and when the operator reassigns a released number `Customer::byPhone()` still finds this record, so the new owner's first message lands in a stranger's file under a stranger's name.

The note the module leaves on the conversation **names neither number**, on purpose: a note body is text the erasure screen cannot reach, and both numbers already live where it can.

Since v3.0.0 every announcement that concerns somebody who is a contact of yours also leaves a record in **FreeScout's own activity log**, under the log name `metawhatsapp_identity`, readable at Manage » Logs. One row per identifier that actually moved. A change of phone number leaves **two** when the module also holds that person's business-scoped ID, because Meta regenerates the ID along with the number and reports both in the same message, and **one** when it does not, which is the case for a contact known only by their number. An announcement about a number this installation has never seen leaves a log line and no row: there is nobody to name and nothing changed. It exists because the note is not always possible: the contact may have no live conversation, theirs may have been deleted or marked as spam, or the move may be refused before a note would be reached, and until then those cases left nothing an administrator could find. It is also what makes the change auditable, which an installation that verifies number changes by hand needs.

**That row carries no number either**, for the same reason as the note: the module's erasure screen does not reach the activity log, so a number written there would outlive an erasure request made afterwards. What it holds is who it was about (the customer, as the row's causer), when, which of the two identifiers changed, which channel it arrived through, and whether the move was applied or refused.

**The old number does not disappear, and an earlier version of this page said it did.** What the move rewrites is this module's own rows, the contact's channel links, and the entry on the customer's phone list. The number stays in two more places, both of them the conversation's own and neither of them this module's to rewrite: the `from` field of every inbound thread, and the conversation subject when the template includes the number. What removes those is deleting that conversation. The customer's name was on this list until v3.0.1 and is not any more, but only where the name is the identifier itself: see the table at the top.

An earlier version of this page added that all of them go when the customer is deleted, and that sentence was wrong twice over. There is no action in FreeScout that deletes a customer, as the section above this one sets out at length, so the condition never arrives; and the threads and the subject belong to the conversation, so what removes them is deleting that conversation. The phone list was on that list of four as well, and it is no longer: the screen removes the number being erased from it.

That has a consequence worth knowing on the audit screen itself: Manage » Logs prints the causer's name beside each row, and a contact the module created is named after their number, so **the screen shows that number even though the row does not store it**. For an installation that audits number changes by hand, that is the old number, found without anything recording it on purpose.

A change of business-scoped ID announced on its own is applied the same way and leaves no note, because an agent never sees that identifier and cannot act on it. One limit worth knowing: the core stores a channel identifier in 64 characters and Meta's business-scoped IDs can be longer, so a new ID that does not fit is logged and nothing is moved, which leaves the contact's link on an identifier that will not arrive again until an agent merges the records by hand.

## Logs are the place erasure does not reach

Two log files can contain personal data, and they behave differently from everything above.

**The normal log** carries no phone number written as a number, on any line. It carries one on seven lines as part of Meta's message id, which is base64 with the number inside it: when a group message is discarded, when a message arrives with no readable sender, when an announcement about a message is not written to a conversation, when a message of a type the module cannot render is shown as a note, when inbound media has no id, when it cannot be downloaded, and when Meta reports a second, different error code for the same message. Counted again on 25 November 2026, because the previous count said three lines with a number and five with an id, and three of the seven arrived with v3.0.0. Those lines are kept as they are on purpose: the message id is the one thing that lets an administrator chase a specific message with Meta, and the log already is, and will stay, the place erasure does not reach. It rotates on FreeScout's own schedule.

**The detailed log** is off by default and **any administrator can switch it on from the module's settings page**, for a window of 1, 3, 7 or 30 days or until they turn it off again. It used to need `METAWHATSAPP_DEBUG=true` in FreeScout's `.env`, so if you remember needing file access for this, that is no longer the case. When on, it records complete webhook payloads and outbound requests, which includes the text of your customers' messages. It rotates daily, and **how many days it keeps is yours to set**, anywhere up to 365. Setting it to `0` means every file is kept forever.

One detail that is easy to get wrong: the retention is applied **by the log itself as it writes**. Switch detailed logging off and nothing deletes anything any more, so the files from that window stay on disk at whatever size they had reached until somebody removes them from the server by hand.

Read that last part twice if you have set `0`. Until v2.1.0 this log could only hold seven days and this page said it was bounded, which is no longer true and was the wrong thing to leave written here. With no retention limit, the detailed log is a second copy of your conversations, outside FreeScout, that nothing ever removes.

That is the part worth understanding: deleting a customer or a conversation, or answering a right-of-erasure request on the screen above, does not, and realistically cannot, rewrite lines out of a rotating log file. So the practical control is not erasure, it is **how long you keep it**. Turn detailed logging on while you are diagnosing something, turn it off when you are done, and keep the retention as short as the diagnosis needs.

Both files are readable at Manage » Logs by administrators only.

## The job queue can hold a copy too

Laravel's job queue is a third place a copy can sit, separate from the module's own tables and from the log files above. `jobs` holds whatever is waiting to run: `ProcessInboundWebhook` is constructed with the entire webhook payload Meta sent, and every outbound send job is constructed with the customer's phone number. A job that finishes leaves no row behind in `jobs`. One that exhausts its retries lands in `failed_jobs` instead, with its payload intact, and that table is **not** cleaned by the right-of-erasure screen, because rewriting a serialized job payload from the core's queue is not something this module does. FreeScout's own scheduler clears it weekly with `queue:flush`, as long as the server's cron is actually invoking `schedule:run`; where it is not (see "When it is not the module" in the README), those rows stay indefinitely.

## What the module does not do

There is **no telemetry**. Nothing about your installation, your customers or your usage is sent to the authors, and there is nowhere for it to go: the module has no server of its own.

Outbound network traffic goes to Meta's Graph API, with your credentials, and nowhere else. Separately, FreeScout's own module updater fetches a version number from GitHub when an administrator opens the Modules page; that request carries no information about your install beyond the fact that it was made.

## Known gaps

Stated plainly, because a list of what we do well is worth less than a list of what we do not.

1. ~~`meta_whatsapp_messages` is never cleaned.~~ **Closed.** The right-of-erasure screen clears the identity from every row belonging to a person, on request.
   - **A correction, and it was ours.** Until this version the screen kept Meta's message id exactly as it was, and this document and the receipt both said so and explained that it was harmless. It was not: the id is base64 with the contact's phone number inside it, so the number survived in the row the screen had just reported as cleaned. Found by MarkoD. The screen now replaces the id with a one-way fingerprint, and the migration `2026_11_23_000001_hash_wamid_of_already_erased_rows` does the same to rows erased by the earlier version, which cannot be reached by searching because searching is by identity and that is what they no longer have.
2. ~~There is no way to ask the module what it holds about one person.~~ **Closed.** The same screen's search answers that, including rows whose conversation has already been deleted.
3. **Detailed logging is all or nothing, and any administrator can turn it on** from the module's settings page. It used to need `METAWHATSAPP_DEBUG=true` in `.env`; it does not any more. Two consequences worth knowing: switching on a log that captures complete conversations no longer requires file access, and its retention only deletes old files **while the log is running**, so the files from a window you have since switched off stay on disk until somebody removes them by hand.
4. **The job queue can carry a copy of a webhook payload, or a phone number, that this module cannot reach.** `failed_jobs` is cleared by FreeScout's own weekly schedule where cron is actually running, and not otherwise; either way, the erasure screen does not rewrite it.
5. **`customers.channel` and `customers.channel_id` are reached by nothing but a right-of-erasure request for the identifier they hold.** They are a denormalised copy the core writes once, for whichever channel happened to be a customer's first, and no screen FreeScout ships clears them. Since v3.0.0 this module's erasure screen does clear the copy when it is the identifier being erased. What stays open is everything else: a copy of another channel's identifier, or of one of ours on a record nobody ever asks about, has no code path anywhere that removes it.

Gaps 1 and 2 are closed by a screen the module added itself, not by hooking a core event, because there was no event to hook that would have meant what we needed it to mean: see "There is no action in FreeScout that deletes a customer" above. Gaps 3 and 4 stay open, and gap 5 only narrowed, for the same underlying reason in each case: this module can erase what it wrote, and on request, but it was never going to be able to rewrite a rotating log file or a serialized job payload in the core's queue.
