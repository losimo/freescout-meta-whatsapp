<?php

namespace Modules\MetaWhatsApp\Services;

use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Support\Logger as MetaWhatsAppLogger;

class WhatsAppApiClient
{
    const API_VERSION = 'v19.0';

    /** @var WhatsAppAccount */
    protected $account;

    /** @var string */
    protected $accessToken;

    public function __construct(WhatsAppAccount $account)
    {
        $this->account     = $account;
        $this->accessToken = decrypt($account->access_token);
    }

    /**
     * Envia un missatge de text pla.
     *
     * Retorn estructurat:
     *  ok            bool   — 2xx amb wamid
     *  wamid         string|null
     *  http_status   int
     *  error_code    string|null — codi d'error de Meta (p. ex. '131047', '190')
     *  error_message string|null
     *  transient     bool   — true si té sentit reintentar (5xx/xarxa)
     */
    public function sendText(string $to, string $text): array
    {
        return $this->postMessagePayload([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $text,
            ],
        ]);
    }

    /**
     * Envia una plantilla pre-aprovada (fora de la finestra de 24 h).
     * $bodyParams són els valors de les variables {{1}}, {{2}}... del cos,
     * en ordre — buit per a plantilles sense variables (comportament previ,
     * payload idèntic sense la clau 'components').
     * Mateix retorn estructurat que sendText().
     */
    public function sendTemplate(string $to, string $name, string $lang, array $bodyParams = []): array
    {
        $template = [
            'name'     => $name,
            'language' => ['code' => $lang],
        ];

        if (!empty($bodyParams)) {
            $template['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(function ($value) {
                    return ['type' => 'text', 'text' => (string) $value];
                }, $bodyParams),
            ]];
        }

        return $this->postMessagePayload([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $template,
        ]);
    }

    /**
     * Llista les plantilles APPROVED del WABA del compte (Graph API
     * /{waba_id}/message_templates), amb el cos i el nombre de variables
     * {{n}} detectades — base del picker dinàmic (issue #2, punt 2 complet).
     * Descarta PENDING/REJECTED: no s'han d'oferir per enviar.
     *
     * Retorn: ['ok', 'templates' => [['name','language','category','body','variable_count'],...],
     * 'http_status','error_code','error_message','transient'] — mateix
     * esperit estructurat que la resta del client.
     */
    public function listTemplates(): array
    {
        $url = rtrim(config('metawhatsapp.api_base', 'https://graph.facebook.com'), '/')
            . '/' . self::API_VERSION . '/' . $this->account->waba_id
            . '/message_templates?fields=name,language,status,category,components&limit=100';

        $result = $this->curlGet($url, ['Authorization: Bearer ' . $this->accessToken]);
        if (!$result['ok']) {
            return $result + ['templates' => []];
        }

        $data      = json_decode($result['body'], true) ?: [];
        $templates = [];
        foreach ($data['data'] ?? [] as $row) {
            if (($row['status'] ?? '') !== 'APPROVED') {
                continue;
            }

            $body = '';
            foreach ($row['components'] ?? [] as $component) {
                if (($component['type'] ?? '') === 'BODY') {
                    $body = $component['text'] ?? '';
                    break;
                }
            }
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);

            $templates[] = [
                'name'           => $row['name'] ?? '',
                'language'       => $row['language'] ?? '',
                'category'       => $row['category'] ?? '',
                'body'           => $body,
                'variable_count' => count(array_unique($matches[1] ?? [])),
            ];
        }

        return [
            'ok' => true, 'templates' => $templates,
            'http_status' => $result['http_status'], 'error_code' => null,
            'error_message' => null, 'transient' => false,
        ];
    }

    /**
     * Descarrega un adjunt multimèdia inbound: GET /{media-id} per obtenir
     * la URL temporal + mime_type, després GET d'aquesta URL amb el mateix
     * Bearer token (la URL caduca en minuts, cal encadenar sense demora).
     * Mateix esperit de retorn estructurat que sendText().
     */
    public function downloadMedia(string $mediaId): array
    {
        $metaUrl = rtrim(config('metawhatsapp.api_base', 'https://graph.facebook.com'), '/')
            . '/' . self::API_VERSION
            . '/' . $mediaId;

        $metaResponse = $this->curlGet($metaUrl, ['Authorization: Bearer ' . $this->accessToken]);
        if (!$metaResponse['ok']) {
            return [
                'ok' => false, 'bytes' => null, 'mime_type' => null,
                'http_status'   => $metaResponse['http_status'],
                'error_code'    => $metaResponse['error_code'],
                'error_message' => $metaResponse['error_message'],
                'transient'     => $metaResponse['transient'],
            ];
        }

        $meta        = json_decode($metaResponse['body'], true) ?: [];
        $downloadUrl = $meta['url'] ?? null;
        $mimeType    = $meta['mime_type'] ?? null;
        if (!$downloadUrl) {
            return [
                'ok' => false, 'bytes' => null, 'mime_type' => null,
                'http_status'   => $metaResponse['http_status'],
                'error_code'    => null,
                'error_message' => 'Meta media metadata response without url',
                'transient'     => false,
            ];
        }

        $fileResponse = $this->curlGet($downloadUrl, ['Authorization: Bearer ' . $this->accessToken]);
        if (!$fileResponse['ok']) {
            return [
                'ok' => false, 'bytes' => null, 'mime_type' => $mimeType,
                'http_status'   => $fileResponse['http_status'],
                'error_code'    => $fileResponse['error_code'],
                'error_message' => $fileResponse['error_message'],
                'transient'     => $fileResponse['transient'],
            ];
        }

        return [
            'ok' => true, 'bytes' => $fileResponse['body'], 'mime_type' => $mimeType,
            'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
        ];
    }

    /**
     * Puja un fitxer a Meta per obtenir un media_id reutilitzable a
     * sendMedia(). Camí recomanat per Meta per a producció (vs. link
     * directe): el fitxer viu al CDN de Meta abans d'intentar l'enviament.
     */
    public function uploadMedia(string $filePath, string $mimeType, string $fileName): array
    {
        $url = rtrim(config('metawhatsapp.api_base', 'https://graph.facebook.com'), '/')
            . '/' . self::API_VERSION
            . '/' . $this->account->phone_number_id
            . '/media';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'messaging_product' => 'whatsapp',
                'file'              => new \CURLFile($filePath, $mimeType, $fileName),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'media_id' => null, 'http_status' => 0, 'error_code' => null, 'error_message' => 'cURL: ' . $curlError, 'transient' => true];
        }

        $data = json_decode($response, true) ?: [];
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
            return ['ok' => true, 'media_id' => $data['id'], 'http_status' => $httpCode, 'error_code' => null, 'error_message' => null, 'transient' => false];
        }

        return [
            'ok' => false, 'media_id' => null, 'http_status' => $httpCode,
            'error_code'    => isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            'error_message' => $data['error']['message'] ?? ('HTTP ' . $httpCode),
            'transient'     => $httpCode >= 500,
        ];
    }

    /**
     * Envia un missatge multimèdia referenciant un media_id ja pujat.
     * $caption s'ignora si el tipus és 'audio' (Meta no ho suporta).
     * $filename només s'aplica a 'document'.
     *
     * $caption/$filename sense tipar explícitament com a `?string`: el
     * mockery/mockery 1.1.0 fixat al projecte genera codi invàlid
     * (`?\?string`) en regenerar la signatura d'un mètode amb tipus
     * nullable escalar en fer un mock complet de la classe (vegeu
     * ProcessInboundWebhookTest i SendWhatsAppMediaTest, que en fan
     * `Mockery::mock(WhatsAppApiClient::class)`). Mantenir sense tipar.
     */
    public function sendMedia(string $to, string $type, string $mediaId, $caption = null, $filename = null): array
    {
        $mediaObject = ['id' => $mediaId];
        if ($caption !== null && $caption !== '' && $type !== 'audio') {
            $mediaObject['caption'] = $caption;
        }
        if ($filename !== null && $type === 'document') {
            $mediaObject['filename'] = $filename;
        }

        return $this->postMessagePayload([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $mediaObject,
        ]);
    }

    /**
     * Crida lleugera a Graph API (GET del phone number, sense enviar cap
     * missatge) per validar que el phone_number_id i el token encara són
     * vàlids. Mateix format ok/http_status/error_code/error_message/transient
     * que downloadMedia(), amb 'verified_name' afegit si la crida té èxit.
     */
    public function testConnection(): array
    {
        $url = rtrim(config('metawhatsapp.api_base', 'https://graph.facebook.com'), '/')
            . '/' . self::API_VERSION . '/' . $this->account->phone_number_id
            . '?fields=verified_name,display_phone_number';

        $result = $this->curlGet($url, [
            'Authorization: Bearer ' . $this->accessToken,
        ]);

        if ($result['ok']) {
            $data = json_decode($result['body'], true) ?: [];
            $result['verified_name'] = $data['verified_name'] ?? null;
        }

        return $result;
    }

    /**
     * Subscriu l'app de Meta configurada als webhooks d'aquest WABA
     * (POST /{waba_id}/subscribed_apps) — pas d'instal·lació que sovint es
     * passa per alt manualment (issue de roadmap: "registre automàtic de
     * webhook", inspirat en kapsowhatsapp). Nota: això NOMÉS subscriu el
     * WABA a l'app; la URL/verify-token del webhook en si es configura una
     * vegada al Meta App Dashboard, pas que no es pot automatitzar amb el
     * token d'aquest compte (calen permisos d'app, no de WABA).
     */
    public function subscribeWebhook(): array
    {
        $url = rtrim(config('metawhatsapp.api_base', 'https://graph.facebook.com'), '/')
            . '/' . self::API_VERSION . '/' . $this->account->waba_id . '/subscribed_apps';

        return $this->curlPostSimple($url, ['Authorization: Bearer ' . $this->accessToken]);
    }

    /**
     * POST genèric sense body JSON (Meta accepta els subscribed_apps sense
     * payload), amb el mateix format de retorn estructurat que curlGet().
     */
    protected function curlPostSimple(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'body' => null, 'http_status' => 0, 'error_code' => null, 'error_message' => 'cURL: ' . $curlError, 'transient' => true];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'body' => $response, 'http_status' => $httpCode, 'error_code' => null, 'error_message' => null, 'transient' => false];
        }

        $data = json_decode($response, true) ?: [];
        return [
            'ok' => false, 'body' => null, 'http_status' => $httpCode,
            'error_code'    => isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            'error_message' => $data['error']['message'] ?? ('HTTP ' . $httpCode),
            'transient'     => $httpCode >= 500,
        ];
    }

    /**
     * GET genèric amb Bearer, reutilitzat per downloadMedia(). Mateix patró
     * de retorn estructurat que postMessagePayload(), amb 'body' en lloc
     * dels camps específics de missatge.
     */
    protected function curlGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'body' => null, 'http_status' => 0, 'error_code' => null, 'error_message' => 'cURL: ' . $curlError, 'transient' => true];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'body' => $response, 'http_status' => $httpCode, 'error_code' => null, 'error_message' => null, 'transient' => false];
        }

        $data = json_decode($response, true) ?: [];
        return [
            'ok' => false, 'body' => null, 'http_status' => $httpCode,
            'error_code'    => isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            'error_message' => $data['error']['message'] ?? ('HTTP ' . $httpCode),
            'transient'     => $httpCode >= 500,
        ];
    }

    /**
     * Transport comú: construeix la URL, fa la crida cURL a Meta i normalitza
     * la resposta al retorn estructurat descrit a sendText(). Extret de
     * sendText() perquè sendTemplate() (i qualsevol futur tipus de missatge)
     * el reutilitzin sense duplicar la part de xarxa.
     */
    protected function postMessagePayload(array $payload): array
    {
        $url = rtrim(config('metawhatsapp.api_base', 'https://graph.facebook.com'), '/')
            . '/' . self::API_VERSION
            . '/' . $this->account->phone_number_id
            . '/messages';

        $body = json_encode($payload);

        MetaWhatsAppLogger::debugData('[MetaWhatsApp] Outbound message payload', [
            'account_id' => $this->account->id,
            'payload'    => $payload,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 15,
            // Explícits per auditabilitat (són els defaults de cURL).
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok'            => false,
                'wamid'         => null,
                'http_status'   => 0,
                'error_code'    => null,
                'error_message' => 'cURL: ' . $curlError,
                'transient'     => true,
            ];
        }

        $data = json_decode($response, true) ?: [];

        MetaWhatsAppLogger::debugData('[MetaWhatsApp] Outbound message response', [
            'account_id'  => $this->account->id,
            'http_status' => $httpCode,
            'response'    => $data,
        ]);

        if ($httpCode >= 200 && $httpCode < 300) {
            $wamid = $data['messages'][0]['id'] ?? null;
            return [
                'ok'            => (bool) $wamid,
                'wamid'         => $wamid,
                'http_status'   => $httpCode,
                'error_code'    => null,
                'error_message' => $wamid ? null : 'Resposta 2xx sense wamid',
                'transient'     => false,
            ];
        }

        return [
            'ok'            => false,
            'wamid'         => null,
            'http_status'   => $httpCode,
            'error_code'    => isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            'error_message' => $data['error']['message'] ?? ('HTTP ' . $httpCode),
            'transient'     => $httpCode >= 500,
        ];
    }
}
