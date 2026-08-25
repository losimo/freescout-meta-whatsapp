{{-- Banner de finestra de servei expirada (estimació segons el llindar
     operatiu del compte; la regla real de 24 h la decideix Meta). --}}
<div class="alert alert-warning" style="margin: 10px 15px 0;">
    <p>{{ __('metawhatsapp::metawhatsapp.window_expired_notice') }}</p>

    @php $templates = $account->getTemplateList(); @endphp
    @if (!($accountActive ?? true))
        {{-- Canal aturat: cap botó, perquè res del que cliqui l'agent
             sortirà. Se'l dirigeix a on es pot reactivar. --}}
        <p style="margin-top: 8px;">
            <a href="{{ route('metawhatsapp.edit', ['id' => $account->id]) }}">
                {{ __('metawhatsapp::metawhatsapp.account_inactive_notice') }}
            </a>
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
        {{-- Sense plantilla configurada: dirigir l'admin a la configuració. --}}
        <p style="margin-top: 8px;">
            <a href="{{ route('metawhatsapp.edit', ['id' => $account->id]) }}">
                {{ __('metawhatsapp::metawhatsapp.template_not_configured') }}
            </a>
        </p>
    @else
        {{-- Contacte només amb BSUID (sense telèfon): fase 2b. --}}
        <p style="margin-top: 8px;">{{ __('metawhatsapp::metawhatsapp.template_no_phone_notice') }}</p>
    @endif

    @if ($phone)
        {{-- Picker dinàmic (issue #2, punt 2 complet): complementa la llista
             estàtica de dalt, no la substitueix: llista EN VIU totes les
             plantilles APPROVED del WABA, amb variables. --}}
        <p style="margin-top: 8px;">
            <a href="{{ route('metawhatsapp.browse_templates', ['id' => $conversation->id]) }}">
                {{ __('metawhatsapp::metawhatsapp.browse_templates_link') }}
            </a>
        </p>
    @endif
</div>
