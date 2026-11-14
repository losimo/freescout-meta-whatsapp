# Personal data in this module

Where this module puts personal data, how long it stays, and what is removed when you delete a customer or a conversation, or when you answer a right-of-erasure request yourself.

This is a factual description, not legal advice. **You are the one answering for this data**, not us: the module runs on your server, with your Meta credentials, and no copy of anything reaches the authors. What follows is meant to let you answer a request about a person without having to read the source.

Every claim here was checked against the code and the database on 7 November 2026, for module version 2.2.0.

## What the module stores, and where

| Where | What is in it | Removed by |
|---|---|---|
| `meta_whatsapp_messages` (`contact_phone`, `contact_user_id`) | The customer's phone number and their business-scoped WhatsApp ID, plus Meta's message id, delivery status and timestamps. **No message text.** | The module's own right-of-erasure screen (Manage → WhatsApp → Answer a right-of-erasure request). Nothing else reaches this table: FreeScout has no action that deletes a customer or a conversation and also touches it. |
| `meta_whatsapp_account_events` | **Nothing about any customer.** Only what Meta reports about the account itself: which template changed status, in which language, and why. Off by default, kept 90 days. It is listed here precisely because it holds nothing: otherwise the next person reading this inventory would wonder whether somebody forgot to check it. | Not personal data. Ages out automatically. |
| `customer_channel`, channels 103 and 104 | The phone number and the business-scoped ID, as the two channel identifiers this module writes for a customer. This is a core table; the module writes to it. | The same right-of-erasure screen, which deletes these rows by identifier rather than by customer (explained under "Answering a right-of-erasure request" below). |
| `meta_whatsapp_accounts` | Your own credentials, not the customer's. Access token and app secret are stored encrypted. | You, by deleting the channel account from the module's settings. |
| `customers.first_name` and its stored phone number | The phone number as the customer's visible name, but only for a customer the module itself created | FreeScout, by editing the customer record. This module never touches it: it cannot tell whether a given customer was created by the module (in which case the phone is the whole name) or already existed under an email with the WhatsApp channel merely added to it, in which case the name is not ours to change. |
| `conversations.subject` | The phone number inside the conversation title ("WhatsApp +34…") for a conversation the module created | FreeScout, by deleting that conversation. |
| `threads.from` | The phone number on every inbound message thread | FreeScout, by deleting that conversation. |
| `customers.channel` / `customers.channel_id` | A denormalised copy of the phone number or the WhatsApp ID, written only when WhatsApp was that customer's *first* channel (`Customer::addChannel()` fills these two columns whenever *either* of them is still empty) | **Nobody.** Not this module, not any screen FreeScout ships. |
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

This is why the module ships its own screen for it, for administrators only, reachable from the module's settings page (**Manage → WhatsApp**, "Answer a right-of-erasure request"). Search by phone number or WhatsApp ID; it shows what the module holds about that person, including how many of those rows belong to a conversation that no longer exists, so a conversation already deleted from FreeScout does not hide anything from the search. Erasing clears the phone number and the WhatsApp ID from every one of that person's rows in `meta_whatsapp_messages` (the row itself stays, so Meta redelivering an old webhook cannot recreate the person) and deletes their `customer_channel` rows on this module's two channels. Those channel rows are removed by matching the identifier, not by resolving "the" customer first: the module's own BSUID learning can deliberately leave the same WhatsApp ID on one customer record and the phone number on another without merging them, so going via a single customer would leave the other record's row behind with the identity still in it. You get a one-time receipt: it is shown once, is not stored anywhere, and states plainly what the screen could not reach, so it never claims more than what was actually done.

## Logs are the place erasure does not reach

Two log files can contain personal data, and they behave differently from everything above.

**The normal log** carries a phone number on three error lines only. It rotates on FreeScout's own schedule.

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
2. ~~There is no way to ask the module what it holds about one person.~~ **Closed.** The same screen's search answers that, including rows whose conversation has already been deleted.
3. **Detailed logging is all or nothing, and any administrator can turn it on** from the module's settings page. It used to need `METAWHATSAPP_DEBUG=true` in `.env`; it does not any more. Two consequences worth knowing: switching on a log that captures complete conversations no longer requires file access, and its retention only deletes old files **while the log is running**, so the files from a window you have since switched off stay on disk until somebody removes them by hand.
4. **The job queue can carry a copy of a webhook payload, or a phone number, that this module cannot reach.** `failed_jobs` is cleared by FreeScout's own weekly schedule where cron is actually running, and not otherwise; either way, the erasure screen does not rewrite it.
5. **`customers.channel` and `customers.channel_id` cannot be removed by anyone.** Not by this module, not by any screen FreeScout ships. It is a denormalised copy the core writes once, for whichever channel happened to be a customer's first, and there is no code path anywhere, in the core or in this module, that clears it.

Gaps 1 and 2 are closed by a screen the module added itself, not by hooking a core event, because there was no event to hook that would have meant what we needed it to mean: see "There is no action in FreeScout that deletes a customer" above. Gaps 3, 4 and 5 stay open, and for the same underlying reason in each case: this module can erase what it wrote, but it was never going to be able to rewrite a rotating log file, a serialized job payload in the core's queue, or a column the core only ever adds to and never clears.
