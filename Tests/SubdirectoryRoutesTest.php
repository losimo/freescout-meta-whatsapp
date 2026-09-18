<?php

namespace Modules\MetaWhatsApp\Tests;

/**
 * Issue #33. FreeScout installed below the domain root (for example at
 * /tickets) registers its own routes inside Route::prefix(Helper::getSubdirectory())
 * in app/Providers/RouteServiceProvider.php. A module's routes.php is required
 * from start.php, outside that group, so it gets no such prefix.
 *
 * On a root install getSubdirectory() returns an empty string and nothing
 * differs, which is why this went unnoticed. On a subdirectory install every
 * route of this module is unreachable: the settings screen 404s, and so does
 * the webhook, so Meta's deliveries never arrive either.
 *
 * SavedReplies, in this same repository, already carries the prefix.
 */
class SubdirectoryRoutesTest extends TestCase
{
    protected function registeredUris(): array
    {
        $uris = [];

        foreach (\Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        return $uris;
    }

    public function test_the_module_routes_carry_the_subdirectory_like_the_core_ones_do()
    {
        config(['app.url' => 'https://freescout.local/tickets']);
        $this->assertEquals('tickets', \Helper::getSubdirectory());

        // Registers a second copy of the module's routes, now that the app
        // believes it lives in a subdirectory. The app is rebuilt per test.
        require __DIR__ . '/../Http/routes.php';

        $uris = $this->registeredUris();

        $this->assertContains(
            'tickets/meta-whatsapp/settings',
            $uris,
            'The settings screen is unreachable on a subdirectory install.'
        );
        $this->assertContains(
            'tickets/meta-whatsapp/webhook',
            $uris,
            'The webhook is unreachable on a subdirectory install, so Meta cannot deliver.'
        );
    }
}
