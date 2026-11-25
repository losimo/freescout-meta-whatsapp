# Duplicate conversations in a WhatsApp mailbox

What makes two conversations exist for one customer in the same WhatsApp
mailbox, which of them receives the next inbound message, and which of these
the module can and cannot prevent.

Written in November 2026 out of issue #44 and a review that followed it, and
read against the code again for v3.0.0 on 25 November 2026, where the
selection rule below had changed and this page had not. The core paths were
read in FreeScout 1.8.252.

## Why it matters

When a message arrives, `ProcessInboundWebhook` looks for the conversation to
file it into among the candidates in the same mailbox for the same customer,
of `type = CHAT`, not spam, and on this module's channel or on none, newest by
`created_at` first. That mirrors FreeScout's own chat behaviour and it is the
right default. It also means that if a second chat conversation exists for
that customer, the newer one takes every message from then on, and the older
one stops receiving without anything saying so.

Two filters were added in v3.0.0 and both narrow that set. The channel filter
keeps another chat channel's conversation for the same customer out of the
candidates, which matters because since issue #59 the customer may be a record
that existed before WhatsApp; conversations with no channel stamped are still
candidates, so an upgrade does not split a history. And the candidates are
walked rather than taken newest-first outright: a conversation that already
carries inbound messages from a **different** WhatsApp number is skipped,
because one customer record can hold two numbers and a reply goes to the
conversation's last inbound number, so filing the second number's message into
the first one's conversation would deliver the answer to whoever wrote last.

## What the module prevents

Three doors that create a conversation out of nothing. All three are closed in
v2.2.0:

| Door | How it is closed |
|---|---|
| The sidebar's "New Conversation" button | Hidden by CSS on a WhatsApp mailbox |
| `conversations.create`, the form behind it | Refused by middleware |
| `conversations.clone_conversation` | Refused by the same middleware. This one shows no form: it writes the conversation and its thread inside the GET |

And one that does not create a conversation but used to steal messages: a
conversation an agent created by hand is an email-type one, and the reuse
query requires a chat conversation, so it cannot be picked.

Note why the type filter is not enough on its own. A **clone** of a chat
conversation is itself a chat conversation, so no filter on type could tell it
from the real one. That is why Clone had to be stopped at the route.

## What the module does not prevent

Three core actions move an existing conversation rather than creating one, so
there is nothing to refuse without taking a legitimate feature away. All three
are reachable by an ordinary agent.

- **Merging two customers.** `Customer::mergeWith()` reassigns every
  conversation with a single bulk update on the query builder. No observer or
  per-conversation event fires, so the module cannot even see it happen until
  afterwards. If both customers had a conversation in the same WhatsApp
  mailbox, the merge leaves two. This is the most likely of the three, because
  merging two cards for one person is exactly the cleanup an agent does when a
  customer has written from two numbers. Worth knowing after such a merge: the
  surviving record now holds two numbers, and the rule above keeps each
  number's messages in its own conversation rather than pooling them, which is
  what stops a reply reaching the wrong one of the two.
- **Changing a conversation's customer**, from the customer sidebar.
- **Moving a conversation into the mailbox** from somewhere else.

In all three the result is two genuine conversations, so the damage is milder
than an undeliverable one: the history splits, and the newer conversation keeps
receiving. Nothing becomes unsendable.

## The trap: merging the conversations does not fix it

The obvious remedy makes it worse in a way that is hard to see.

FreeScout's "merge conversations" moves the threads out of the source and then
**soft-deletes** it: the row stays with `state = DELETED`. The reuse query does
not filter on state, and this module deliberately resurrects a deleted
conversation when a message arrives, because a customer replying to a closed or
deleted chat should reopen it rather than vanish.

Put together: merge the newer duplicate into the older one, and the emptied
shell still wins on `created_at`, gets resurrected by the next message, and
starts a fresh history while everything before it sits in the other
conversation.

**The only remedy that removes a conversation from the candidate set is
deleting it permanently.** Merge first if you want to keep the threads, then
delete the empty shell forever rather than leaving it.

One thing this page does not promise: duplicates that already exist in an
installation are not merged by the module when it recognises a number. The
match by number runs only when no conversation of that customer is found at
all, so two records that are already linked to the same person stay as they
are, for an agent to merge.
