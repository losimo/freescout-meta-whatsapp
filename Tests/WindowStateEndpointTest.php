<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

class WindowStateEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInbound($account, int $conversationId, $createdAt): WhatsAppMessage
    {
        $message = new WhatsAppMessage();
        $message->account_id      = $account->id;
        $message->conversation_id = $conversationId;
        $message->direction       = WhatsAppMessage::DIRECTION_INBOUND;
        // wamid is unique per row; a fixed value would collide the second
        // time a test (e.g. the "later reply" one) inserts a second
        // inbound message for the same conversation id.
        $message->wamid            = 'wamid.window-state-' . $conversationId . '-' . uniqid();
        $message->status            = WhatsAppMessage::STATUS_RECEIVED;
        $message->save();
        $message->created_at = $createdAt;
        $message->save();

        return $message;
    }

    /**
     * $account is optional and only needed when the test will exercise the
     * viewCached policy for a NON-admin user: that path reads
     * $conversation->mailbox->users_cached, which fatals on a null
     * mailbox_id. Admin-only tests short-circuit the policy via
     * isAdmin() before ever touching mailbox, so they don't need it
     * (matches the fakeConversation() used by the 404 test here and by
     * WindowClockTest's own private helper).
     */
    private function fakeConversation(int $id, $account = null): \App\Conversation
    {
        $conversation = new \App\Conversation();
        $conversation->id = $id;
        if ($account) {
            $conversation->mailbox_id = $account->mailbox_id;
        }
        $conversation->save();

        return $conversation;
    }

    public function test_open_window_returns_a_fresh_clock_and_an_empty_banner()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555040, now()->subMinutes(30));
        $conversation = $this->fakeConversation(555040);

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/conversation/555040/window-state'));

        $response->assertStatus(200);
        $json = json_decode($response->getContent(), true);
        $this->assertSame('', $json['banner']);
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.window_clock_open'),
            $json['clock']
        );
    }

    public function test_expired_window_returns_the_banner_html_and_an_empty_clock()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555041, now()->subHours(25));
        $conversation = $this->fakeConversation(555041);

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/conversation/555041/window-state'));

        $response->assertStatus(200);
        $json = json_decode($response->getContent(), true);
        $this->assertSame('', $json['clock']);
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.window_expired_notice'),
            $json['banner']
        );
    }

    public function test_inactive_account_returns_the_inactive_banner_regardless_of_window()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $this->makeInbound($account, 555042, now()->subMinutes(5));
        $conversation = $this->fakeConversation(555042);

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/conversation/555042/window-state'));

        $response->assertStatus(200);
        $json = json_decode($response->getContent(), true);
        $this->assertSame('', $json['clock']);
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.account_inactive_banner'),
            $json['banner']
        );
    }

    /**
     * The regression this whole feature exists to prevent (spec §3): a
     * reply that arrived AFTER the page first loaded must be reflected as
     * a genuinely fresh expiry, not the stale one from the original
     * render. This simulates it by calling the endpoint twice against two
     * different real DB states for the same conversation id, exactly as
     * two separate live requests would see it.
     */
    public function test_a_later_reply_is_reflected_as_a_fresh_expiry_not_a_stale_one()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555043, now()->subHours(23));
        $conversation = $this->fakeConversation(555043);

        $firstResponse = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/conversation/555043/window-state'));
        $firstJson = json_decode($firstResponse->getContent(), true);
        $this->assertStringContainsString('metawhatsapp-window-clock-warning', $firstJson['clock']);

        // A fresh reply arrives — the real window resets to ~24h.
        $this->makeInbound($account, 555043, now());

        $secondResponse = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/conversation/555043/window-state'));
        $secondJson = json_decode($secondResponse->getContent(), true);
        $this->assertStringNotContainsString('metawhatsapp-window-clock-warning', $secondJson['clock']);
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.window_clock_open'),
            $secondJson['clock']
        );
    }

    public function test_a_conversation_outside_the_module_404s()
    {
        $conversation = $this->fakeConversation(555044);

        $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/conversation/555044/window-state'))
            ->assertStatus(404);
    }

    public function test_an_agent_without_mailbox_access_is_forbidden()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555045, now()->subMinutes(5));
        $conversation = $this->fakeConversation(555045, $account);

        $outsider = new \App\User();
        $outsider->first_name = 'Outsider';
        $outsider->last_name  = 'Agent';
        $outsider->email      = 'outsider-' . uniqid() . '@example.com';
        $outsider->password   = bcrypt('secret');
        $outsider->role       = \App\User::ROLE_USER;
        $outsider->save();

        $this->actingAs($outsider)
            ->get($this->url('/meta-whatsapp/conversation/555045/window-state'))
            ->assertStatus(403);
    }
}
