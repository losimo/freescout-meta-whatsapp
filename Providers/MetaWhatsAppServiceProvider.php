<?php

namespace Modules\MetaWhatsApp\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

class MetaWhatsAppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerViews();
        $this->registerTranslations();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', 'metawhatsapp');
    }

    protected function hooks()
    {
        // Entrada al menú Gestionar (només admins).
        \Eventy::addAction('menu.manage.append', function () {
            if (auth()->user() && auth()->user()->isAdmin()) {
                echo view('metawhatsapp::menu')->render();
            }
        });

        // Registrar WhatsApp com a canal disponible.
        \Eventy::addFilter('channels.list', function ($channels) {
            $channels[WhatsAppAccount::CHANNEL] = WhatsAppAccount::CHANNEL_NAME;
            $channels[WhatsAppAccount::CHANNEL_BSUID] = WhatsAppAccount::CHANNEL_BSUID_NAME;
            return $channels;
        }, 20, 1);

        // Nom visible del canal enter a customer_channel (mecanisme del core:
        // CustomerChannel::getChannelName()).
        \Eventy::addFilter('channel.name', function ($name, $channel = null) {
            if ((int) $channel === WhatsAppAccount::CHANNEL) {
                return WhatsAppAccount::CHANNEL_NAME;
            }
            if ((int) $channel === WhatsAppAccount::CHANNEL_BSUID) {
                return WhatsAppAccount::CHANNEL_BSUID_NAME;
            }
            return $name;
        }, 20, 2);

        // Outbound (A2): camí natiu del core per a canals chat. S'executa
        // post-undo (backgroundAction amb delay UNDO_TIMOUT via TriggerAction).
        \Eventy::addAction('chat_conversation.send_reply', function ($conversation, $replies, $customer) {
            $this->handleOutboundReplies($conversation, $replies);
        }, 20, 3);

        // Neteja UX a les pàgines de canals WhatsApp: amaga els artefactes
        // d'email del core (toggle Cc/Bcc al reply, email tècnic al sidebar).
        // Només s'aplica si la pàgina pertany a una bústia amb compte WhatsApp.
        \Eventy::addAction('layout.body_bottom', function () {
            if ($this->currentPageIsWhatsAppMailbox()) {
                echo '<style>#toggle-cc, .sidebar-title-email { display: none !important; }</style>';
            }
        });

        // Banner de finestra expirada: només si la conversa és del mòdul
        // (té fila meta_whatsapp_messages) i la finestra ha caducat.
        // Signatura verificada al core (view.blade.php): 2 arguments.
        \Eventy::addAction('conversation.after_subject_block', function ($conversation, $mailbox) {
            $accountId = WhatsAppMessage::where('conversation_id', $conversation->id)
                ->value('account_id');
            if (!$accountId) {
                return;
            }
            $account = WhatsAppAccount::find($accountId);
            if (!$account || !WhatsAppMessage::windowExpired($conversation->id, $account)) {
                return;
            }
            $phone = WhatsAppMessage::where('conversation_id', $conversation->id)
                ->whereNotNull('contact_phone')
                ->orderByDesc('id')
                ->value('contact_phone');
            echo view('metawhatsapp::partials/window_banner', [
                'conversation' => $conversation,
                'account'      => $account,
                'phone'        => $phone,
            ])->render();
        }, 20, 2);

        // Miniatura d'imatge als adjunts multimèdia de WhatsApp: el core ja
        // llista qualsevol Attachment (nom + enllaç de descàrrega); només
        // s'hi afegeix una previsualització per a TYPE_IMAGE. La resta de
        // tipus (video/audio/document) es queden amb la fila per defecte
        // del core — és la degradació correcta, no cal construir-la.
        \Eventy::addAction('thread.attachment_append', function ($attachment, $thread, $conversation, $mailbox) {
            if ($attachment->type != \App\Attachment::TYPE_IMAGE) {
                return;
            }
            echo '<div class="metawhatsapp-attachment-preview">'
                . '<a href="' . e($attachment->url()) . '" target="_blank">'
                . '<img src="' . e($attachment->url()) . '" alt="' . e($attachment->file_name) . '" '
                . 'style="max-width:200px;max-height:200px;border-radius:4px;margin-top:6px;">'
                . '</a></div>';
        }, 20, 4);
    }

    protected function currentPageIsWhatsAppMailbox(): bool
    {
        $route = \Route::current();
        if (!$route) {
            return false;
        }

        $mailboxId = null;
        switch ($route->getName()) {
            case 'conversations.view':
                $conversation = \App\Conversation::find($route->parameter('id'));
                $mailboxId    = $conversation->mailbox_id ?? null;
                break;
            case 'mailboxes.view':
            case 'mailboxes.view.folder':
                $mailboxId = $route->parameter('id');
                break;
            default:
                return false;
        }

        return $mailboxId
            && WhatsAppAccount::where('mailbox_id', $mailboxId)->exists();
    }

    /**
     * Encua l'enviament WhatsApp de cada reply pendent d'una conversa chat.
     *
     * TriggerAction serialitza els paràmetres com a array pla: els models
     * arriben com a snapshot del moment del reply, NO re-hidratats. Tot el
     * que és decisiu (estat del thread) es re-consulta fresc de BD aquí i
     * al job.
     */
    protected function handleOutboundReplies($conversation, $replies)
    {
        $account = WhatsAppAccount::where('mailbox_id', $conversation->mailbox_id)
            ->where('is_active', true)
            ->first();
        if (!$account) {
            return; // Conversa chat d'un altre canal.
        }

        // Destinatari: últim inbound de la conversa; fallback customer_channel.
        $lastInbound = \Modules\MetaWhatsApp\Models\WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', \Modules\MetaWhatsApp\Models\WhatsAppMessage::DIRECTION_INBOUND)
            ->orderBy('id', 'desc')
            ->first();
        $phone = $lastInbound->contact_phone
            ?? \App\CustomerChannel::where('customer_id', $conversation->customer_id)
                ->where('channel', WhatsAppAccount::CHANNEL)
                ->value('channel_id');
        if (!$phone) {
            \Log::warning('[MetaWhatsApp] Reply without recipient phone, not sent', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        foreach ($replies as $reply) {
            $fresh = \App\Thread::find($reply->id);
            if (!$fresh
                || $fresh->type != \App\Thread::TYPE_MESSAGE
                || $fresh->state != \App\Thread::STATE_PUBLISHED
            ) {
                continue; // Notes internes i threads retirats per undo.
            }

            $attachments = $fresh->attachments;

            if ($attachments->isEmpty()) {
                if (\Modules\MetaWhatsApp\Models\WhatsAppMessage::where('thread_id', $fresh->id)
                    ->where('direction', \Modules\MetaWhatsApp\Models\WhatsAppMessage::DIRECTION_OUTBOUND)
                    ->exists()
                ) {
                    continue; // Ja enviat (o fallit definitivament).
                }
                \Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage::dispatch($account->id, $fresh->id, $phone);
                continue;
            }

            // Multimèdia (A3): un missatge de WhatsApp per adjunt (Meta no
            // permet més d'un objecte multimèdia per missatge). El text de
            // la resposta viatja com a caption del primer adjunt, tret que
            // sigui audio (Meta no admet caption en audio): en aquest cas
            // el text es despatxa a part.
            $text         = trim(\Helper::htmlToText($fresh->body));
            $firstIsAudio = \Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia::mediaCategory($attachments->first()->mime_type) === 'audio';

            if ($text !== '' && $firstIsAudio
                && !\Modules\MetaWhatsApp\Models\WhatsAppMessage::where('thread_id', $fresh->id)
                    ->where('direction', \Modules\MetaWhatsApp\Models\WhatsAppMessage::DIRECTION_OUTBOUND)
                    ->whereNull('attachment_id')
                    ->exists()
            ) {
                \Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage::dispatch($account->id, $fresh->id, $phone);
            }

            foreach ($attachments as $index => $attachment) {
                if (\Modules\MetaWhatsApp\Models\WhatsAppMessage::where('thread_id', $fresh->id)
                    ->where('attachment_id', $attachment->id)
                    ->where('direction', \Modules\MetaWhatsApp\Models\WhatsAppMessage::DIRECTION_OUTBOUND)
                    ->exists()
                ) {
                    continue; // Ja enviat (o fallit definitivament).
                }

                $caption = ($index === 0 && $text !== '' && !$firstIsAudio) ? $text : null;

                \Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia::dispatch($account->id, $fresh->id, $phone, $attachment->id, $caption);
            }
        }
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__ . '/../Views', 'metawhatsapp');
    }

    protected function registerTranslations()
    {
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'metawhatsapp');
    }
}
