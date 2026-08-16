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
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

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
            'name', 'conversation_subject_template', 'phone_number', 'phone_number_id', 'waba_id', 'verify_token',
            'template_name', 'template_lang', 'template_threshold_minutes',
        ]));
        $account->templates            = $this->cleanTemplates($request);
        $account->mailbox_id           = $mailboxId;
        $account->auto_created_mailbox = $autoCreated;
        $account->access_token         = encrypt($request->access_token);
        $account->app_secret           = encrypt($request->app_secret);
        $account->is_active            = true;
        $account->save();

        // Registre automàtic de webhook (roadmap: pas d'instal·lació que
        // sovint es passa per alt manualment). Best-effort: si falla, no
        // bloqueja la creació del compte — l'admin pot reintentar-ho des
        // del botó "Subscribe webhook" a l'edició.
        $subscribeResult = app(WhatsAppApiClient::class, ['account' => $account])->subscribeWebhook();
        if ($subscribeResult['ok']) {
            \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_created'));
        } else {
            \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_created'));
            \Session::flash('flash_warning_floating', __('metawhatsapp::metawhatsapp.webhook_subscribe_failed', [
                'error' => $subscribeResult['error_message'] ?: __('metawhatsapp::metawhatsapp.test_connection_unknown_error'),
            ]));
        }

        return redirect()->route('metawhatsapp.settings');
    }

    public function edit($id)
    {
        $this->requireAdmin();
        $account    = WhatsAppAccount::with('mailbox')->findOrFail($id);
        $webhookUrl = $this->webhookUrl();

        // Instantània de salut: es llegeix de meta_whatsapp_messages, cap
        // dada nova a persistir (delivered_at/read_at/error_code ja existeixen).
        $healthSnapshot = [
            'last_inbound'  => WhatsAppMessage::where('account_id', $account->id)
                ->where('direction', WhatsAppMessage::DIRECTION_INBOUND)
                ->latest('id')->first(),
            'last_outbound' => WhatsAppMessage::where('account_id', $account->id)
                ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
                ->latest('id')->first(),
            'last_error'    => WhatsAppMessage::where('account_id', $account->id)
                ->where('status', WhatsAppMessage::STATUS_FAILED)
                ->latest('id')->first(),
        ];

        return view('metawhatsapp::account_form', compact('account', 'webhookUrl', 'healthSnapshot'));
    }

    public function update(WhatsAppAccountRequest $request, $id)
    {
        $account = WhatsAppAccount::findOrFail($id);

        // L'associació canal-bústia és immutable en edició (spec v0.3 §3.2).
        $account->fill($request->only([
            'name', 'conversation_subject_template', 'phone_number', 'phone_number_id', 'waba_id', 'verify_token',
            'template_name', 'template_lang', 'template_threshold_minutes',
        ]));
        $account->templates = $this->cleanTemplates($request);
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
     * Botó "Test connection" del formulari de compte: crida lleugera a
     * Graph API per confirmar que el phone_number_id i el token encara són
     * vàlids, sense enviar cap missatge. Resultat via flash, redirigint a
     * edit(), mateix patró que la resta d'accions d'aquest controlador.
     */
    public function testConnection($id)
    {
        $this->requireAdmin();
        $account = WhatsAppAccount::findOrFail($id);

        $result = app(WhatsAppApiClient::class, ['account' => $account])->testConnection();

        if ($result['ok']) {
            // Reactivació guiada (issue #9): un test de connexió amb èxit
            // sobre un compte desactivat (p. ex. per un error 190 anterior)
            // el reactiva automàticament, amb audit trail (qui/quan).
            if (!$account->is_active) {
                $account->is_active      = true;
                $account->reactivated_at = now();
                $account->reactivated_by = auth()->id();
                $account->save();

                \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.account_reactivated', [
                    'name' => $result['verified_name'] ?: $account->phone_number,
                ]));
            } else {
                \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.test_connection_success', [
                    'name' => $result['verified_name'] ?: $account->phone_number,
                ]));
            }
        } else {
            \Session::flash('flash_error_floating', __('metawhatsapp::metawhatsapp.test_connection_failed', [
                'error' => $result['error_message'] ?: __('metawhatsapp::metawhatsapp.test_connection_unknown_error'),
            ]));
        }

        return redirect()->route('metawhatsapp.edit', $id);
    }

    /**
     * Registre manual de webhook (roadmap): reintent per si l'automàtic en
     * crear el compte va fallar, o per readoptar la subscripció si algú
     * l'ha revocada des del Meta App Dashboard.
     */
    public function subscribeWebhook($id)
    {
        $this->requireAdmin();
        $account = WhatsAppAccount::findOrFail($id);

        $result = app(WhatsAppApiClient::class, ['account' => $account])->subscribeWebhook();

        if ($result['ok']) {
            \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.webhook_subscribed_success'));
        } else {
            \Session::flash('flash_error_floating', __('metawhatsapp::metawhatsapp.webhook_subscribe_failed', [
                'error' => $result['error_message'] ?: __('metawhatsapp::metawhatsapp.test_connection_unknown_error'),
            ]));
        }

        return redirect()->route('metawhatsapp.edit', $id);
    }

    /**
     * Banner de finestra expirada: enviament manual de la plantilla de
     * recuperació des de la conversa. Mateix guard d'autorització que
     * ConversationsController::view() del core (policy viewCached).
     */
    public function sendTemplate($id, \Illuminate\Http\Request $request)
    {
        [$conversation, $account, $phone] = $this->resolveModuleConversation($id);
        if (!$phone) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_no_phone')]);
        }

        $templates = $account->getTemplateList();
        if (empty($templates)) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_not_configured')]);
        }

        // Amb una sola plantilla configurada el banner no envia template_id
        // (compatibilitat amb el formulari d'una sola plantilla); amb
        // diverses, cal que el botó premut identifiqui quina.
        if ($request->filled('template_id') && $request->filled('template_language')) {
            $template = $account->findTemplate($request->input('template_id'), $request->input('template_language'));
        } else {
            $template = count($templates) === 1 ? $templates[0] : null;
        }
        if (!$template) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_not_configured')]);
        }

        if ($guard = $this->guardTemplateWindowAndIdempotency($conversation, $account)) {
            return $guard;
        }

        // Text de l'auditoria (issue #2, punt 3): el recovery_text llegible
        // configurat per l'admin, si n'hi ha; si no, el nom de la plantilla.
        $thread = $this->createTemplateAuditThread($conversation, $template['recovery_text'] ?: $template['id']);

        SendWhatsAppTemplate::dispatch($account->id, $thread->id, $phone, $template['id'], $template['language']);

        \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.template_sent'));
        return redirect()->back();
    }

    /**
     * Picker de plantilles en viu (issue #2, punt 2 — versió completa amb
     * fetch dinàmic de Meta i variables, kapsowhatsapp-style). Complementa
     * la llista estàtica del banner, no la substitueix: aquí es llisten
     * TOTES les plantilles APPROVED del WABA, amb un input per variable
     * ({{n}}) detectada al cos.
     */
    public function browseTemplates($id)
    {
        [$conversation, $account, $phone] = $this->resolveModuleConversation($id);

        $result = app(WhatsAppApiClient::class, ['account' => $account])->listTemplates();

        return view('metawhatsapp::templates_picker', compact('conversation', 'account', 'phone', 'result'));
    }

    /**
     * Enviament amb variables des del picker dinàmic. Mateixos guards de
     * finestra/idempotència que sendTemplate(); a diferència d'aquell, el
     * nom/idioma venen del request (qualsevol plantilla APPROVED del WABA,
     * no només les configurades estàticament al compte).
     */
    public function sendDynamicTemplate($id, \Illuminate\Http\Request $request)
    {
        [$conversation, $account, $phone] = $this->resolveModuleConversation($id);
        if (!$phone) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_no_phone')]);
        }

        $name     = trim((string) $request->input('template_name'));
        $language = trim((string) $request->input('template_language'));
        if ($name === '' || $language === '') {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_not_configured')]);
        }

        if ($guard = $this->guardTemplateWindowAndIdempotency($conversation, $account)) {
            return $guard;
        }

        $variables = array_values(array_map('strval', (array) $request->input('variables', [])));
        $label     = $name . (empty($variables) ? '' : ' (' . implode(', ', $variables) . ')');
        $thread    = $this->createTemplateAuditThread($conversation, $label);

        SendWhatsAppTemplate::dispatch($account->id, $thread->id, $phone, $name, $language, $variables);

        \Session::flash('flash_success_floating', __('metawhatsapp::metawhatsapp.template_sent'));
        return redirect()->back();
    }

    /**
     * Resol la conversa "del mòdul" (té almenys una fila
     * meta_whatsapp_messages) i el seu compte, amb el mateix guard
     * d'autorització que ConversationsController::view() del core
     * (policy viewCached). Retorna [conversation, account, phone|null] —
     * $phone pot ser null (contacte BSUID-only), cal comprovar-ho al crider.
     */
    private function resolveModuleConversation($id): array
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            abort(404);
        }

        if (!auth()->user() || !auth()->user()->can('viewCached', $conversation)) {
            abort(403);
        }

        $accountId = WhatsAppMessage::where('conversation_id', $conversation->id)->value('account_id');
        $account   = $accountId ? WhatsAppAccount::find($accountId) : null;
        if (!$account) {
            abort(404);
        }

        $phone = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->value('contact_phone');

        return [$conversation, $account, $phone];
    }

    /**
     * Re-check de finestra al servidor (el banner/picker es pot haver
     * renderitzat fa temps) + idempotència de 60s contra doble enviament.
     * Retorna una RedirectResponse d'error, o null si tot està bé.
     */
    private function guardTemplateWindowAndIdempotency(Conversation $conversation, WhatsAppAccount $account)
    {
        if (!WhatsAppMessage::windowExpired($conversation->id, $account)) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_window_open')]);
        }

        $recentTemplateSent = Thread::where('conversation_id', $conversation->id)
            ->where('body', 'like', '[WhatsApp template]%')
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
        if ($recentTemplateSent) {
            return redirect()->back()
                ->withErrors(['send_template' => __('metawhatsapp::metawhatsapp.template_already_sent')]);
        }

        return null;
    }

    /**
     * Thread d'auditoria: deixa constància visible a la conversa de qui i
     * quan s'ha disparat l'enviament d'una plantilla (estàtica o dinàmica).
     */
    private function createTemplateAuditThread(Conversation $conversation, string $bodyText): Thread
    {
        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = auth()->id();
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '[WhatsApp template] ' . $bodyText;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $conversation->customer_id;
        $thread->created_by_user_id = auth()->id();
        $thread->save();

        return $thread;
    }

    /**
     * Neteja les fins a 5 files del formulari de plantilles (issue #2, punts
     * 2-4): descarta files sense id o sense language (les considera buides)
     * i talla display_name/recovery_text al camp corresponent.
     */
    private function cleanTemplates(\Illuminate\Http\Request $request): array
    {
        $rows = $request->input('templates', []);
        if (!is_array($rows)) {
            return [];
        }

        $clean = [];
        foreach ($rows as $row) {
            $id       = trim($row['id'] ?? '');
            $language = trim($row['language'] ?? '');
            if ($id === '' || $language === '') {
                continue;
            }
            $clean[] = [
                'id'            => $id,
                'language'      => $language,
                'display_name'  => trim($row['display_name'] ?? '') ?: $id,
                'recovery_text' => trim($row['recovery_text'] ?? '') ?: null,
            ];
        }

        return $clean;
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
