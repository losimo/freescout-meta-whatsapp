<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\ServiceUsage;

/**
 * From 1 October 2026 Meta bills service messages sent inside the customer
 * window, with a monthly allowance per business phone number. This counts
 * what left through FreeScout, which is the only thing we can honestly
 * know: the same number may also be used elsewhere.
 */
class ServiceUsageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_counts_only_this_account_s_outbound_service_messages()
    {
        $account = $this->createTestAccount();
        $other   = $this->createTestAccount();

        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE);
        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_TEMPLATE);
        $this->message($account, WhatsAppMessage::DIRECTION_INBOUND, null);
        $this->message($other, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE);

        $this->assertEquals(1, ServiceUsage::sentThisMonth($account));
    }

    public function test_does_not_count_a_message_that_never_went_out()
    {
        $account = $this->createTestAccount();

        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE);
        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE, WhatsAppMessage::STATUS_FAILED);

        $this->assertEquals(
            1,
            ServiceUsage::sentThisMonth($account),
            'Meta bills per delivered message, so a failed send costs nothing and must not be counted.'
        );
    }

    public function test_the_allowance_resets_so_last_month_does_not_count()
    {
        $account = $this->createTestAccount();

        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE);
        $this->message(
            $account,
            WhatsAppMessage::DIRECTION_OUTBOUND,
            WhatsAppMessage::CATEGORY_SERVICE,
            WhatsAppMessage::STATUS_SENT,
            now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateTimeString()
        );

        $this->assertEquals(
            1,
            ServiceUsage::sentThisMonth($account),
            'Meta\'s allowance resets every month and does not roll over, so only the current month counts.'
        );
    }

    /**
     * Rows written before the category column existed carry no category and
     * nothing backfills them. Counting them would guess, and guessing here
     * means putting a number in front of an administrator that we cannot
     * stand behind.
     */
    public function test_messages_recorded_before_the_category_existed_are_not_counted()
    {
        $account = $this->createTestAccount();

        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE);
        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, null);

        $this->assertEquals(1, ServiceUsage::sentThisMonth($account));
    }

    public function test_the_panel_shows_the_count_only_when_the_counter_is_switched_on()
    {
        $account = $this->createTestAccount();
        $this->message($account, WhatsAppMessage::DIRECTION_OUTBOUND, WhatsAppMessage::CATEGORY_SERVICE);
        $admin = $this->makeAdminUser();

        $off = $this->actingAs($admin)->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));
        // Without the status check an error page would satisfy the next
        // assertion too, and the test would pass for the wrong reason.
        $off->assertStatus(200);
        $this->assertStringNotContainsString(__('metawhatsapp::metawhatsapp.usage_scope_help'), $off->getContent());

        $account->usage_counter_enabled = true;
        $account->save();

        $on = $this->actingAs($admin)->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));
        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.usage_scope_help'), $on->getContent());
    }

    /**
     * Saving a channel from the form was reaching Request::boolean(), which
     * does not exist on this Laravel, so every save of an existing channel
     * returned a 500 and lost the edit. No test covered this route at all:
     * the counter's own tests set the column on the model directly, which is
     * why the suite stayed green over a broken save path.
     */
    public function test_saving_a_channel_from_the_form_keeps_working()
    {
        $account = $this->createTestAccount();

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->put($this->url('/meta-whatsapp/settings/' . $account->id), [
                'name'                       => 'Renamed channel',
                'phone_number'               => '+34600123999',
                'phone_number_id'            => $account->phone_number_id,
                'waba_id'                    => $account->waba_id ?: 'waba-test',
                'verify_token'               => str_repeat('a', 64),
                'template_threshold_minutes' => 1435,
                'usage_counter_enabled'      => '1',
            ]);

        $response->assertStatus(302);
        $this->assertEquals('Renamed channel', $account->fresh()->name, 'The edit must actually be saved.');
        $this->assertTrue((bool) $account->fresh()->usage_counter_enabled);
    }

    public function test_leaving_the_counter_box_unticked_switches_it_off()
    {
        $account = $this->createTestAccount();
        $account->usage_counter_enabled = true;
        $account->save();

        $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->put($this->url('/meta-whatsapp/settings/' . $account->id), [
                'name'                       => $account->name,
                'phone_number'               => '+34600123999',
                'phone_number_id'            => $account->phone_number_id,
                'waba_id'                    => $account->waba_id ?: 'waba-test',
                'verify_token'               => str_repeat('a', 64),
                'template_threshold_minutes' => 1435,
            ]);

        $this->assertFalse((bool) $account->fresh()->usage_counter_enabled);
    }

    protected function message(
        WhatsAppAccount $account,
        string $direction,
        ?string $category,
        string $status = WhatsAppMessage::STATUS_SENT,
        ?string $createdAt = null
    ): WhatsAppMessage {
        $message = WhatsAppMessage::create([
            'wamid'           => 'wamid.usage-' . mt_rand(1000000, 9999999),
            'account_id'      => $account->id,
            'conversation_id' => 1,
            'contact_phone'   => '+34611222333',
            'direction'       => $direction,
            'category'        => $category,
            'status'          => $status,
        ]);

        if ($createdAt !== null) {
            $message->created_at = $createdAt;
            $message->save();
        }

        return $message;
    }
}
