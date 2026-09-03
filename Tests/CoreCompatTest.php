<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Support\CoreCompat;

/**
 * What the module expects from the core, and what it does when it does not
 * get it.
 *
 * FreeScout does not read the `freescout_version` key of a community module,
 * so nothing stops an old install from loading this one. The module keeps
 * working and says so on the settings screen instead of dying in a queue
 * worker on an undefined method.
 */
class CoreCompatTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeConversation(int $status): Conversation
    {
        $customer = new Customer();
        $customer->first_name = 'PHPUnit';
        $customer->save();

        $conversation = new Conversation();
        $conversation->type           = Conversation::TYPE_CHAT;
        $conversation->state          = Conversation::STATE_PUBLISHED;
        $conversation->subject        = 'PHPUnit';
        $conversation->mailbox_id     = 1;
        $conversation->customer_id    = $customer->id;
        $conversation->customer_email = '';
        $conversation->status         = $status;
        $conversation->source_via     = Conversation::PERSON_CUSTOMER;
        $conversation->source_type    = Conversation::SOURCE_TYPE_API;
        $conversation->preview        = 'x';
        $conversation->save();

        return $conversation;
    }

    public function test_the_core_running_here_meets_the_declared_minimum()
    {
        $this->assertTrue(
            CoreCompat::isSupportedCore(),
            'the development install is on FreeScout ' . CoreCompat::coreVersion()
                . ', below the ' . CoreCompat::MINIMUM_FREESCOUT . ' this module declares.'
                . ' Either the environment needs updating or the declared minimum is wrong.'
        );
    }

    public function test_status_matching_answers_the_same_on_both_branches()
    {
        $active = $this->makeConversation(Conversation::STATUS_ACTIVE);
        $closed = $this->makeConversation(Conversation::STATUS_CLOSED);

        $list = [Conversation::STATUS_ACTIVE, Conversation::STATUS_SPAM];

        $this->assertTrue(CoreCompat::conversationHasStatus($active, $list));
        $this->assertFalse(CoreCompat::conversationHasStatus($closed, $list));

        // The fallback is what an old core would run. It has to reach the same
        // answer for standard statuses, otherwise the two branches disagree
        // and the behaviour depends on which FreeScout you happen to be on.
        $this->assertTrue(in_array((int) $active->status, $list, true));
        $this->assertFalse(in_array((int) $closed->status, $list, true));
    }

    public function test_the_settings_screen_warns_only_when_the_core_is_behind()
    {
        $rendered = \View::make('metawhatsapp::partials/core_notice', [
            'coreOutdated' => true,
            'coreVersion'  => '1.8.100',
            'coreMinimum'  => CoreCompat::MINIMUM_FREESCOUT,
        ])->render();

        $this->assertStringContainsString('1.8.100', $rendered);
        $this->assertStringContainsString(CoreCompat::MINIMUM_FREESCOUT, $rendered);

        $quiet = \View::make('metawhatsapp::partials/core_notice', [
            'coreOutdated' => false,
            'coreVersion'  => CoreCompat::coreVersion(),
            'coreMinimum'  => CoreCompat::MINIMUM_FREESCOUT,
        ])->render();

        $this->assertStringNotContainsString(
            CoreCompat::MINIMUM_FREESCOUT,
            $quiet,
            'the notice shows on an up-to-date install, where it is only noise'
        );
    }
}
