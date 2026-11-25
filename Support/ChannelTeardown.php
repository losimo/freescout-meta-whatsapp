<?php

namespace Modules\MetaWhatsApp\Support;

use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Taking a channel down, from wherever the order comes.
 *
 * Two doors lead here and they used to do different things: the module's
 * own "delete channel" button, and FreeScout deleting the mailbox the
 * channel is attached to. One routine for both, because the same bug
 * reported four times in this module (#18, #25, #28, #29) came from three
 * copies of one guard.
 *
 * **The message rows go.** The reason they are kept after a right-of-erasure
 * request (a redelivery from Meta must not recreate the person) does not
 * apply once the channel itself is gone: the webhook route answers 403 for
 * an unknown account, so there is nothing left for those rows to protect.
 * Keeping them would leave a table that only grows, holding phone numbers
 * that no screen can reach.
 *
 * **The subscription belongs to the Messaging account, not to the number.**
 * `DELETE /{waba_id}/subscribed_apps` removes this app's webhooks for the
 * whole account: the corpus of 2026-11-22 says "unsubscribe your app from
 * webhooks for a Messaging account" (`solution-providers_manage-webhooks`).
 * Our own README recommends one WABA holding several numbers
 * (`README.md:192`), so sending it while a sibling channel is still there
 * stopped the inbound of a number nobody had touched, in silence. It is
 * sent when this was the last channel on that account, and not before.
 *
 * **Telling Meta is best effort and never blocks.** An expired token, a
 * network error, a Meta outage or credentials that cannot be decrypted
 * must not be the reason an administrator cannot delete a mailbox. What
 * happened is reported back to the caller so the screen can say it, and
 * written to the log when it failed, because the consequence is real:
 * Meta keeps delivering events for a number nobody answers and every
 * delivery is refused, so Meta retries each one with decreasing frequency
 * for up to seven days, to every app subscribed to that account
 * (`webhooks_overview`, same corpus).
 */
class ChannelTeardown
{
    /**
     * @return array{messages:int, unsubscribed:bool|null, error:string|null}
     *         `unsubscribed` is null when Meta was not contacted at all,
     *         which is both the caller asking us not to and a sibling
     *         channel still needing the subscription. Neither is a warning
     *         for the administrator: in the second case the account stays
     *         subscribed because it is still in use.
     */
    public static function forAccount(WhatsAppAccount $account, bool $tellMeta = true): array
    {
        $result = ['messages' => 0, 'unsubscribed' => null, 'error' => null];

        if ($tellMeta && !self::wabaStillAnswered($account)) {
            try {
                $response               = app(WhatsAppApiClient::class, ['account' => $account])
                    ->unsubscribeWebhook();
                $result['unsubscribed'] = !empty($response['ok']);
                if (!$result['unsubscribed']) {
                    $result['error'] = $response['error_message'] ?? null;
                }
            } catch (\Throwable $e) {
                // Throwable and not Exception: a TypeError from the
                // container binding or a fatal inside the client
                // constructor is an Error, and letting one escape would
                // skip the delete below and leave the foreign key
                // blocking the mailbox row again, which is the defect
                // this routine exists to fix. Including
                // CredentialsUnreadable: a channel whose token cannot be
                // decrypted still has to be deletable.
                $result['unsubscribed'] = false;
                $result['error']        = $e->getMessage();
            }

            if (!$result['unsubscribed']) {
                Log::warning('[MetaWhatsApp] Could not remove the webhook subscription while deleting a channel. Meta will keep sending events for this number until the subscription is removed by hand in the Meta App Dashboard.', [
                    'account_id' => $account->id,
                    'reason'     => $result['error'],
                ]);
            }
        }

        // Meta first, rows second, on purpose. A timeout here leaves the
        // channel in place and the administrator can try again; the other
        // order would destroy the credentials this call needs and leave
        // the subscription unreachable for good.
        $result['messages'] = (int) WhatsAppMessage::where('account_id', $account->id)->delete();
        $account->delete();

        return $result;
    }

    /**
     * Whether another channel of this installation still answers for the
     * same Messaging account.
     *
     * Inactive siblings count. An administrator switches a channel off to
     * stop it for a while, and unsubscribing the account would turn that
     * into something they cannot undo from this module: switching it back
     * on would leave a channel that sends and never receives.
     *
     * A channel with no `waba_id` is on nobody's account, and the call it
     * would make has an empty id in the path, so there is nothing to keep
     * and nothing to send.
     */
    protected static function wabaStillAnswered(WhatsAppAccount $account): bool
    {
        $wabaId = (string) $account->waba_id;

        if ($wabaId === '') {
            return false;
        }

        return WhatsAppAccount::where('waba_id', $wabaId)
            ->where('id', '!=', $account->id)
            ->exists();
    }
}
