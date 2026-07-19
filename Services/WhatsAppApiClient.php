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
