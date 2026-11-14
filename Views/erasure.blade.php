@extends('layouts.app')

@section('title', __('metawhatsapp::metawhatsapp.erasure_title'))

@section('content')
<div class="section-heading">{{ __('metawhatsapp::metawhatsapp.erasure_title') }}</div>

<div class="container" style="margin-top:20px">
    <div class="row">
        <div class="col-xs-12">
            <p class="help-block">{{ __('metawhatsapp::metawhatsapp.erasure_help') }}</p>

            <form method="POST" action="{{ route('metawhatsapp.erasure_search') }}" class="form-inline" style="margin-bottom:20px">
                {{ csrf_field() }}
                <input type="text" name="identifier" class="form-control" style="min-width:280px"
                       value="{{ $identifier ?? '' }}"
                       placeholder="{{ __('metawhatsapp::metawhatsapp.erasure_identifier_placeholder') }}">
                <button type="submit" class="btn btn-default">{{ __('metawhatsapp::metawhatsapp.erasure_search_button') }}</button>
            </form>

            @if(!empty($receipt))
                <div class="panel panel-default">
                    <div class="panel-heading">{{ __('metawhatsapp::metawhatsapp.erasure_receipt_title') }}</div>
                    <div class="panel-body">
                        <div class="alert alert-warning">
                            {{ __('metawhatsapp::metawhatsapp.erasure_receipt_warning') }}
                        </div>

                        @if(empty($receipt['identifiers']))
                            <p>{{ __('metawhatsapp::metawhatsapp.erasure_nothing_to_erase') }}</p>
                        @else
                            {{-- Selectable as one block, so it can be copied in one go. --}}
                            <pre style="white-space:pre-wrap">{{ __('metawhatsapp::metawhatsapp.erasure_receipt_removed', [
                                'identifiers' => implode(', ', $receipt['identifiers']),
                                'messages'    => $receipt['messages'],
                                'channels'    => $receipt['channel_rows'],
                                'date'        => now()->format('Y-m-d H:i'),
                            ]) }}

{{ __('metawhatsapp::metawhatsapp.erasure_receipt_kept') }}

{{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_intro') }}
- {{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_conversations') }}
- {{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_customer') }}
- {{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_queue') }}
- {{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_debuglog') }}
- {{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_portfolios') }}</pre>
                        @endif
                    </div>
                </div>
            @endif

            @if(!empty($searched))
                @if(empty($summary))
                    <p>{{ __('metawhatsapp::metawhatsapp.erasure_no_results') }}</p>
                    {{-- A screen that exists to prove what is left cannot answer
                         "nothing" while the phone can still be the customer's
                         display name one click away. --}}
                    <div class="alert alert-info">
                        {{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_intro') }}
                        <ul style="margin-bottom:0">
                            <li>{{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_conversations') }}</li>
                            <li>{{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_customer') }}</li>
                            <li>{{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_queue') }}</li>
                            <li>{{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_debuglog') }}</li>
                            {{-- An empty search under one identifier is exactly what a
                                 second, unlinked Meta portfolio for the same person
                                 looks like, so this line belongs here too. --}}
                            <li>{{ __('metawhatsapp::metawhatsapp.erasure_receipt_remains_portfolios') }}</li>
                        </ul>
                    </div>
                @else
                    <table class="table table-condensed">
                        <tbody>
                            <tr>
                                <th>{{ __('metawhatsapp::metawhatsapp.erasure_col_identifiers') }}</th>
                                <td>{{ implode(', ', $summary['identifiers']) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('metawhatsapp::metawhatsapp.erasure_col_messages') }}</th>
                                <td>{{ $summary['messages'] }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('metawhatsapp::metawhatsapp.erasure_col_channels') }}</th>
                                <td>{{ implode(', ', $summary['channel_names']) ?: '-' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('metawhatsapp::metawhatsapp.erasure_col_first_last') }}</th>
                                <td>{{ $summary['first_at'] }} / {{ $summary['last_at'] }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('metawhatsapp::metawhatsapp.erasure_col_orphaned') }}</th>
                                <td>{{ $summary['orphaned'] }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <form method="POST" action="{{ route('metawhatsapp.erasure_erase') }}" class="mwa-erasure-form">
                        {{ csrf_field() }}
                        <input type="hidden" name="identifier" value="{{ $identifier }}">
                        <button type="submit" class="btn btn-danger">{{ __('metawhatsapp::metawhatsapp.erasure_erase_button') }}</button>
                        <span class="help-block">{{ __('metawhatsapp::metawhatsapp.erasure_erase_help') }}</span>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>

<script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
(function () {
    function mwaInit() {
        document.querySelectorAll('.mwa-erasure-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(@json(__('metawhatsapp::metawhatsapp.erasure_confirm')))) {
                    e.preventDefault();
                }
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mwaInit);
    } else {
        mwaInit();
    }
})();
</script>
@endsection
