{{--
    Coses que impedeixen que el mòdul funcioni i que no són del mòdul: el cron
    aturat, la cua en mode sync, el curl que falta, permisos. No les podem
    arreglar nosaltres, però sí dir-les, i aquesta és tota la seva raó de ser.

    La pitjor d'aquestes fallades no dona cap error enlloc: si ningú processa
    la cua, el webhook contesta 200 a Meta, la resposta de l'agent queda a la
    conversa com si hagués sortit, i les feines s'apilen en silenci.

    Parcial propi perquè es pugui pintar sol en un test, en comptes d'haver
    d'afirmar contra la pàgina sencera.
--}}
@if(!empty($environmentProblems))
    @foreach($environmentProblems as $problem)
        <div class="alert alert-{{ $problem['severity'] === 'problem' ? 'danger' : 'warning' }} metawhatsapp-env-notice"
             data-check="{{ $problem['key'] }}">
            <strong>{{ $problem['title'] }}</strong>
            <div>{{ $problem['detail'] }}</div>
        </div>
    @endforeach
@endif
