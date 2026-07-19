{{-- Banner de finestra de servei expirada (estimació segons el llindar
     operatiu del compte; la regla real de 24 h la decideix Meta). --}}
<div class="alert alert-warning" style="margin: 10px 15px 0;">
    <p>{{ __('metawhatsapp::metawhatsapp.window_expired_notice') }}</p>

    @if ($account->template_name && $account->template_lang && $phone)
        {{-- Cas normal: plantilla configurada (nom i idioma, com exigeix el
             guard del controlador) i telèfon resoluble. --}}
        {{-- Guarda al submit del formulari, no al click del botó: un
             onclick que desactiva el botó pot arribar a empassar-se el
             submit natiu en alguns motors i, combinat amb un submit()
             imperatiu, provocar dos POST amb un sol clic. --}}
        <form method="POST" action="{{ route('metawhatsapp.send_template', ['id' => $conversation->id]) }}" style="margin-top: 8px;"
            onsubmit="if (this.dataset.sent) return false; this.dataset.sent = '1'; this.querySelector('button[type=submit]').disabled = true;">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default btn-sm">
                <i class="glyphicon glyphicon-send"></i>
                {{ __('metawhatsapp::metawhatsapp.send_template_button', ['name' => $account->template_name]) }}
            </button>
        </form>
    @elseif (!$account->template_name || !$account->template_lang)
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
</div>
