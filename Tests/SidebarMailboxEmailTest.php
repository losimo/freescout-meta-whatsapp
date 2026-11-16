<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Mailbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Issue #45. The line under the mailbox name in the left sidebar
 * (`.sidebar-title-email`) used to be hidden with `display: none`, which took
 * it out of the flow and collapsed `.sidebar-title` from 51.8px to 24.4px.
 * Everything below shifted up by 27px and the hover icon of the official
 * Mailbox Icons module ended up covered.
 *
 * These tests go through the HTTP door rather than calling the CSS-building
 * methods, because a method can be perfect and never injected, which is the
 * shape of mistake that has already cost this module a release.
 *
 * What is NOT covered here: the JavaScript half. Public/js/sidebar-phone.js is
 * the piece that actually writes the number into the element and reveals it,
 * and this module has no JS test harness. The PHP side proves that the number
 * and the rule reach the page, in the right place; that the browser then paints
 * the number where the address used to be has only been checked by eye.
 */
class SidebarMailboxEmailTest extends TestCase
{
    use DatabaseTransactions;

    /** A mailbox with no WhatsApp account, which must stay untouched. */
    private function plainMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->name       = 'PHPUnit plain';
        $mailbox->email      = 'plain-' . uniqid() . '@example.com';
        $mailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
        $mailbox->in_server  = '';
        $mailbox->out_server = '';
        $mailbox->save();

