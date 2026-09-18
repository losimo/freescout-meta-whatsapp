# Personal data in this module

Where this module puts personal data, how long it stays, and what is removed when you delete a customer or a conversation.

This is a factual description, not legal advice. **You are the one answering for this data**, not us: the module runs on your server, with your Meta credentials, and no copy of anything reaches the authors. What follows is meant to let you answer a request about a person without having to read the source.

Every claim here was checked against the code and the database on 14 September 2026, for module version 1.13.0, the next release, not yet published.

## What the module stores, and where

| Where | What is in it |
|---|---|
| `meta_whatsapp_messages` | The customer's phone number (`contact_phone`) and their business-scoped WhatsApp ID (`contact_user_id`), plus Meta's message id, delivery status and timestamps. **No message text.** |
| `meta_whatsapp_account_events` | **Nothing about any customer.** Only what Meta reports about the account itself: which template changed status, in which language, and why. Off by default, kept 90 days. It is listed here precisely because it holds nothing: otherwise the next person reading this inventory would wonder whether somebody forgot to check it. |
| `customer_channel` | The phone number as the channel identifier, and the business-scoped ID on a second channel row. This is a core table; the module writes to it. |
| `meta_whatsapp_accounts` | Your own credentials, not the customer's. Access token and app secret are stored encrypted. |
| `customers`, `conversations`, `threads` | Name, phone number and the message text itself. These are core tables and the module writes through the same paths any FreeScout channel does. |
| Attachments on disk | Media received from WhatsApp, stored through FreeScout's own attachment handling. |
| `storage/logs/laravel-*.log` | Three error lines include the sender's phone number: an unsupported message type, and two media download failures. Nothing else, and no message text. |
| `storage/logs/metawhatsapp-debug-*.log` | **Full webhook payloads and outbound requests, which means message text and phone numbers.** Only written when detailed logging is switched on. See below. |

## What happens when you delete a customer

FreeScout deletes the customer record, their email addresses, and their `customer_channel` rows, which is where the phone number and the WhatsApp ID live.

**It does not delete their conversations**, and the core says so in its own code. It also does not touch `meta_whatsapp_messages`, so the phone number stays there.

## What happens when you delete a conversation

FreeScout deletes the conversation, its threads and its attachments.

**The module's own rows survive.** `meta_whatsapp_messages` has no foreign key to conversations and nothing cleans it up, so the phone number and the WhatsApp ID of that exchange remain in that table after the conversation is gone.

This is a real gap and it is ours. It is the next thing we intend to close: the core fires a `conversation.deleting` event we can hook into, and the same for `customer.deleting`.

## Logs are the place erasure does not reach

Two log files can contain personal data, and they behave differently from everything above.

**The normal log** carries a phone number on three error lines only. It rotates on FreeScout's own schedule.

**The detailed log** is off unless you set `METAWHATSAPP_DEBUG=true` in FreeScout's `.env`. When on, it records complete webhook payloads and outbound requests, which includes the text of your customers' messages. It rotates daily and keeps **seven files**, so it is bounded, but within that window it is a second copy of conversations that lives outside FreeScout.

That is the part worth understanding: deleting a customer or a conversation does not, and realistically cannot, rewrite lines out of a rotating log file. So the practical control is not erasure, it is **how long the window is**. Turn detailed logging on while you are diagnosing something, and off when you are done.

Both files are readable at Manage » Logs by administrators only.

## What the module does not do

There is **no telemetry**. Nothing about your installation, your customers or your usage is sent to the authors, and there is nowhere for it to go: the module has no server of its own.

Outbound network traffic goes to Meta's Graph API, with your credentials, and nowhere else. Separately, FreeScout's own module updater fetches a version number from GitHub when an administrator opens the Modules page; that request carries no information about your install beyond the fact that it was made.

## Known gaps

Stated plainly, because a list of what we do well is worth less than a list of what we do not.

1. **`meta_whatsapp_messages` is never cleaned.** Not on customer deletion, not on conversation deletion, not by age.
2. **There is no way to ask the module what it holds about one person.** You would have to query the database yourself.
3. **Detailed logging is all or nothing** and can only be switched in `.env`, which puts it out of reach on hosting where you cannot edit files.

The direction for all three is decided: first make the module able to report what it holds about a person, then remove what is ours when the core removes a customer or a conversation, with a record of what was removed. Erasure will never cover the log files, and the answer there is a short window rather than a promise we cannot keep.
