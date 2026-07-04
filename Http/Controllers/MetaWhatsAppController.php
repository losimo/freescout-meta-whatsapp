<?php

namespace Modules\MetaWhatsApp\Http\Controllers;

use App\Mailbox;
use Illuminate\Routing\Controller;
use Modules\MetaWhatsApp\Http\Requests\WhatsAppAccountRequest;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

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
