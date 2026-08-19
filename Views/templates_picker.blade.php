@extends('layouts.app')

@section('title_full', __('metawhatsapp::metawhatsapp.templates_picker_title'))

@section('content')
<div class="section-heading">
    {{ __('metawhatsapp::metawhatsapp.templates_picker_title') }}
</div>

<div class="container" style="margin-top:20px">
    <div class="row">
        <div class="col-xs-12 col-md-8 col-md-offset-2">
            @include('partials/flash_messages')

            <p>
                <a href="{{ url()->previous() }}">{{ __('metawhatsapp::metawhatsapp.templates_picker_back') }}</a>
            </p>

            @if($errors->has('send_template'))
                <div class="alert alert-danger">{{ $errors->first('send_template') }}</div>
            @endif

            @if(!$result['ok'])
                <div class="alert alert-danger">
                    {{ __('metawhatsapp::metawhatsapp.templates_picker_fetch_error', ['error' => $result['error_message'] ?: '-']) }}
                </div>
            @elseif(empty($result['templates']))
                <p class="text-muted">{{ __('metawhatsapp::metawhatsapp.templates_picker_empty') }}</p>
            @else
                @foreach($result['templates'] as $template)
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <strong>{{ $template['name'] }}</strong>
                            <span class="text-muted">({{ $template['language'] }}, {{ $template['category'] }})</span>
                        </div>
                        <div class="panel-body">
                            @if($template['body'])
                                <p style="font-style:italic;white-space:pre-wrap">{{ $template['body'] }}</p>
                            @endif

                            <form method="POST" action="{{ route('metawhatsapp.send_dynamic_template', ['id' => $conversation->id]) }}"
                                onsubmit="if (this.dataset.sent) return false; this.dataset.sent = '1'; this.querySelector('button[type=submit]').disabled = true;">
                                {{ csrf_field() }}
                                <input type="hidden" name="template_name" value="{{ $template['name'] }}">
                                <input type="hidden" name="template_language" value="{{ $template['language'] }}">

                                @for($i = 1; $i <= $template['variable_count']; $i++)
                                    <div class="form-group">
                                        <label>{{ __('metawhatsapp::metawhatsapp.template_variable_label', ['n' => $i]) }}</label>
                                        <input type="text" name="variables[]" class="form-control" required maxlength="500">
                                    </div>
                                @endfor

                                <button type="submit" class="btn btn-default btn-sm">
                                    <i class="glyphicon glyphicon-send"></i>
                                    {{ __('metawhatsapp::metawhatsapp.template_send_button') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>
@endsection
