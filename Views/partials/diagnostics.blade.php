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

        <h4 style="margin-top:0">{{ __('metawhatsapp::metawhatsapp.diagnostics_section_log') }}</h4>

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
                           min="0" max="365" value="{{ $debug['retention'] }}">
                    <p class="help-block">{{ __('metawhatsapp::metawhatsapp.diagnostics_retention_help') }}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.account_events_title') }}</label>
                <div class="col-sm-8">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="account_events" value="1"
                                   {{ \Modules\MetaWhatsApp\Support\AccountEventLog::isEnabled() ? 'checked' : '' }}>
                            {{ __('metawhatsapp::metawhatsapp.account_events_enable') }}
                        </label>
                    </div>
                    <p class="help-block">{{ __('metawhatsapp::metawhatsapp.account_events_enable_help') }}</p>
                </div>
            </div>

            {{-- Issue #43. Who signs the module's automatic notes.

                 Read from the helper rather than from a view variable, like
                 the account events checkbox just above, so this partial can
                 still be rendered on its own in a test with only $debug.

                 The first option is the default, the installation's first
                 administrator (#68), and it is also what shows as selected
                 when the chosen user has been deleted since: the list only
                 offers live users, so the screen shows what actually happens
                 in a conversation. --}}
            @php($noteAuthorSelected = \Modules\MetaWhatsApp\Support\NoteAuthor::configuredUserId())
            <h4>{{ __('metawhatsapp::metawhatsapp.diagnostics_section_notes') }}</h4>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('metawhatsapp::metawhatsapp.note_author') }}</label>
                <div class="col-sm-8">
                    <select name="note_author" class="form-control" style="max-width:320px">
                        <option value="">{{ __('metawhatsapp::metawhatsapp.note_author_default') }}</option>
                        @foreach(\Modules\MetaWhatsApp\Support\NoteAuthor::selectableUsers() as $noteAuthorUser)
                            <option value="{{ $noteAuthorUser->id }}"
                                {{ $noteAuthorSelected === (int) $noteAuthorUser->id ? 'selected' : '' }}>
                                {{ $noteAuthorUser->getFullName() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="help-block">{{ __('metawhatsapp::metawhatsapp.note_author_help') }}</p>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-offset-4 col-sm-8">
                    <button type="submit" class="btn btn-primary">{{ __('metawhatsapp::metawhatsapp.save') }}</button>
                    <a href="{{ route('logs.app') }}" class="btn btn-link">{{ __('metawhatsapp::metawhatsapp.diagnostics_view_log') }}</a>
                    <a href="{{ route('metawhatsapp.account_events') }}" class="btn btn-link">{{ __('metawhatsapp::metawhatsapp.account_events_link') }}</a>
                    <a href="{{ route('metawhatsapp.erasure') }}" class="btn btn-link">{{ __('metawhatsapp::metawhatsapp.erasure_link') }}</a>
                </div>
            </div>
        </form>
    </div>
</div>
