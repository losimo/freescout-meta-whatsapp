@extends('layouts.app')

@section('title', __('metawhatsapp::metawhatsapp.account_events_title'))

@section('content')
<div class="section-heading">{{ __('metawhatsapp::metawhatsapp.account_events_title') }}</div>

<div class="container" style="margin-top:20px">
    <div class="row">
        <div class="col-xs-12">
            <p class="help-block">{{ __('metawhatsapp::metawhatsapp.account_events_help') }}</p>

            @if($events->isEmpty())
                <p>{{ __('metawhatsapp::metawhatsapp.account_events_empty') }}</p>
            @else
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>{{ __('metawhatsapp::metawhatsapp.account_events_channel') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.account_events_when') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.account_events_type') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.account_events_severity') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.account_events_details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($events as $event)
                            <tr>
                                <td>{{ $accountNames[$event->account_id] ?? ('#' . $event->account_id) }}</td>
                                <td>{{ $event->created_at ? $event->created_at->format('Y-m-d H:i') : '' }}</td>
                                <td>{{ $event->event_type }}</td>
                                <td>{{ $event->severity }}</td>
                                {{-- This value can carry strings Meta sent us, not only our own
                                     JSON. It must stay escaped, so keep it in {{ }} and never
                                     switch it to {!! !!}. --}}
                                <td><code>{{ $event->details }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
@endsection
