<?php

namespace Modules\MetaWhatsApp\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

class WebhookController extends Controller
{
    /**
     * El que veu una persona que obre aquest URL al navegador.
     *
     * Meta mai arriba aquí sense paràmetres, així que aquesta branca és
     * sempre algú que segueix la guia d'instal·lació. Fins ara li responíem
     * "Forbidden", que és una manera ben estranya de dir que tot va bé.
     */
    const REACHABLE_MESSAGE = "MetaWhatsApp is installed and this endpoint is reachable.\n\n"
        . "This is the webhook URL you paste into Meta. It only answers Meta's verification\n"
        . "handshake, so there is nothing else to see here.\n\n"
        . "If the installation guide sent you here: this is what success looks like.\n";

    /**
     * GET: handshake de subscripció de Meta.
     * Fail-closed: només respon el challenge si el verify_token pertany a un compte actiu.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Cap dels tres paràmetres: no és Meta, és una persona comprovant que
        // el mòdul respon. Es contesta amb 200 i no amb 403 a posta: molts
        // allotjaments compartits substitueixen les pàgines d'error per la
        // seva pròpia, i llavors la comprovació fallaria justament al tipus
        // d'allotjament on més falta fa.
        if (!$mode && !$token && !$challenge) {
            return response(self::REACHABLE_MESSAGE, 200)->header('Content-Type', 'text/plain');
        }

        // Amb algun paràmetre però no tots, o amb un mode que no toca, sí que
        // és una crida mal formada i es rebutja sense donar cap detall.
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
            \Log::warning('[MetaWhatsApp] Webhook rejected: invalid JSON', ['ip' => $request->ip()]);
            return response('Forbidden', 403);
        }

        // Tots els esdeveniments d'un POST pertanyen a la mateixa App (doc. Meta):
        // el primer phone_number_id identifica el compte i, per tant, l'app_secret.
        $phoneNumberId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
        if (!$phoneNumberId) {
            \Log::warning('[MetaWhatsApp] Webhook rejected: missing phone_number_id', ['ip' => $request->ip()]);
            return response('Forbidden', 403);
        }

        $account = WhatsAppAccount::where('phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();
        if (!$account) {
            \Log::warning('[MetaWhatsApp] Webhook rejected: unknown or inactive account', [
                'phone_number_id' => $phoneNumberId,
                'ip'              => $request->ip(),
            ]);
            return response('Forbidden', 403);
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, decrypt($account->app_secret));
        if (!$signature || !hash_equals($expected, $signature)) {
            \Log::warning('[MetaWhatsApp] Webhook rejected: missing or invalid signature', [
                'account_id' => $account->id,
                'ip'         => $request->ip(),
            ]);
            return response('Forbidden', 403);
        }

        \Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook::dispatch($account->id, $payload);

        return response('OK', 200);
    }
}
