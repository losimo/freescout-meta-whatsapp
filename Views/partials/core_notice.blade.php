{{--
    FreeScout does not check the `freescout_version` of a community module, so
    an install older than this module expects loads it without a word. The
    module keeps working; this is the only place an administrator finds out.
    Kept as a partial so it can be rendered on its own in a test, rather than
    asserting against a whole page.
--}}
@if(!empty($coreOutdated))
    <div class="alert alert-warning metawhatsapp-core-notice">
        {{ __('metawhatsapp::metawhatsapp.core_outdated', ['current' => $coreVersion, 'minimum' => $coreMinimum]) }}
    </div>
@endif
