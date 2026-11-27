<?php

namespace Modules\MetaWhatsApp\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\IdentityChangeAudit;

require_once __DIR__ . '/../vendor/autoload.php';

class MetaWhatsAppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerViews();
        $this->registerTranslations();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->registerMiddleware();
        $this->hooks();
    }

    /**
     * Issue #44. The core has no Eventy hook inside
     * ConversationsController@create and no filter on its route, so the only
     * way to refuse starting a conversation in a WhatsApp mailbox is from the
     * middleware stack. Pushed onto the `web` group rather than declared on a
     * route, because the route being guarded belongs to the core.
     */
    protected function registerMiddleware()
    {
        $this->app['router']->pushMiddlewareToGroup(
            'web',
            \Modules\MetaWhatsApp\Http\Middleware\RefuseNewConversation::class
        );
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

        // The module's own activity log, offered in the picker an
        // administrator chooses from under Settings » Alerts (issue #60). The
        // Logs screen itself lists whatever log names exist in the table, so
        // this is only about being alertable; without it the rows are
        // readable but nobody can ask to be told about them.
        //
        // The filter is the supported way in, and on 1.8.252 it is the only
        // one: getAvailableLogs() builds a list that merges the log names
        // present in the table and then returns the filter applied to the
        // static list instead, so the merge never reaches the caller.
        \Eventy::addFilter('activity_log.available_logs', function ($logs) {
            $logs[] = IdentityChangeAudit::LOG_NAME;

            return $logs;
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

        // Issue #57. Core 1.8.251 (core issue #5799) puts an "Also send reply
        // to customer by email" checkbox, ticked by default, on the reply
        // form of every chat conversation whose customer has an e-mail, and
        // ConversationsController reads it into Thread::META_CHAT_SEND_EMAIL
        // six lines before firing this hook. SendReplyToCustomer then
        // dispatches the WhatsApp send above and, with that flag present,
        // carries on and e-mails the same reply: the customer gets the answer
        // twice unless the agent unticks the box every single time.
        //
        // The stop has to be here and not in the stylesheet, because a
        // checkbox hidden by CSS is still submitted; hiding it alone would
        // only take away the agent's ability to untick it. The rule that
        // hides it is the second layer, so nobody is shown a control that
        // does nothing.
        //
        // The shape is the one the Kapso WhatsApp module settled on, which
        // MarkoD pointed at when he reported this.
        \Eventy::addAction('thread.before_save_from_request', function ($thread, $request = null) {
            $this->dropCoreEmailCopy($thread);
        }, 20, 2);

        // T01. The core's Mailbox::deleteMailbox() (app/Mailbox.php:1141 in
        // 1.8.252) destroys every conversation, thread and folder of the
        // mailbox and only then deletes the mailbox row, which is where
        // this module's foreign key was stopping it: an error, and a
        // mailbox left alive with nothing in it. There is no earlier hook
        // to use. The first one the core offers is this one, fired from
        // MailboxObserver::deleting(), by which point the content is
        // already gone. Refusing the deletion up front would need a hook
        // the core does not have yet.
        \Eventy::addAction('mailbox.before_delete', function ($mailbox) {
            $accounts = WhatsAppAccount::where('mailbox_id', (int) $mailbox->id)->get();
            foreach ($accounts as $account) {
                \Modules\MetaWhatsApp\Support\ChannelTeardown::forAccount($account);
            }
        }, 20, 1);

        // UX clean-up on WhatsApp channel pages: the core's e-mail artefacts
        // (the Cc/Bcc toggle in the reply form, the technical address under
        // the mailbox name in the sidebar) say nothing on a channel that has
        // no e-mail. Only applied when the page belongs to a mailbox that has
        // a WhatsApp account.
        //
        // Injected at layout.head (inside <head>, before the browser starts
        // painting the <body>) and not at layout.body_bottom (just before
        // </body>): with body_bottom there was a real window in which the
        // Cc/Bcc toggle and the dashboard counters could be seen unhidden for
        // an instant, a flash, before the <style> at the end of the page
        // arrived (found through issue #17).
        \Eventy::addAction('layout.head', function () {
            $account = $this->currentPageWhatsAppAccount();
            if ($account) {
                // Three selectors and not one: the core reveals the fields
                // by a path that does not go through the link. Restoring a
                // draft that carries cc or bcc makes it click `#toggle-cc`
                // itself, and a hidden element still answers a programmatic
                // click, so hiding only the link left the containers
                // reachable (issue #17, reopened twice on that). `.field-cc`
                // is carried by both the Cc and the Bcc block, `.cc-toggler`
                // is the wrapper the link sits in. The core does the same to
                // itself in chat mode.
                echo '<style>#toggle-cc, .cc-toggler, .field-cc { display: none !important; }</style>';
                echo $this->sidebarMailboxEmailCss();
                echo $this->sidebarPhoneMeta();
                echo '<style>.metawhatsapp-window-clock-open { color: #767676; } '
                    .'.metawhatsapp-window-clock-warning { color: #8a6d3b; }</style>';
                echo $this->hideSidebarNewConversationCss();
                // Second layer of issue #57: where the server drops the
                // flag, the checkbox would be a control that does nothing,
                // so it is hidden too. Where the channel allows the e-mail
                // copy, the checkbox is the control the agent uses and has
                // to stay visible. `.conv-chat-email` is the whole
                // form-group, label included.
                if (!$account->allow_core_email_copy) {
                    echo '<style>.conv-chat-email { display: none !important; }</style>';
                }
            }

            if (\Route::currentRouteName() == 'dashboard') {
                echo $this->dashboardCounterFixCss();
            }
        });

        // Window clock (v2.0.0) + expired banner: only if the conversation
        // is the module's (has a meta_whatsapp_messages row). Clock A (real
        // 24h) decides which of the three states to show; Clock B
        // (template_threshold_minutes) still, and only, decides when the
        // recovery banner appears — see
        // docs/superpowers/specs/2026-09-23-metawhatsapp-window-clock-design.md.
        //
        // Split across two Eventy hooks (v2.0.1, review finding #2), not
        // one function: the core template wraps conversation.after_subject_block
        // in a Bootstrap collapse behind "Show Details" whenever the
        // conversation is in Chat Mode — and this module always puts its
        // conversations in Chat Mode, so that's the normal case, not an
        // edge case. conversation.after_subject fires a few lines earlier
        // in the same template and is never collapsed, in or out of Chat
        // Mode. The clock is the part that must be genuinely always
        // visible, so it moves to that hook; the banner stays on
        // after_subject_block exactly as before — moving it is a separate
        // decision for a separate patch, not fixed here.
        //
        // Each closure re-decides open/closed independently by calling the
        // same tested static methods below; only the account lookup itself
        // is shared, via windowClockAccountFor().
        \Eventy::addAction('conversation.after_subject', function ($conversation, $mailbox) {
            $account = $this->windowClockAccountFor($conversation);
            if (!$account) {
                return;
            }

            echo '<div id="metawhatsapp-window-clock-wrapper" data-window-state-url="'
                . route('metawhatsapp.window_state', ['id' => $conversation->id])
                . '">'
                . \Modules\MetaWhatsApp\Support\WindowState::renderClock($conversation, $account)
                . '</div>';
        }, 20, 2);

        \Eventy::addAction('conversation.after_subject_block', function ($conversation, $mailbox) {
            $account = $this->windowClockAccountFor($conversation);
            if (!$account) {
                return;
            }

            echo '<div id="metawhatsapp-window-banner">'
                . \Modules\MetaWhatsApp\Support\WindowState::renderBanner($conversation, $account)
                . '</div>';
        }, 20, 2);

        // Live updates for the window clock (#41) and the expired-window
        // banner (#42): loads the client-side script that polls the
        // window-state endpoint (Task 2) and listens to the existing
        // Polycast channel for new threads. Harmless on the mailbox-list
        // page too (currentPageIsWhatsAppMailbox() also returns true
        // there) — the script's own early-return guard finds no wrapper
        // elements on that page and exits immediately.
        //
        // Second file in the same filter (#45): the sidebar phone number, see
        // sidebarMailboxEmailCss() below. Kept apart from window-state.js
        // rather than appended to it because that one early-returns on any
        // page without a conversation_id, and the mailbox list page is
        // precisely one of the pages this has to work on.
        \Eventy::addFilter('javascripts', function ($scripts) {
            if ($this->currentPageIsWhatsAppMailbox()) {
                $scripts[] = '/modules/metawhatsapp/js/window-state.js';
                $scripts[] = '/modules/metawhatsapp/js/sidebar-phone.js';
            }
            return $scripts;
        }, 20, 1);

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

    /**
     * Shared account lookup for the two window-clock hooks above: finds
     * the WhatsApp account tied to this conversation, or null when the
     * conversation isn't the module's (no meta_whatsapp_messages row).
     */
    private function windowClockAccountFor($conversation): ?WhatsAppAccount
    {
        $accountId = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->value('account_id');
        if (!$accountId) {
            return null;
        }

        return WhatsAppAccount::find($accountId);
    }

    protected function currentPageIsWhatsAppMailbox(): bool
    {
        return (bool) $this->currentPageWhatsAppAccount();
    }

    /**
     * The WhatsApp account the current page belongs to, or null when the page
     * is not one of the module's or its mailbox has no account.
     *
     * Split out of currentPageIsWhatsAppMailbox() for issue #45, which needs
     * the row itself (the phone number) and not just the yes/no answer. The
     * two must keep agreeing, so one is written in terms of the other rather
     * than repeating the route switch.
     *
     * Deliberately not memoised: the same provider instance serves several
     * requests inside one PHP process under phpunit, so a cached answer from
     * the previous request would leak into the next one.
     */
    protected function currentPageWhatsAppAccount(): ?WhatsAppAccount
    {
        $route = \Route::current();
        if (!$route) {
            return null;
        }

        $mailboxId = null;
        switch ($route->getName()) {
            case 'conversations.view':
                $conversation = \App\Conversation::find($route->parameter('id'));
                $mailboxId    = $conversation->mailbox_id ?? null;
                break;
            case 'conversations.chats':
                // The empty state of a brand new channel. This route usually
                // redirects to the first chat, which lands on
                // conversations.view above, but with no conversations yet it
                // renders itself, and that is exactly the page somebody sees
                // right after configuring the channel. Carries mailbox_id
                // rather than id.
                $mailboxId = $route->parameter('mailbox_id');
                break;
            case 'mailboxes.view':
            case 'mailboxes.view.folder':
                $mailboxId = $route->parameter('id');
                break;
            default:
                return null;
        }

        if (!$mailboxId) {
            return null;
        }

        return WhatsAppAccount::where('mailbox_id', $mailboxId)->first();
    }

    /**
     * Issue #45. `.sidebar-title-email` is the line under the mailbox name in
     * the left sidebar, and on a WhatsApp mailbox the core fills it with the
     * technical address of the channel mailbox
     * (whatsapp-<phone_number_id>@channel.internal), which means nothing to
     * anyone reading it.
     *
     * Until now the module hid it with `display: none`, and that collapsed the
     * header: `.sidebar-title` measures 51.8px tall normally and 24.4px with
     * the element taken out of the flow, so everything below shifted up by
     * 27px and the 50x50 hover icon of the official Mailbox Icons module ended
     * up covered.
     *
     * `visibility: hidden` keeps the box exactly as tall as it was, because
     * the element still takes part in layout and only its painting is
     * suppressed, while the address itself is not painted, not selectable and
     * not exposed to a screen reader. It is also what the official Global
     * Mailbox module settled on for the same line: leave the element in place
     * and empty rather than remove it.
     *
     * Not `!important` on purpose: sidebar-phone.js turns the element visible
     * again through an inline style, and an inline style only wins over a
     * declaration that is not `!important`.
     *
     * Why this cannot flash the technical address. The rule travels in the
     * <head>, so it belongs to the first style resolution the browser does
     * and applies before the sidebar is painted for the first time: the
     * address is never painted at all, not even for a frame. The script then
     * works in the only safe order, text first and visibility second, so
     * there is no moment at which the element is visible while still holding
     * the old text. The reverse order, or a rule injected at the end of the
     * body, is exactly the flash this module already paid for in issue #17.
     *
     * If the script never runs, the reader is left with an empty box of the
     * right height, which is the honest degradation: no technical address and
     * no 27px collapse either.
     */
    protected function sidebarMailboxEmailCss(): string
    {
        return '<style>.sidebar-title-email { visibility: hidden; }</style>';
    }

    /**
     * Issue #57: take the core's "also send this reply by e-mail" flag off a
     * reply that belongs to a WhatsApp mailbox, so SendReplyToCustomer stops
     * after dispatching the WhatsApp send instead of sending the same text
     * again by e-mail.
     *
     * Keyed on the thread's own conversation and not on the request or the
     * page, because this hook fires on every reply in the install and both
     * of the core's call sites have to get the same answer: the reply path
     * (ConversationsController:1251 in 1.8.252) and the per-recipient copies
     * of a new conversation (:1410). The second should be unreachable here,
     * since this module refuses starting a conversation in a WhatsApp
     * mailbox, and answering for it costs a query it will never run.
     *
     * Silent on a core older than 1.8.251, which has neither the checkbox nor
     * the constant. That silence is the risk the tripwire in
     * CoreChatEmailCopyTest covers: if the core renames the constant while
     * keeping the checkbox, this guard would quietly stop guarding.
     */
    protected function dropCoreEmailCopy($thread): void
    {
        if (!$thread instanceof \App\Thread
            || !defined(\App\Thread::class . '::META_CHAT_SEND_EMAIL')
        ) {
            return;
        }

        $mailboxId = (int) optional($thread->conversation)->mailbox_id;
        if (!$mailboxId) {
            $mailboxId = (int) \App\Conversation::where('id', (int) $thread->conversation_id)
                ->value('mailbox_id');
        }

        if (!$mailboxId) {
            return;
        }

        $account = WhatsAppAccount::where('mailbox_id', $mailboxId)->first();
        if (!$account) {
            return;
        }

        // The channel can be told to allow it. The core's own default is to
        // offer this checkbox, so a module that refuses it for everyone is
        // overruling the core rather than fixing the defect, which was the
        // box arriving ticked (#57). Off by default: the accident stays
        // closed for an installation that never thinks about it.
        if ($account->allow_core_email_copy) {
            return;
        }

        $thread->unsetMeta(\App\Thread::META_CHAT_SEND_EMAIL);
    }

    /**
     * Issue #45. The account's phone number, for sidebar-phone.js to put where
     * the technical address used to be.
     *
     * It travels as a <meta> in the <head> and not as a data attribute on an
     * element inside the sidebar because the core's sidebar view
     * (resources/views/mailboxes/sidebar_menu_view.blade.php, checked against
     * FreeScout 1.8.251) has no hook after `.sidebar-title-email`. The only
     * hook inside that block, `mailbox.view.before_name`, fires before the
     * mailbox name, so anything echoed through it would be a stray element
     * sitting in the core's title box. Emitting the value here also means the
     * number and the rule that hides the address are written under one single
     * condition and can never disagree.
     *
     * Empty string when the account has no number stored. The reader then
     * keeps the empty box from the rule above, which is the right answer:
     * there is nothing useful to show, and the technical address was not
     * useful either.
     */
    protected function sidebarPhoneMeta(): string
    {
        $account = $this->currentPageWhatsAppAccount();
        if (!$account || !$account->phone_number) {
            return '';
        }

        return '<meta name="metawhatsapp-sidebar-phone" content="'
            . e($account->phone_number) . '">';
    }

    /**
     * Issue #44. The core gates its "New Conversation" button differently in
     * two places: the dashboard requires both the `mailbox.show_buttons`
     * filter and `$mailbox->isActive()`
     * (resources/views/secure/dashboard.blade.php), while the sidebar checks
     * only the filter (resources/views/mailboxes/sidebar_menu_view.blade.php).
     * A WhatsApp mailbox has no incoming e-mail server on purpose, so
     * isActive() is false and the dashboard hides the button on its own,
     * which is why the reporter saw it in one place and not the other.
     *
     * The filter is reachable here, but it wraps the whole `.sidebar-buttons`
     * block, so returning false from it would also take away the Mailbox
     * Settings button next to it. Hiding one link instead of three quarters
     * of the sidebar's controls is worth doing in CSS.
     *
     * Done without adding an element: the container is a Bootstrap
     * `btn-group-justified`, which lays its children out as table cells, so a
     * marker element would claim a column of its own and skew the row. The
     * rule keys on markup the core already writes.
     *
     * Inert from FreeScout 1.8.252, and deliberately kept. That release
     * gates the link on `$mailbox->isConnected()` in
     * resources/views/mailboxes/sidebar_menu_view.blade.php, so on a
     * WhatsApp mailbox it is no longer rendered and this rule matches
     * nothing. It is not behind a version check on purpose: the module's
     * floor is still 1.8.234, so the rule is needed from there to 1.8.251
     * anyway, and a check could misread a patched template and bring the
     * button back on an install where the route refuses it regardless. The
     * rule goes away on its own when the floor rises past 1.8.251.
     *
     * Deliberately only this one link. Hiding the whole strip when it has
     * nothing left (an agent without permission to see the settings button)
     * would be one more rule, and it cannot be written safely:
     * `mailbox.sidebar.buttons` is a public hook, so another module may have
     * put its own button in that strip, and a rule broad enough to catch the
     * empty case would take that button with it. An empty strip is a cosmetic
     * problem; removing another module's button is a functional one.
     */
    protected function hideSidebarNewConversationCss(): string
    {
        return '<style>.sidebar-buttons a[href*="/new-ticket"] { display: none !important; }</style>';
    }

    /**
     * FreeScout's dashboard hides ticket counters (Unassigned/Mine/Starred)
     * behind the `dash-card-inactive` class whenever a mailbox has no
     * incoming e-mail server configured — Mailbox::isInActive() (core) is
     * false. WhatsApp mailboxes are intentionally configured this way (to
     * avoid mixing e-mail and WhatsApp conversations), but the counters
     * themselves are always computed server-side regardless of that class
     * (see resources/views/secure/dashboard.blade.php); only the CSS toggle
     * hides them. This overrides that toggle for WhatsApp mailboxes only,
     * without touching Mailbox::isInActive() itself — doing that instead
     * would also flip freescout:fetch-emails' per-mailbox guard and make it
     * attempt a real IMAP connection with an empty server (issue #22).
     */
    protected function dashboardCounterFixCss(): string
    {
        $mailboxIds = WhatsAppAccount::pluck('mailbox_id')->filter();
        if ($mailboxIds->isEmpty()) {
            return '';
        }

        // Cada selector complet (base + descendent) s'ha de repetir per
        // mailbox: una coma en CSS separa selectors sencers, no prefixos
        // compartits — '.a, .b .x' NO aplica .x a .a i .b totes dues.
        $listSelectors    = [];
        $contentSelectors = [];
        $cardSelectors    = [];
        foreach ($mailboxIds as $id) {
            $base = '.dash-card.dash-card-inactive[data-mailbox-id="'.(int) $id.'"]';
            $listSelectors[]    = $base.' .dash-card-list';
            $contentSelectors[] = $base.' .dash-card-inactive-content';
            $cardSelectors[]    = $base;
        }

        // El core també pinta la targeta inactiva amb fons gris
        // (.dash-card.dash-card-inactive { background: #f4f5f5 }). Ensenyar
        // els comptadors damunt d'un fons d'inactiu era una mitja mesura:
        // la targeta activa no defineix cap fons, així que es retorna a
        // transparent per igualar-la.
        return '<style>'
            .implode(', ', $listSelectors).' { display: block; }'
            .implode(', ', $contentSelectors).' { display: none; }'
            .implode(', ', $cardSelectors).' { background: transparent; }'
            .'</style>';
    }

    /**
     * Primer reply que de debò hauria sortit, rellegit de BD. Mateix filtre
     * que el bucle d'enviament: notes internes i threads retirats amb undo
     * no compten.
     */
    protected function firstPublishedMessage($replies): ?\App\Thread
    {
        foreach ($replies as $reply) {
            $fresh = \App\Thread::find($reply->id ?? null);
            if ($fresh
                && $fresh->type == \App\Thread::TYPE_MESSAGE
                && $fresh->state == \App\Thread::STATE_PUBLISHED
            ) {
                return $fresh;
            }
        }

        return null;
    }

    /**
     * Encua l'enviament WhatsApp de cada reply pendent d'una conversa chat.
     *
     * TriggerAction serialitza els paràmetres com a array pla: els models
     * arriben com a snapshot del moment del reply, NO re-hidratats. Tot el
     * que és decisiu (estat del thread) es re-consulta fresc de BD aquí i
     * al job.
     *
     * Inside AgentNotifications::around(), for the same reason the jobs are
     * (#64), and the reason is this hook's own transport. The core fires
     * `chat_conversation.send_reply` through Helper::backgroundAction()
     * (app/Listeners/SendReplyToCustomer.php:89), which dispatches the
     * App\Jobs\TriggerAction job with the undo delay, so this method runs
     * inside a queue worker and not in the request that wrote the reply. The
     * first version of the #64 fix got that wrong and left the commonest
     * refusal of all, a channel switched off, writing a note that notified
     * nobody. Worse than nobody, in fact: a registered event sits in a
     * static buffer until the next job of ours on that worker flushes it
     * along with its own.
     */
    protected function handleOutboundReplies($conversation, $replies)
    {
        return \Modules\MetaWhatsApp\Support\AgentNotifications::around(
            function () use ($conversation, $replies) {
                return $this->dispatchOutboundReplies($conversation, $replies);
            },
            ['hook' => 'chat_conversation.send_reply', 'mailbox_id' => $conversation->mailbox_id ?? null]
        );
    }

    /** The hook itself. Everything it writes is flushed by the wrapper above. */
    protected function dispatchOutboundReplies($conversation, $replies)
    {
        $account = WhatsAppAccount::where('mailbox_id', $conversation->mailbox_id)->first();
        if (!$account) {
            return; // A chat conversation of some other channel.
        }

        // T28. The lookup used to ask for is_active = true, so a channel an
        // administrator had switched off took the return above, the one that
        // means "not ours". It is ours, stopped. Every refusal this module
        // knows how to report lives inside the outbound jobs, and none of
        // them is dispatched from here, so the agent wrote a reply, pressed
        // send, saw it in the conversation, and nothing happened anywhere:
        // no note, no log, no row.
        //
        // No failed row is written on purpose. Writing one would also stop
        // these replies from being re-dispatched when the channel comes
        // back, which is a different question with a real trade-off (a
        // backlog delivered days late and out of context, against replies
        // that are never sent at all unless the agent sends them again).
        // That one is our finding 4 and belongs to its own ticket, decided
        // rather than arrived at as a side effect.
        //
        // Its own note, and not the one the jobs use. The core hands this
        // hook every message thread of the conversation and not only the
        // new one (core, app/Listeners/SendReplyToCustomer.php:41 in
        // 1.8.252), and the loop below dispatches any reply with no row of
        // its own, so a reply refused here does go out once the channel is
        // back and the agent writes again. The jobs' note asks the agent to
        // send the message again, which is right where a failed row was
        // written and nothing will re-dispatch it, and on this path it asked
        // for the duplicate: the customer would get the same answer twice.
        if (!$account->is_active) {
            \Log::error('[MetaWhatsApp] Reply not sent: the channel is inactive', [
                'conversation_id' => $conversation->id,
                'account_id'      => $account->id,
            ]);
            \Modules\MetaWhatsApp\Support\OutboundGuard::noteRefusal(
                $this->firstPublishedMessage($replies),
                'metawhatsapp::metawhatsapp.not_sent_channel_inactive_kept'
            );

            return;
        }

        // Destinatari: últim inbound de la conversa; fallback customer_channel.
        // Through the model, so that the number a reply goes to and the
        // window the agent was shown cannot be decided by different rows.
        $lastInbound = \Modules\MetaWhatsApp\Models\WhatsAppMessage::lastInbound($conversation->id);
        $phone = $lastInbound->contact_phone ?? null;

        if (!$phone) {
            // El fallback agafava la primera fila que trobava. Des del #59 un
            // client pot tenir més d'un número de WhatsApp, i triar-ne un
            // voldria dir lliurar la resposta a algú a qui l'agent no
            // escrivia, sense que res a la pantalla ho digués. Amb diversos
            // números i cap missatge entrant que n'assenyali un, el mòdul
            // s'absté.
            $numbers = \App\CustomerChannel::where('customer_id', $conversation->customer_id)
                ->where('channel', WhatsAppAccount::CHANNEL)
                ->pluck('channel_id');

            if ($numbers->count() === 1) {
                $phone = $numbers->first();
            } elseif ($numbers->count() > 1) {
                \Log::error('[MetaWhatsApp] Reply not sent: the customer has several WhatsApp numbers and the conversation has no inbound message to choose one', [
                    'conversation_id' => $conversation->id,
                    'numbers'         => $numbers->count(),
                ]);
                \Modules\MetaWhatsApp\Support\OutboundGuard::noteRefusal(
                    $this->firstPublishedMessage($replies),
                    'metawhatsapp::metawhatsapp.not_sent_several_numbers'
                );

                return;
            }
        }

        if (!$phone) {
            \Log::error('[MetaWhatsApp] Reply without recipient phone, not sent', [
                'conversation_id' => $conversation->id,
            ]);
            // Sense telèfon no hi ha res a on enviar, però l'agent ja ha
            // escrit i premut enviar: el thread queda a la conversa i sembla
            // lliurat. La nota és l'única cosa que li ho desmenteix.
            //
            // El thread es torna a llegir de BD i no es fa servir el de
            // $replies, que és un snapshot sense re-hidratar (vegeu la
            // capçalera del mètode), i s'aplica el mateix filtre que el
            // bucle de sota: si no hi ha cap missatge publicat, no hi havia
            // res per enviar i la nota sobraria.
            \Modules\MetaWhatsApp\Support\OutboundGuard::noteRefusal(
                $this->firstPublishedMessage($replies),
                'metawhatsapp::metawhatsapp.not_sent_no_phone'
            );
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
            // la resposta viatja com a caption del primer adjunt.
            //
            // Two reasons it cannot: Meta takes no caption on audio, and a
            // caption over MAX_CAPTION characters has the whole message
            // refused. Both end the same way, with the text dispatched as a
            // message of its own, so they are one condition and they are
            // decided here.
            //
            // The length rule lives here and not inside the media job, which
            // is where the review found it: there it ran before the upload,
            // and a transient error brought the worker back with the caption
            // restored from the serialized payload, so the text could be
            // dispatched twice or, if the first attempt died earlier still,
            // never. Here it is decided once per reply, from the thread, with
            // no retry around it.
            $text         = trim(\Helper::htmlToText($fresh->body));
            $firstIsAudio = \Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia::mediaCategory($attachments->first()->mime_type) === 'audio';
            $textRides    = $text !== ''
                && !$firstIsAudio
                && mb_strlen($text) <= \Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia::MAX_CAPTION;

            // Deliberately without a window check of its own, after the
            // fourth review round showed what one costs here. Deciding it in
            // the request rather than in the job takes a snapshot: with the
            // window shut no text job was queued, and if the customer wrote
            // in before the media job ran, the file went out and the agent's
            // words were dropped with nothing to say so. It would also make
            // the module stricter than Meta on an administrator's own margin,
            // refusing a text that Meta might still have taken. Meta decides,
            // and its refusal leaves a note like any other.
            if ($text !== '' && !$textRides
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

                $caption = ($index === 0 && $textRides) ? $text : null;

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
