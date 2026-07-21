<?php

namespace Modules\MetaWhatsApp\Services;

use Modules\MetaWhatsApp\Models\WhatsAppAccount;

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
     * Mateix retorn estructurat que sendText().
     */
    public function sendTemplate(string $to, string $name, string $lang): array
    {
        return $this->postMessagePayload([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $name,
                'language' => ['code' => $lang],
            ],
        ]);
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
