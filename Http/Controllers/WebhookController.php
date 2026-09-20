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

        // Tots els esdeveniments d'un POST pertanyen a la mateixa App (doc. Meta).
        // Els de missatges i estats identifiquen el compte pel phone_number_id.
        // Els de nivell WABA (estat i categoria de plantilles, qualitat del
        // número, avisos i restriccions del compte) no porten `metadata` en
        // absolut, i fins ara es rebutjaven aquí amb un 403: la conseqüència
        // era que res del que no fos un missatge arribava mai al mòdul.
        $phoneNumberId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
        $wabaId        = $payload['entry'][0]['id'] ?? null;

        if (is_string($phoneNumberId) && $phoneNumberId !== '') {
            $account = WhatsAppAccount::where('phone_number_id', $phoneNumberId)
                ->where('is_active', true)
                ->first();
        } elseif (is_string($wabaId) && $wabaId !== '') {
            // An account level event belongs to every channel on that WABA.
            // Any one of them is enough to verify the signature here, because
            // channels sharing a WABA share Meta's app and therefore its
            // secret; the fan-out to every channel happens in the router.
            // Ordered by `id` so the same account is picked every time,
            // rather than whichever row the database happens to return.
            $account = WhatsAppAccount::where('waba_id', $wabaId)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        } else {
            \Log::warning('[MetaWhatsApp] Webhook rejected: no phone_number_id and no WABA id', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        if (!$account) {
            \Log::warning('[MetaWhatsApp] Webhook rejected: unknown or inactive account', [
                'phone_number_id' => $phoneNumberId,
                'waba_id'         => $wabaId,
                'ip'              => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        $signature = $request->header('X-Hub-Signature-256', '');

        try {
            $appSecret = $account->readAppSecret();
        } catch (\Modules\MetaWhatsApp\Support\CredentialsUnreadable $e) {
            // Sense això, cada entrega de Meta era un 500 i l'única pista un
            // DecryptException al laravel.log. Meta acaba desactivant un
            // webhook que respon 500 durant dies, i llavors ja no és només
            // que no entrin missatges: és que cal tornar a subscriure el
            // número. Es contesta 403 perquè no es pot verificar res, i es
            // diu en veu alta què ha passat.
            \Log::error('[MetaWhatsApp] Webhook rejected: credentials cannot be decrypted with the current APP_KEY. '
                . \Modules\MetaWhatsApp\Support\CredentialsUnreadable::HINT, [
                'account_id' => $account->id,
            ]);

            return response('Forbidden', 403);
        }

        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
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
