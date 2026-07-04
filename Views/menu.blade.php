<li class="{{ \Request::is('meta-whatsapp*') ? 'active' : '' }}">
    <a href="{{ route('metawhatsapp.settings') }}">
        <i class="glyphicon glyphicon-phone"></i> {{ __('metawhatsapp::metawhatsapp.menu_label') }}
    </a>
</li>
