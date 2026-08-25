{{-- Banner de finestra de servei expirada (estimació segons el llindar
     operatiu del compte; la regla real de 24 h la decideix Meta), o de
     canal aturat, que té prioritat perquè amb el compte inactiu la
     finestra és irrellevant: no sortirà res igualment. --}}
<div class="alert alert-warning" style="margin: 10px 15px 0;">
    <p>
        @if (!($accountActive ?? true))
            {{ __('metawhatsapp::metawhatsapp.account_inactive_banner') }}
        @else
            {{ __('metawhatsapp::metawhatsapp.window_expired_notice') }}
        @endif
    </p>

    @php
        $templates = $account->getTemplateList();
        // La configuració del canal és només per a administradors: enllaçar-hi
        // un agent el porta a un 403. Es pregunta abans d'oferir cap enllaç.
        $canConfigure = auth()->user() && auth()->user()->isAdmin();
    @endphp
    @if (!($accountActive ?? true))
        {{-- Canal aturat: cap botó, perquè res del que cliqui l'agent
             sortirà. Qui ho pot arreglar hi va; qui no, sap a qui dir-ho. --}}
        <p style="margin-top: 8px;">
            @if ($canConfigure)
                <a href="{{ route('metawhatsapp.edit', ['id' => $account->id]) }}">
                    {{ __('metawhatsapp::metawhatsapp.account_inactive_notice') }}
                </a>
            @else
                {{ __('metawhatsapp::metawhatsapp.account_inactive_notice_agent') }}
            @endif
        </p>
    @elseif (!empty($templates) && $phone)
        {{-- Un botó per plantilla configurada (issue #2, punt 2: multi-idioma).
             Amb una sola plantilla, template_id/template_language no calen:
             el controlador ja fa fallback a l'única disponible. --}}
        {{-- Guarda al submit del formulari, no al click del botó: un
             onclick que desactiva el botó pot arribar a empassar-se el
             submit natiu en alguns motors i, combinat amb un submit()
             imperatiu, provocar dos POST amb un sol clic. --}}
        @foreach ($templates as $template)
            <form method="POST" action="{{ route('metawhatsapp.send_template', ['id' => $conversation->id]) }}"
                style="margin-top: 8px; display: inline-block; margin-right: 6px;"
                onsubmit="if (this.dataset.sent) return false; this.dataset.sent = '1'; this.querySelector('button[type=submit]').disabled = true;">
                {{ csrf_field() }}
                <input type="hidden" name="template_id" value="{{ $template['id'] }}">
                <input type="hidden" name="template_language" value="{{ $template['language'] }}">
                <button type="submit" class="btn btn-default btn-sm">
                    <i class="glyphicon glyphicon-send"></i>
                    {{ __('metawhatsapp::metawhatsapp.send_template_button', ['name' => $template['display_name']]) }}
                </button>
            </form>
        @endforeach
    @elseif (empty($templates))
        {{-- Sense plantilla configurada. Mateix criteri: només enllaça qui
             pot entrar-hi. Això venia de la v1.3.0 i portava els agents a
             un 403 des del principi. --}}
        <p style="margin-top: 8px;">
            @if ($canConfigure)
                <a href="{{ route('metawhatsapp.edit', ['id' => $account->id]) }}">
                    {{ __('metawhatsapp::metawhatsapp.template_not_configured') }}
                </a>
            @else
                {{ __('metawhatsapp::metawhatsapp.template_not_configured_agent') }}
            @endif
        </p>
    @else
        {{-- Contacte només amb BSUID (sense telèfon): fase 2b. --}}
        <p style="margin-top: 8px;">{{ __('metawhatsapp::metawhatsapp.template_no_phone_notice') }}</p>
    @endif

    @php
        // Els dos mecanismes no es mostren mai alhora (issue #2): amb
        // plantilles configurades l'agent veu només aquells botons, i
        // sense cap configurada veu només el selector. Tenir-los tots dos
        // feia que uns agents fessin una cosa i altres una altra dins del
        // mateix equip.
        //
        // Excepció per a administradors: la interfície de WhatsApp Manager
        // és incòmoda i el selector és una manera ràpida de consultar què
        // té Meta aprovat de debò, amb quin nom i en quin idioma.
        $isAdmin        = auth()->user() && auth()->user()->isAdmin();
        $showPicker     = $phone && ($isAdmin || empty($templates));
    @endphp
    @if ($showPicker && ($accountActive ?? true))
        <p style="margin-top: 8px;">
            <a href="{{ route('metawhatsapp.browse_templates', ['id' => $conversation->id]) }}">
                {{ __('metawhatsapp::metawhatsapp.browse_templates_link') }}
            </a>
            @if ($isAdmin && !empty($templates))
                <span class="help-block" style="margin:2px 0 0">{{ __('metawhatsapp::metawhatsapp.browse_templates_admin_only') }}</span>
            @endif
        </p>
    @endif
</div>
