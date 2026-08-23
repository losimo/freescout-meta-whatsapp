<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Mailbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider;

class DashboardCounterFixTest extends TestCase
{
    use DatabaseTransactions;

    protected function css(): string
    {
        $provider = new MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'dashboardCounterFixCss');
        $method->setAccessible(true);

        return $method->invoke($provider);
    }

    public function test_no_genera_css_si_no_hi_ha_cap_compte_whatsapp()
    {
        // L'entorn de test pot tenir comptes WhatsApp d'altres fixtures;
        // s'esborren dins la transacció del test (rollback automàtic en
        // acabar, no afecta altres tests ni dades persistents).
        WhatsAppAccount::query()->delete();

        $this->assertSame('', $this->css());
    }

    public function test_genera_selector_per_a_cada_bustia_whatsapp()
    {
        $account1 = $this->createTestAccount();
        $account2 = $this->createTestAccount();

        $css = $this->css();

        foreach ([$account1, $account2] as $account) {
            $this->assertStringContainsString(
                '.dash-card.dash-card-inactive[data-mailbox-id="' . $account->mailbox_id . '"] .dash-card-list',
                $css
            );
            $this->assertStringContainsString(
                '.dash-card.dash-card-inactive[data-mailbox-id="' . $account->mailbox_id . '"] .dash-card-inactive-content',
                $css
            );
        }

        // Cada grup de selectors va seguit d'una única regla de display,
        // compartida per totes les bústies del grup (no un display per selector).
        $this->assertStringContainsString(' .dash-card-list { display: block; }', $css);
        $this->assertStringContainsString(' .dash-card-inactive-content { display: none; }', $css);
    }

    public function test_no_afecta_bustis_sense_compte_whatsapp()
    {
        $whatsappAccount = $this->createTestAccount();

        $plainMailbox = new Mailbox();
        $plainMailbox->name       = 'PHPUnit Email';
        $plainMailbox->email      = 'phpunit-email-' . mt_rand(100000, 999999) . '@example.com';
        $plainMailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
        $plainMailbox->in_server  = '';
        $plainMailbox->out_server = '';
        $plainMailbox->save();

        $css = $this->css();

        $this->assertStringContainsString('data-mailbox-id="' . $whatsappAccount->mailbox_id . '"', $css);
        $this->assertStringNotContainsString('data-mailbox-id="' . $plainMailbox->id . '"', $css);
    }
}
