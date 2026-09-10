{{--
    Finestra i retenció del registre detallat.

    Aquest fitxer guarda el contingut dels missatges i els telèfons dels
    clients, i és l'únic lloc on l'esborrat no arriba: un fitxer rotatiu no
    es pot reescriure quan s'elimina una conversa. Per això el text explica
    per què val la pena una finestra curta, i per això la decisió és de qui
    administra i no nostra.

    Partial per poder-la renderitzar sola en un test, com el core_notice.
--}}
<div class="panel panel-default" style="margin-top:20px">
    <div class="panel-heading">{{ __('metawhatsapp::metawhatsapp.diagnostics_title') }}</div>
    <div class="panel-body">

        <p class="help-block" style="margin-bottom:15px">
            {{ __('metawhatsapp::metawhatsapp.diagnostics_help') }}
        </p>

        @if($debug['forced_env'])
            <div class="alert alert-info">
                {{ __('metawhatsapp::metawhatsapp.diagnostics_forced_env') }}
            </div>
        @endif

        <div class="form-group">
            <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.diagnostics_state') }}</label>
            <div class="col-sm-8">
                <p class="form-control-static">
                    @if(!$debug['enabled'])
                        {{ __('metawhatsapp::metawhatsapp.diagnostics_off') }}
                    @elseif($debug['always'])
                        <span class="text-warning">{{ __('metawhatsapp::metawhatsapp.diagnostics_on_always') }}</span>
                    @elseif($debug['expires_at'])
                        <span class="text-warning">{{ __('metawhatsapp::metawhatsapp.diagnostics_on_until', ['date' => $debug['expires_at']->format('Y-m-d H:i')]) }}</span>
                    @else
                        <span class="text-warning">{{ __('metawhatsapp::metawhatsapp.diagnostics_on_always') }}</span>
                    @endif
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('metawhatsapp.diagnostics') }}" class="form-horizontal">
            {{ csrf_field() }}

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.diagnostics_window') }}</label>
                <div class="col-sm-8">
                    {{-- La primera opció no és "apagat" sinó "no ho toquis": l'estat
                         real el diu la línia de dalt, i aquest desplegable és una
                         acció. Si el valor per defecte fos "apagat", qui vingués a
                         canviar només els dies de retenció apagaria el registre
                         sense adonar-se'n. --}}
                    <select name="debug_window" class="form-control" style="max-width:320px">
                        <option value="">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_keep') }}</option>
                        <option value="off">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_off') }}</option>
                        <option value="1">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_days', ['days' => 1]) }}</option>
                        <option value="3">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_days', ['days' => 3]) }}</option>
                        <option value="7">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_days', ['days' => 7]) }}</option>
                        <option value="30">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_days', ['days' => 30]) }}</option>
                        <option value="always">{{ __('metawhatsapp::metawhatsapp.diagnostics_window_always') }}</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.diagnostics_retention') }}</label>
                <div class="col-sm-8">
                    <input type="number" name="debug_retention" class="form-control" style="max-width:120px"
                           min="1" max="90" value="{{ $debug['retention'] }}">
                    <p class="help-block">{{ __('metawhatsapp::metawhatsapp.diagnostics_retention_help') }}</p>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-offset-4 col-sm-8">
                    <button type="submit" class="btn btn-primary">{{ __('metawhatsapp::metawhatsapp.save') }}</button>
                    <a href="{{ route('logs.app') }}" class="btn btn-link">{{ __('metawhatsapp::metawhatsapp.diagnostics_view_log') }}</a>
                </div>
            </div>
        </form>
    </div>
</div>
