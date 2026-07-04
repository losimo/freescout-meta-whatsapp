@extends('layouts.app')

@section('title_full', __('metawhatsapp::metawhatsapp.title'))

@section('content')
<div class="section-heading">
    {{ __('metawhatsapp::metawhatsapp.title') }}
    <div class="section-heading-actions">
        <a href="{{ route('metawhatsapp.create') }}" class="btn btn-primary">
            {{ __('metawhatsapp::metawhatsapp.add_account') }}
        </a>
    </div>
</div>

<div class="container" style="margin-top:20px">
    <div class="row">
        <div class="col-xs-12">
            @include('partials/flash_messages')

            @if($accounts->isEmpty())
                <div class="alert alert-info">
                    {{ __('metawhatsapp::metawhatsapp.no_accounts') }}
                </div>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ __('metawhatsapp::metawhatsapp.channel_name') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.phone_number') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.status') }}</th>
                            <th>{{ __('metawhatsapp::metawhatsapp.mailbox') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($accounts as $account)
                            <tr>
                                <td>{{ $account->name }}</td>
                                <td>{{ $account->phone_number }}</td>
                                <td>
                                    @if($account->getStatus() === 'active')
                                        <span class="text-success">&#9679; {{ __('metawhatsapp::metawhatsapp.active') }}</span>
                                    @elseif($account->getStatus() === 'inactive')
                                        <span class="text-muted">&#9675; {{ __('metawhatsapp::metawhatsapp.inactive') }}</span>
                                    @else
                                        <span class="text-warning">&#9888; {{ __('metawhatsapp::metawhatsapp.mailbox_unlinked') }}</span>
                                    @endif
                                </td>
                                <td>{{ $account->mailbox->name ?? '—' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('metawhatsapp.edit', $account->id) }}" class="btn btn-default btn-xs">
                                        {{ __('metawhatsapp::metawhatsapp.edit') }}
                                    </a>
                                    <form method="POST" action="{{ route('metawhatsapp.destroy', $account->id) }}"
                                          style="display:inline" class="mwa-delete-form">
                                        {{ csrf_field() }}
                                        {{ method_field('DELETE') }}
                                        <button type="submit" class="btn btn-default btn-xs text-danger">
                                            {{ __('metawhatsapp::metawhatsapp.delete') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

<script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
(function () {
    function mwaInit() {
        document.querySelectorAll('.mwa-delete-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(@json(__('metawhatsapp::metawhatsapp.delete_confirm')))) {
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
