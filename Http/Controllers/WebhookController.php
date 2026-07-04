<?php

namespace Modules\MetaWhatsApp\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

class WebhookController extends Controller
{
    /**
     * GET: handshake de subscripció de Meta.
     * Fail-closed: només respon el challenge si el verify_token pertany a un compte actiu.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || !$token || !$challenge) {
            return response('Forbidden', 403);
        }

        $exists = WhatsAppAccount::where('verify_token', $token)
            ->where('is_active', true)
            ->exists();

        if (!$exists) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST: esdeveniments de Meta. Fail-closed: resolució del compte per
     * phone_number_id del payload + verificació HMAC obligatòria abans
     * de despatxar res a la cua.
     */
    public function receive(Request $request)
    {
        // Raw body abans de cap parsing: l'stream només es pot llegir un cop
        // i la signatura es calcula sobre els bytes exactes.
        $rawBody = $request->getContent();

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            \Log::warning('[MetaWhatsApp] Webhook rebutjat: JSON invàlid', ['ip' => $request->ip()]);
            return response('Forbidden', 403);
        }

        // Tots els esdeveniments d'un POST pertanyen a la mateixa App (doc. Meta):
        // el primer phone_number_id identifica el compte i, per tant, l'app_secret.
        $phoneNumberId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
        if (!$phoneNumberId) {
            \Log::warning('[MetaWhatsApp] Webhook rebutjat: sense phone_number_id', ['ip' => $request->ip()]);
            return response('Forbidden', 403);
        }

        $account = WhatsAppAccount::where('phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();
        if (!$account) {
            \Log::warning('[MetaWhatsApp] Webhook rebutjat: compte desconegut o inactiu', [
                'phone_number_id' => $phoneNumberId,
                'ip'              => $request->ip(),
            ]);
            return response('Forbidden', 403);
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, decrypt($account->app_secret));
        if (!$signature || !hash_equals($expected, $signature)) {
            \Log::warning('[MetaWhatsApp] Webhook rebutjat: signatura absent o invàlida', [
                'account_id' => $account->id,
                'ip'         => $request->ip(),
            ]);
            return response('Forbidden', 403);
        }

        \Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook::dispatch($account->id, $payload);

        return response('OK', 200);
    }
}
