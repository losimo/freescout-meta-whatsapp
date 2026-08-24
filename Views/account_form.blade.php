@extends('layouts.app')

@section('title_full', $account ? __('metawhatsapp::metawhatsapp.edit_account') : __('metawhatsapp::metawhatsapp.add_account'))

@section('content')
<div class="section-heading">
    {{ $account ? __('metawhatsapp::metawhatsapp.edit_account') : __('metawhatsapp::metawhatsapp.add_account') }}
</div>

<div class="container" style="margin-top:20px">
    <div class="row">
        <div class="col-xs-12 col-md-8 col-md-offset-2">
            @include('partials/flash_messages')

            <form method="POST"
                  action="{{ $account ? route('metawhatsapp.update', $account->id) : route('metawhatsapp.store') }}"
                  class="form-horizontal">
                {{ csrf_field() }}
                @if($account) {{ method_field('PUT') }} @endif

                {{-- Secció 1: Identificació del canal --}}
                <h4>{{ __('metawhatsapp::metawhatsapp.section_identification') }}</h4>
                <hr style="margin-top:5px">

                <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.channel_name') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               value="{{ old('name', $account->name ?? '') }}"
                               placeholder="{{ __('metawhatsapp::metawhatsapp.channel_name_placeholder') }}">
                        @if($errors->has('name'))<p class="help-block">{{ $errors->first('name') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('conversation_subject_template') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.conversation_subject_template') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="conversation_subject_template" class="form-control" maxlength="190"
                               value="{{ old('conversation_subject_template', $account->conversation_subject_template ?? '') }}"
                               placeholder="{{ __('metawhatsapp::metawhatsapp.conversation_subject') }}">
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.conversation_subject_template_help') }}</p>
                        @if($errors->has('conversation_subject_template'))<p class="help-block">{{ $errors->first('conversation_subject_template') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('phone_number') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.phone_number') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="phone_number" class="form-control" required
                               value="{{ old('phone_number', $account->phone_number ?? '') }}"
                               placeholder="+34600000000">
                        @if($errors->has('phone_number'))<p class="help-block">{{ $errors->first('phone_number') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('phone_number_id') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.phone_number_id') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="phone_number_id" class="form-control" required maxlength="50"
                               value="{{ old('phone_number_id', $account->phone_number_id ?? '') }}"
                               placeholder="107856...">
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.phone_number_id_help') }}</p>
                        @if($errors->has('phone_number_id'))<p class="help-block">{{ $errors->first('phone_number_id') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('waba_id') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.waba_id') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="waba_id" class="form-control" required maxlength="50"
                               value="{{ old('waba_id', $account->waba_id ?? '') }}">
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.waba_id_help') }}</p>
                        @if($errors->has('waba_id'))<p class="help-block">{{ $errors->first('waba_id') }}</p>@endif
                    </div>
                </div>

                {{-- Secció 2: Credencials API --}}
                <h4 style="margin-top:30px">{{ __('metawhatsapp::metawhatsapp.section_credentials') }}</h4>
                <hr style="margin-top:5px">

                <div class="form-group{{ $errors->has('access_token') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.access_token') }}</label>
                    <div class="col-sm-8">
                        <input type="password" name="access_token" class="form-control" autocomplete="new-password"
                               @if(!$account) required @endif
                               placeholder="{{ $account ? __('metawhatsapp::metawhatsapp.leave_blank_to_keep') : '' }}">
                        @if($errors->has('access_token'))<p class="help-block">{{ $errors->first('access_token') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('app_secret') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.app_secret') }}</label>
                    <div class="col-sm-8">
                        <input type="password" name="app_secret" class="form-control" autocomplete="new-password"
                               @if(!$account) required @endif
                               placeholder="{{ $account ? __('metawhatsapp::metawhatsapp.leave_blank_to_keep') : '' }}">
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.app_secret_help') }}</p>
                        @if($errors->has('app_secret'))<p class="help-block">{{ $errors->first('app_secret') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('verify_token') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.verify_token') }}</label>
                    <div class="col-sm-8">
                        <div class="input-group">
                            <input type="text" name="verify_token" id="verify_token" class="form-control" readonly
                                   value="{{ old('verify_token', $account->verify_token ?? $generatedToken ?? '') }}">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default" id="regen_token"
                                        title="{{ __('metawhatsapp::metawhatsapp.regenerate_token') }}">&#8635;</button>
                            </span>
                        </div>
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.verify_token_help') }}</p>
                    </div>
                </div>

                {{-- Secció 3: Webhook --}}
                <h4 style="margin-top:30px">{{ __('metawhatsapp::metawhatsapp.section_webhook') }}</h4>
                <hr style="margin-top:5px">

                <div class="form-group">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.webhook_url') }}</label>
                    <div class="col-sm-8">
                        <div class="input-group">
                            <input type="text" id="webhook_url" class="form-control" readonly value="{{ $webhookUrl }}">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default" id="copy_webhook"
                                        title="{{ __('metawhatsapp::metawhatsapp.copy') }}">&#128203;</button>
                            </span>
                        </div>
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.webhook_url_help') }}</p>
                    </div>
                </div>

                {{-- Secció 4: Recuperació de finestra expirada (MVP issue #2) --}}
                <h4 style="margin-top:30px">{{ __('metawhatsapp::metawhatsapp.section_template_recovery') }}</h4>
                <hr style="margin-top:5px">

                <div class="form-group{{ $errors->has('template_name') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_name') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="template_name" class="form-control" maxlength="512"
                               value="{{ old('template_name', $account->template_name ?? '') }}">
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.template_name_help') }}</p>
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.template_cost_warning') }}</p>
                        @if($errors->has('template_name'))<p class="help-block">{{ $errors->first('template_name') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('template_lang') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_lang') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="template_lang" class="form-control" maxlength="15"
                               value="{{ old('template_lang', $account->template_lang ?? '') }}"
                               placeholder="es_ES">
                        @if($errors->has('template_lang'))<p class="help-block">{{ $errors->first('template_lang') }}</p>@endif
                    </div>
                </div>

                <div class="form-group{{ $errors->has('template_threshold_minutes') ? ' has-error' : '' }}">
                    <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_threshold') }}</label>
                    <div class="col-sm-8">
                        <input type="number" name="template_threshold_minutes" class="form-control" min="1" max="1440"
                               value="{{ old('template_threshold_minutes', $account->template_threshold_minutes ?? 1435) }}">
                        <p class="help-block">{!! __('metawhatsapp::metawhatsapp.template_threshold_help') !!}</p>
                        @if($errors->has('template_threshold_minutes'))<p class="help-block">{{ $errors->first('template_threshold_minutes') }}</p>@endif
                    </div>
                </div>

                {{-- Multi-plantilla (issue #2, punts 2-4): fins a 5 files
                     estàtiques. Buida (sense id o sense idioma) es descarta
                     en desar; amb 0 files plenes, el compte cau al parell
                     template_name/template_lang de dalt. --}}
                <div class="form-group">
                    <div class="col-sm-8 col-sm-offset-4">
                        <p class="help-block">{{ __('metawhatsapp::metawhatsapp.templates_multi_help') }}</p>
                    </div>
                </div>
                @php $existingTemplates = old('templates', $account->templates ?? []); @endphp
                @for ($i = 0; $i < 5; $i++)
                    <div class="panel panel-default">
                        <div class="panel-body">
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_row_id') }}</label>
                                <div class="col-sm-8">
                                    <input type="text" name="templates[{{ $i }}][id]" class="form-control" maxlength="512"
                                           value="{{ $existingTemplates[$i]['id'] ?? '' }}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_lang') }}</label>
                                <div class="col-sm-8">
                                    <input type="text" name="templates[{{ $i }}][language]" class="form-control" maxlength="15"
                                           placeholder="es_ES" value="{{ $existingTemplates[$i]['language'] ?? '' }}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_row_display_name') }}</label>
                                <div class="col-sm-8">
                                    <input type="text" name="templates[{{ $i }}][display_name]" class="form-control" maxlength="190"
                                           value="{{ $existingTemplates[$i]['display_name'] ?? '' }}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.template_row_recovery_text') }}</label>
                                <div class="col-sm-8">
                                    <input type="text" name="templates[{{ $i }}][recovery_text]" class="form-control" maxlength="1000"
                                           value="{{ $existingTemplates[$i]['recovery_text'] ?? '' }}">
                                </div>
                            </div>
                        </div>
                    </div>
                @endfor
                @if($errors->has('templates') || $errors->has('templates.*'))
                    <div class="form-group">
                        <div class="col-sm-8 col-sm-offset-4">
                            <p class="help-block">{{ $errors->first('templates') ?: $errors->first('templates.*') }}</p>
                        </div>
                    </div>
                @endif

                {{-- Secció 5: Bústia associada (només en alta; immutable en edició) --}}
                @if(!$account)
                    <h4 style="margin-top:30px">{{ __('metawhatsapp::metawhatsapp.section_mailbox') }}</h4>
                    <hr style="margin-top:5px">

                    <div class="form-group">
                        <div class="col-sm-8 col-sm-offset-4">
                            <label class="radio-inline" style="display:block;margin-bottom:8px">
                                <input type="radio" name="mailbox_mode" value="new"
                                       {{ old('mailbox_mode', 'new') === 'new' ? 'checked' : '' }}>
                                {{ __('metawhatsapp::metawhatsapp.mailbox_mode_new') }}
                            </label>
                            <label class="radio-inline" style="display:block;margin-left:0">
                                <input type="radio" name="mailbox_mode" value="existing"
                                       {{ old('mailbox_mode') === 'existing' ? 'checked' : '' }}
                                       {{ $mailboxes->isEmpty() ? 'disabled' : '' }}>
                                {{ __('metawhatsapp::metawhatsapp.mailbox_mode_existing') }}
                                @if($mailboxes->isEmpty())
                                    <span class="text-muted">({{ __('metawhatsapp::metawhatsapp.no_mailboxes_short') }})</span>
                                @endif
                            </label>
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('mailbox_name') ? ' has-error' : '' }}" id="mailbox_new_group">
                        <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.mailbox_name') }}</label>
                        <div class="col-sm-8">
                            <input type="text" name="mailbox_name" id="mailbox_name" class="form-control" maxlength="100"
                                   value="{{ old('mailbox_name', '') }}">
                            <p class="help-block">{{ __('metawhatsapp::metawhatsapp.mailbox_name_help') }}</p>
                            @if($errors->has('mailbox_name'))<p class="help-block">{{ $errors->first('mailbox_name') }}</p>@endif
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('mailbox_id') ? ' has-error' : '' }}" id="mailbox_existing_group" style="display:none">
                        <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.mailbox') }}</label>
                        <div class="col-sm-8">
                            <select name="mailbox_id" class="form-control">
                                <option value="">- {{ __('metawhatsapp::metawhatsapp.select_mailbox') }} -</option>
                                @foreach($mailboxes as $mb)
                                    <option value="{{ $mb->id }}" {{ old('mailbox_id') == $mb->id ? 'selected' : '' }}>
                                        {{ $mb->name }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="help-block">{{ __('metawhatsapp::metawhatsapp.mailbox_existing_help') }}</p>
                            @if($errors->has('mailbox_id'))<p class="help-block">{{ $errors->first('mailbox_id') }}</p>@endif
                        </div>
                    </div>
                @else
                    <div class="form-group">
                        <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.mailbox') }}</label>
                        <div class="col-sm-8">
                            <p class="form-control-static">
                                {{ $account->mailbox->name ?? __('metawhatsapp::metawhatsapp.mailbox_unlinked') }}
                            </p>
                        </div>
                    </div>
                @endif

                <div class="form-group" style="margin-top:30px">
                    <div class="col-sm-8 col-sm-offset-4">
                        <button type="submit" class="btn btn-primary">{{ __('metawhatsapp::metawhatsapp.save') }}</button>
                        <a href="{{ route('metawhatsapp.settings') }}" class="btn btn-default">{{ __('metawhatsapp::metawhatsapp.cancel') }}</a>
                    </div>
                </div>
            </form>

            @if($account)
            <div class="panel panel-default" style="margin-top:20px">
                <div class="panel-heading">{{ __('metawhatsapp::metawhatsapp.health_snapshot_title') }}</div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('metawhatsapp.test_connection', $account->id) }}" style="margin-bottom:15px; display:inline-block">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-default">{{ __('metawhatsapp::metawhatsapp.test_connection_button') }}</button>
                    </form>
                    <form method="POST" action="{{ route('metawhatsapp.subscribe_webhook', $account->id) }}" style="margin-bottom:15px; display:inline-block">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-default">{{ __('metawhatsapp::metawhatsapp.webhook_subscribe_button') }}</button>
                    </form>

                    <div class="form-horizontal">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.health_last_inbound') }}</label>
                            <div class="col-sm-8">
                                <p class="form-control-static">
                                    @if($healthSnapshot['last_inbound'])
                                        {{ $healthSnapshot['last_inbound']->created_at->format('Y-m-d H:i') }}
                                    @else
                                        {{ __('metawhatsapp::metawhatsapp.health_never') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.health_last_outbound') }}</label>
                            <div class="col-sm-8">
                                <p class="form-control-static">
                                    @if($healthSnapshot['last_outbound'])
                                        {{ $healthSnapshot['last_outbound']->created_at->format('Y-m-d H:i') }}
                                        - {{ __('metawhatsapp::metawhatsapp.health_last_status') }}: {{ $healthSnapshot['last_outbound']->status }}
                                    @else
                                        {{ __('metawhatsapp::metawhatsapp.health_never') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.health_last_error') }}</label>
                            <div class="col-sm-8">
                                <p class="form-control-static">
                                    @if($healthSnapshot['last_error'])
                                        {{ $healthSnapshot['last_error']->error_code }} - {{ $healthSnapshot['last_error']->created_at->format('Y-m-d H:i') }}
                                    @else
                                        {{ __('metawhatsapp::metawhatsapp.health_never') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.health_last_reactivation') }}</label>
                            <div class="col-sm-8">
                                <p class="form-control-static">
                                    @if($account->reactivated_at)
                                        {{ $account->reactivated_at->format('Y-m-d H:i') }}
                                        @if($account->reactivatedBy)
                                            - {{ $account->reactivatedBy->getFullName() }}
                                        @endif
                                    @else
                                        {{ __('metawhatsapp::metawhatsapp.health_never') }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

<script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
(function () {
    function mwaInit() {
    // Regenerar el token de verificació (CSPRNG del navegador).
    var regen = document.getElementById('regen_token');
    if (regen) {
        regen.addEventListener('click', function () {
            @if($account)
            if (!confirm(@json(__('metawhatsapp::metawhatsapp.verify_token_change_warning')))) {
                return;
            }
            @endif
            var bytes = new Uint8Array(32);
            crypto.getRandomValues(bytes);
            document.getElementById('verify_token').value = Array.prototype.map.call(bytes, function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('');
        });
    }

    // Copiar la URL del webhook.
    var copyBtn = document.getElementById('copy_webhook');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('webhook_url');
            navigator.clipboard.writeText(input.value).then(function () {
                copyBtn.textContent = '✓';
                setTimeout(function () { copyBtn.innerHTML = '&#128203;'; }, 1500);
            });
        });
    }

    // Alternar nova bústia / bústia existent.
    var radios = document.querySelectorAll('input[name="mailbox_mode"]');
    var newGroup = document.getElementById('mailbox_new_group');
    var existingGroup = document.getElementById('mailbox_existing_group');
    function toggleMailboxMode() {
        var mode = document.querySelector('input[name="mailbox_mode"]:checked');
        if (!mode || !newGroup || !existingGroup) { return; }
        newGroup.style.display = (mode.value === 'new') ? '' : 'none';
        existingGroup.style.display = (mode.value === 'existing') ? '' : 'none';
    }
    radios.forEach(function (r) { r.addEventListener('change', toggleMailboxMode); });
    toggleMailboxMode();

    @if($account)
    // Avís si es canvia el Phone Number ID d'un compte existent (spec §3.2):
    // el webhook deixaria de resoldre aquest compte fins a actualitzar Meta.
    var pniInput = document.querySelector('input[name="phone_number_id"]');
    var pniOriginal = @json($account->phone_number_id);
    var mwaForm = document.querySelector('form.form-horizontal');
    if (pniInput && mwaForm) {
        mwaForm.addEventListener('submit', function (e) {
            if (pniInput.value !== pniOriginal
                && !confirm(@json(__('metawhatsapp::metawhatsapp.phone_number_id_change_warning')))) {
                e.preventDefault();
            }
        });
    }
    @endif

    // Pre-omplir el nom de la bústia amb el nom del canal.
    var channelName = document.querySelector('input[name="name"]');
    var mailboxName = document.getElementById('mailbox_name');
    if (channelName && mailboxName) {
        channelName.addEventListener('input', function () {
            if (!mailboxName.dataset.touched) {
                mailboxName.value = channelName.value;
            }
        });
        mailboxName.addEventListener('input', function () {
            mailboxName.dataset.touched = '1';
        });
    }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mwaInit);
    } else {
        mwaInit();
    }
})();
</script>
@endsection
