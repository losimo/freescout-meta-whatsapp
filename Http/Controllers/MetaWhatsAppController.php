<?php

namespace Modules\MetaWhatsApp\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use Illuminate\Routing\Controller;
use Modules\MetaWhatsApp\Http\Requests\WhatsAppAccountRequest;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppTemplate;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

class MetaWhatsAppController extends Controller
{
    public function settings()
    {
        $this->requireAdmin();
        $accounts = WhatsAppAccount::with('mailbox')->orderBy('created_at', 'desc')->get();
        return view('metawhatsapp::settings', compact('accounts'));
    }

    public function create()
    {
        $this->requireAdmin();
        $account        = null;
        $generatedToken = bin2hex(random_bytes(32));
        $mailboxes      = $this->availableMailboxes();
        $webhookUrl     = $this->webhookUrl();
        return view('metawhatsapp::account_form', compact('account', 'generatedToken', 'mailboxes', 'webhookUrl'));
    }

    public function store(WhatsAppAccountRequest $request)
    {
        if ($request->mailbox_mode === 'new') {
            $mailbox = new Mailbox();
            $mailbox->name       = $request->mailbox_name;
            $mailbox->email      = 'whatsapp-' . $request->phone_number_id . '@channel.internal';
            $mailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
            $mailbox->in_server  = '';
            $mailbox->out_server = '';
            $mailbox->save();
            $mailbox->createPublicFolders();
            $mailbox->createAdminPersonalFolders();
            $mailboxId   = $mailbox->id;
            $autoCreated = true;
        } else {
            $mailboxId   = (int) $request->mailbox_id;
            $autoCreated = false;

            if (WhatsAppAccount::where('mailbox_id', $mailboxId)->exists()) {
                return redirect()->back()->withInput()
                    ->withErrors(['mailbox_id' => __('metawhatsapp::metawhatsapp.mailbox_already_linked')]);
            }
        }

        $account = new WhatsAppAccount();
        $account->fill($request->only([
            'name', 'phone_number', 'phone_number_id', 'waba_id', 'verify_token',
            'template_name', 'template_lang', 'template_threshold_minutes',
        ]));
        $account->mailbox_id           = $mailboxId;
        $account->auto_created_mailbox = $autoCreated;
        $account->access_token         = encrypt($request->access_token);
        $account->app_secret           = encrypt($request->app_secret);
        $account->is_active            = true;
        $account->save();

        \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_created'));
        return redirect()->route('metawhatsapp.settings');
    }

    public function edit($id)
    {
        $this->requireAdmin();
        $account    = WhatsAppAccount::with('mailbox')->findOrFail($id);
        $webhookUrl = $this->webhookUrl();
        return view('metawhatsapp::account_form', compact('account', 'webhookUrl'));
    }

    public function update(WhatsAppAccountRequest $request, $id)
    {
        $account = WhatsAppAccount::findOrFail($id);

        // L'associació canal-bústia és immutable en edició (spec v0.3 §3.2).
        $account->fill($request->only([
            'name', 'phone_number', 'phone_number_id', 'waba_id', 'verify_token',
            'template_name', 'template_lang', 'template_threshold_minutes',
        ]));
        if ($request->filled('access_token')) {
            $account->access_token = encrypt($request->access_token);
        }
        if ($request->filled('app_secret')) {
            $account->app_secret = encrypt($request->app_secret);
        }
        $account->save();

        \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_updated'));
        return redirect()->route('metawhatsapp.settings');
    }

    public function destroy($id)
    {
        $this->requireAdmin();
        $account = WhatsAppAccount::with('mailbox')->findOrFail($id);
        $mailbox = $account->mailbox;
        $autoCreated = $account->auto_created_mailbox;

        // Ordre imposat per la FK ON DELETE RESTRICT: primer el compte, després la bústia.
        $account->delete();

        if ($autoCreated && $mailbox) {
            if ($mailbox->conversations()->exists()) {
                \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_deleted_mailbox_kept'));
                return redirect()->route('metawhatsapp.settings');
            }
            $mailbox->delete();
        }

        \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_deleted'));
        return redirect()->route('metawhatsapp.settings');
    }

    /**
     * Banner de finestra expirada: enviament manual de la plantilla de
     * recuperació des de la conversa. Mateix guard d'autorització que
     * ConversationsController::view() del core (policy viewCached).
     */
    public function sendTemplate($id)
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            abort(404);
        }

        if (!auth()->user() || !auth()->user()->can('viewCached', $conversation)) {
            abort(403);
        }

        // Conversa "del mòdul": té almenys una fila meta_whatsapp_messages
        // (mateix criteri que fa servir el banner al ServiceProvider).
        $accountId = WhatsAppMessage::where('conversation_id', $conversation->id)->value('account_id');
        $account   = $accountId ? WhatsAppAccount::find($accountId) : null;
        if (!$account) {
            abort(404);
        }

        $phone = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->value('contact_phone');
        if (!$phone) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_no_phone')]);
        }

        if (empty($account->template_name) || empty($account->template_lang)) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_not_configured')]);
        }

        // Re-check de finestra al servidor: el banner es pot haver renderitzat
        // fa temps (pestanya oberta) i el client pot haver escrit mentrestant.
        // Sense aquest guard s'enviaria una plantilla de pagament innecessària
        // quan una resposta normal ja funcionaria.
        if (!WhatsAppMessage::windowExpired($conversation->id, $account)) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_window_open')]);
        }

        // Idempotència: evita el doble enviament (doble clic, doble POST del
        // formulari, reintent de xarxa...) si ja s'ha creat un thread
        // d'auditoria de plantilla per a aquesta conversa fa poc.
        $recentTemplateSent = Thread::where('conversation_id', $conversation->id)
            ->where('body', 'like', '[WhatsApp template]%')
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
        if ($recentTemplateSent) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_already_sent')]);
        }

        // Thread d'auditoria: deixa constància visible a la conversa de qui
        // i quan s'ha disparat l'enviament de la plantilla.
        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = auth()->id();
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '[WhatsApp template] ' . $account->template_name;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $conversation->customer_id;
        $thread->created_by_user_id = auth()->id();
        $thread->save();

        SendWhatsAppTemplate::dispatch($account->id, $thread->id, $phone);

        \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.template_sent'));
        return redirect()->back();
    }

    /**
     * Bústies associables: sense servidor d'entrada ni de sortida configurat
     * (els defaults de columna són in_protocol=1 i out_method=1, per això el
     * criteri és in_server/out_server buits) i no vinculades ja a un altre compte.
     */
    private function availableMailboxes()
    {
        $linked = WhatsAppAccount::pluck('mailbox_id');

        return Mailbox::where(function ($q) {
                $q->whereNull('in_server')->orWhere('in_server', '');
            })
            ->where(function ($q) {
                $q->whereNull('out_server')->orWhere('out_server', '');
            })
            ->whereNotIn('id', $linked)
            ->orderBy('name')
            ->get();
    }

    private function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/meta-whatsapp/webhook';
    }

    private function requireAdmin(): void
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403);
        }
    }
}
