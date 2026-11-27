<?php

namespace Modules\MetaWhatsApp\Support;

use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * The blue tick on the customer's last message, once the agent's reply has
 * left (issue #23).
 *
 * Shared because it was written twice, one copy in the text job and one in
 * the media job, with the same query in both. That is the shape of the bug
 * that got reported four times (#18, #25, #28, #29): three jobs each holding
 * their own copy of one decision. The copies had already started to drift
 * from the window clock's idea of the last inbound message, which is what
 * this class and WhatsAppMessage::lastInbound() exist to stop.
 *
 * Meta's errors are ignored on purpose, and the client's own docblock says
 * why: a failure here costs the customer a tick and nothing else, and the
 * reply has already gone out by the time this runs.
 */
class ReadReceipt
{
    public static function markLastInbound(WhatsAppApiClient $client, int $conversationId): void
    {
        $last = WhatsAppMessage::lastInbound($conversationId);

        if ($last && $last->wamid) {
            $client->markAsRead($last->wamid);
        }
    }
}