        return $mailbox;
    }

    private function mailboxPage(int $mailboxId): string
    {
        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $mailboxId));

        $response->assertStatus(200);

        return $response->getContent();
    }

    /**
     * The JavaScript the page actually loads, read back from disk.
     *
     * The core runs every file registered through the `javascripts` filter
     * into Minify::javascript(), so the page carries one combined bundle URL
     * and never the module's own path. Asserting on the filter's array would
     * prove the module asks nicely and say nothing about whether the file is
     * published and served, which is the half that has broken before.
     */
    private function servedJavascript(string $html): string
    {
        preg_match_all('#<script[^>]+src="([^"]+)"#', $html, $matches);
        $this->assertNotEmpty($matches[1], 'the page loads no scripts at all');

        $served = '';
        foreach ($matches[1] as $src) {
            $path = public_path(parse_url($src, PHP_URL_PATH));
            if (is_file($path)) {
                $served .= file_get_contents($path);
            }
        }

        return $served;
    }

    /**
     * Every inline <style> block of the page, which is where this module
     * writes its rules. Used by the regression test below so that the guard
     * does not depend on the exact spacing of one hand-written string.
     */
    private function inlineStyleBlocks(string $html): array
    {
        preg_match_all('#<style[^>]*>(.*?)</style>#s', $html, $matches);

        return $matches[1];
    }

    /**
     * The feature: the number the reader actually wants to see arrives from
     * the server, carrying the right value.
     */
    public function test_the_account_phone_reaches_the_page_of_a_whatsapp_mailbox()
    {
        $account = $this->createTestAccount();

        $this->assertStringContainsString(
            '<meta name="metawhatsapp-sidebar-phone" content="' . $account->phone_number . '">',
            $this->mailboxPage($account->mailbox_id),
            'the sidebar has nothing to put where the technical address was'
        );
    }

    /**
     * The number has to be in the <head>, before the body opens. Sent at the
     * end of the page it would arrive after the sidebar has been painted, and
     * since the script waits for it the element would stay empty for longer
     * than it has to (issue #17 is the same mistake in the other direction).
     */
    public function test_the_phone_arrives_before_the_body()
    {
        $account = $this->createTestAccount();
        $html    = $this->mailboxPage($account->mailbox_id);

        $metaPosition = strpos($html, 'name="metawhatsapp-sidebar-phone"');
        $bodyPosition = stripos($html, '<body');

        $this->assertNotFalse($metaPosition);
        $this->assertNotFalse($bodyPosition);
        $this->assertLessThan(
            $bodyPosition,
            $metaPosition,
            'the phone number is sent after the body opens'
        );
    }

    /**
     * The fix for the collapse itself: the element is hidden in a way that
     * keeps its box, and the rule reaches the <head> so the technical address
     * is never painted, not even for a frame.
     */
    public function test_the_sidebar_address_is_hidden_without_losing_its_box()
    {
        $account = $this->createTestAccount();
        $html    = $this->mailboxPage($account->mailbox_id);

        $rulePosition = strpos($html, '.sidebar-title-email { visibility: hidden; }');
        $bodyPosition = stripos($html, '<body');

        $this->assertNotFalse(
            $rulePosition,
            'the technical address is not hidden at all, or not with visibility'
        );
        $this->assertNotFalse($bodyPosition);
        $this->assertLessThan(
            $bodyPosition,
            $rulePosition,
            'the rule is injected after the body opens, so the technical address flashes first'
        );
    }

    /**
     * The script is the half that puts the number in the element, so it has
     * to reach the browser. The number and the rule can both be perfect and
     * the line would still stay empty if the file were never served.
     */
    public function test_the_sidebar_script_reaches_the_page()
    {
        $account = $this->createTestAccount();
        $html    = $this->mailboxPage($account->mailbox_id);

        $this->assertStringContainsString(
            'metawhatsapp-sidebar-phone',
            $this->servedJavascript($html),
            'the script that writes the phone number is never served to the browser'
        );
    }

    /**
     * And nowhere else. The filter is global, so a registration that forgot
     * to ask which mailbox this is would ship the script to every page of the
     * install.
     */
    public function test_the_sidebar_script_is_absent_from_a_mailbox_without_whatsapp()
    {
        $this->createTestAccount();
        $plain = $this->plainMailbox();

        $this->assertStringNotContainsString(
            'metawhatsapp-sidebar-phone',
            $this->servedJavascript($this->mailboxPage($plain->id)),
            'the script is served on mailboxes that have no WhatsApp account'
        );
    }

    /**
     * The regression this issue reported. `display: none` on that element is
     * what collapsed the header by 27px, so no rule this module writes may
     * declare `display` on it again, whatever the spacing of the declaration.
     */
    public function test_no_rule_takes_the_sidebar_address_out_of_the_flow()
    {
        $account = $this->createTestAccount();
        $html    = $this->mailboxPage($account->mailbox_id);

        $blocks = $this->inlineStyleBlocks($html);
        $this->assertNotEmpty($blocks, 'the page carries no inline styles at all, so this guard proves nothing');

        foreach ($blocks as $block) {
            if (strpos($block, '.sidebar-title-email') === false) {
                continue;
            }
            $this->assertStringNotContainsString(
                'display',
                $block,
                'a rule declares display on .sidebar-title-email, which collapses .sidebar-title by 27px'
            );
        }
    }

    /**
     * The Cc/Bcc toggle was hidden by the same declaration as the sidebar
     * address and had to be split off from it. It is a different element with
     * a different problem (it belongs to e-mail, not to WhatsApp) and hiding
     * it outright is still right, so splitting must not have dropped it.
     */
    public function test_the_cc_toggle_is_still_hidden_outright()
    {
        $account = $this->createTestAccount();

        $this->assertStringContainsString(
            '#toggle-cc { display: none !important; }',
            $this->mailboxPage($account->mailbox_id),
            'splitting the rule lost the Cc/Bcc toggle'
        );
    }

    /**
     * The inverse, and the reason it matters: a WhatsApp account exists in
     * this install, so anything keyed on "some account exists" rather than on
     * this mailbox would reach every other mailbox in the install and hide a
     * real e-mail address from the people using it.
     */
    public function test_a_mailbox_without_whatsapp_is_untouched()
    {
        $this->createTestAccount();
        $plain = $this->plainMailbox();
        $html  = $this->mailboxPage($plain->id);

        $this->assertStringNotContainsString('metawhatsapp-sidebar-phone', $html);
        $this->assertStringNotContainsString('.sidebar-title-email', $html);
        $this->assertStringContainsString(
            $plain->email,
            $html,
            'the real e-mail address of a plain mailbox stopped reaching its sidebar'
        );
    }

    /**
     * An account with no number stored keeps the empty box and says nothing.
     * The column is NOT NULL, so this is the empty-string case rather than a
     * hypothetical one: a <meta> with an empty value would make the script
     * blank the line out loud instead of leaving it alone.
     */
    public function test_an_account_without_a_phone_sends_no_meta()
    {
        $account = $this->createTestAccount();
        $account->phone_number = '';
        $account->save();

        $html = $this->mailboxPage($account->mailbox_id);

        $this->assertStringNotContainsString('metawhatsapp-sidebar-phone', $html);
        // The address still has to go: it was never useful, and the box still
        // has to keep its height.
        $this->assertStringContainsString('.sidebar-title-email { visibility: hidden; }', $html);
    }

    /**
     * The empty state of a brand new channel. `conversations.chats` normally
     * redirects to the first chat, so it is easy to believe it is covered by
     * the conversation route. With no conversations yet it renders itself,
     * and that is the page somebody lands on right after configuring the
     * channel, which is the worst moment to show them
     * whatsapp-...@channel.internal and a sidebar 27px shorter than it
     * should be.
     */
    public function test_the_chats_page_of_a_channel_with_no_conversations_is_covered()
    {
        $account = $this->createTestAccount();

        $content = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $account->mailbox_id . '/chats'))
            ->getContent();

        $this->assertStringContainsString($account->phone_number, $content);
        $this->assertStringNotContainsString('.sidebar-title-email { display: none', $content);
    }
}
