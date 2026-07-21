<?php

namespace Modules\MetaWhatsApp\Jobs;

use App\Attachment;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

class SendWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // El backoff entre reintents el gestiona el worker (Laravel 5.8).
    public $tries = 3;

    // Límits documentats de Meta per tipus (bytes).
    const MAX_SIZES = [
        'image'    => 5 * 1024 * 1024,
        'video'    => 16 * 1024 * 1024,
        'audio'    => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
    ];

    /** @var int */
    protected $accountId;

    /** @var int */
    protected $threadId;

    /** @var string */
    protected $toPhone;

    /** @var int */
    protected $attachmentId;

    /** @var string|null */
    protected $caption;

    public function __construct(int $accountId, int $threadId, string $toPhone, int $attachmentId, ?string $caption = null)
    {
        $this->accountId    = $accountId;
        $this->threadId     = $threadId;
        $this->toPhone      = $toPhone;
        $this->attachmentId = $attachmentId;
        $this->caption      = $caption;
    }

    public function handle()
    {
        $account = WhatsAppAccount::find($this->accountId);
        if (!$account || !$account->is_active) {
            Log::warning('[MetaWhatsApp] SendWhatsAppMedia: account missing or inactive', [
                'account_id'    => $this->accountId,
                'thread_id'     => $this->threadId,
                'attachment_id' => $this->attachmentId,
            ]);
            return;
        }

        // Idempotència per adjunt: un mateix thread pot tenir més d'un
        // missatge de sortida (un per adjunt), a diferència del text pla.
        if (WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('attachment_id', $this->attachmentId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->exists()
        ) {
            return;
        }

        $thread = Thread::find($this->threadId);
        if (!$thread
            || $thread->type != Thread::TYPE_MESSAGE
            || $thread->state != Thread::STATE_PUBLISHED
        ) {
            return;
        }

        $attachment = Attachment::find($this->attachmentId);
        if (!$attachment || (int) $attachment->thread_id !== $thread->id) {
            Log::warning('[MetaWhatsApp] SendWhatsAppMedia: attachment missing or not linked to thread', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $this->attachmentId,
            ]);
            return;
        }

        if (WhatsAppMessage::windowExpired($thread->conversation_id, $account)) {
            Log::warning('[MetaWhatsApp] Outside the 24h window: media not sent', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
            ]);
            $this->recordFailure($account->id, $thread, 'window_expired');
            return;
        }

        $category = self::mediaCategory($attachment->mime_type);
        $maxSize  = self::MAX_SIZES[$category];
        if ($attachment->size > $maxSize) {
            Log::error('[MetaWhatsApp] Attachment exceeds WhatsApp size limit for its type', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
                'category'      => $category,
                'size'          => $attachment->size,
                'max_size'      => $maxSize,
            ]);
            $this->recordFailure($account->id, $thread, 'size_exceeded');
            return;
        }

        $client = $this->apiClient($account);

        $upload = $client->uploadMedia($attachment->getLocalFilePath(), $attachment->mime_type, $attachment->file_name);
        if (!$upload['ok']) {
            if ($upload['transient']) {
                throw new \RuntimeException(
                    '[MetaWhatsApp] Error transitori pujant adjunt a Meta: ' . $upload['error_message']
                );
            }
            Log::error('[MetaWhatsApp] Meta rejected media upload', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
                'error_code'    => $upload['error_code'],
                'error'         => $upload['error_message'],
            ]);
            $this->recordFailure($account->id, $thread, (string) $upload['error_code']);
            return;
        }

        $filename = $category === 'document' ? $attachment->file_name : null;
        $result   = $client->sendMedia($this->toPhone, $category, $upload['media_id'], $this->caption, $filename);

        if ($result['ok']) {
            WhatsAppMessage::create([
                'wamid'           => $result['wamid'],
                'account_id'      => $account->id,
                'conversation_id' => $thread->conversation_id,
                'thread_id'       => $thread->id,
                'attachment_id'   => $attachment->id,
                'contact_phone'   => $this->toPhone,
                'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
                'status'          => WhatsAppMessage::STATUS_SENT,
            ]);
            return;
        }

        // Errors transitoris (5xx, xarxa): reintent via $tries, sense fila.
        if ($result['transient']) {
            throw new \RuntimeException(
                '[MetaWhatsApp] Error transitori enviant adjunt a Meta: ' . $result['error_message']
            );
        }

        // Errors semàntics: reintentar no canvia el resultat.
        if ($result['error_code'] === '190') {
            $account->is_active = false;
            $account->save();
            Log::error('[MetaWhatsApp] Access token rejected by Meta (190): account deactivated', [
                'account_id' => $account->id,
            ]);
        } elseif ($result['error_code'] === '131047') {
            Log::warning('[MetaWhatsApp] Outside the 24h window (131047): media not delivered', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
            ]);
        } else {
            Log::error('[MetaWhatsApp] Meta semantic error sending media', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
                'error_code'    => $result['error_code'],
                'error'         => $result['error_message'],
            ]);
        }

        $this->recordFailure($account->id, $thread, (string) $result['error_code']);
    }

    /**
     * Deriva la categoria de Meta (image/video/audio/document) a partir
     * del mime_type d'un adjunt d'agent. Prefix-based, com detectType()
     * del core: no es manté una llista blanca dels sub-tipus exactes que
     * Meta accepta per categoria (fora d'abast en aquest MVP); si Meta
     * rebutja un sub-tipus concret, es tracta com qualsevol altre error
     * semàntic de l'enviament.
     */
    public static function mediaCategory(string $mimeType): string
    {
        $prefix = strtolower(explode('/', $mimeType)[0] ?? '');
        if (in_array($prefix, ['image', 'video', 'audio'], true)) {
            return $prefix;
        }
        return 'document';
    }

    protected function apiClient(WhatsAppAccount $account): WhatsAppApiClient
    {
        return new WhatsAppApiClient($account);
    }

    protected function recordFailure(int $accountId, Thread $thread, string $errorCode)
    {
        WhatsAppMessage::create([
            // Els fallits no tenen wamid de Meta: clau sintètica única per
            // thread+adjunt (el wamid té UNIQUE a la taula).
            'wamid'           => 'failed-thread-' . $thread->id . '-att-' . $this->attachmentId,
            'account_id'      => $accountId,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'attachment_id'   => $this->attachmentId,
            'contact_phone'   => $this->toPhone,
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_FAILED,
            'error_code'      => substr($errorCode, 0, 20),
        ]);
    }

    public function failed(\Throwable $e)
    {
        Log::error('[MetaWhatsApp] SendWhatsAppMedia failed permanently', [
            'account_id'    => $this->accountId,
            'thread_id'     => $this->threadId,
            'attachment_id' => $this->attachmentId,
            'error'         => $e->getMessage(),
        ]);

        $exists = WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('attachment_id', $this->attachmentId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->exists();
        if (!$exists) {
            $thread = Thread::find($this->threadId);
            if ($thread) {
                $this->recordFailure($this->accountId, $thread, 'transient');
            }
        }
    }
}
