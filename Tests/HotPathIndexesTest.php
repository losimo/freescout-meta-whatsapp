<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Every column the module filters on in a hot path has to be indexed.
 *
 * `meta_whatsapp_messages` grows by one row per message and never shrinks,
 * so a lookup without an index is a full scan that gets slower every day the
 * install is used, on paths nobody profiles: the outbound send, the
 * `failed()` handler of each job, and the reply dispatcher.
 *
 * `thread_id` was the one missing, reported by MarkoD in issue #52. Nine
 * production sites filter by it, all of them on the path that answers an
 * agent pressing Send: Jobs/SendWhatsAppMessage.php:65 and :207,
 * Jobs/SendWhatsAppMedia.php:81 and :294, Jobs/SendWhatsAppTemplate.php:106
 * and :257, and Providers/MetaWhatsAppServiceProvider.php:570, :589 and
 * :598.
 *
 * Asserted as a list rather than one case, because the next column added to
 * this table will be queried the same way and should fail here if it is not
 * indexed. Only the first column of each index counts: MySQL can use a
 * composite index for its leading column alone, not for the ones behind it.
 */
class HotPathIndexesTest extends TestCase
{
    use DatabaseTransactions;

    /** Columns that must be the leading column of some index. */
    protected function indexedColumns(): array
    {
        return [
            'thread_id'       => 'the outbound send, every job failed() handler and the reply dispatcher',
            'conversation_id' => 'the inbound webhook resolving an existing conversation',
            'wamid'           => 'the idempotency guard on every redelivered webhook',
            'contact_phone'   => 'the erasure screen iterating to a fixed point',
            'contact_user_id' => 'the same screen, by BSUID',
            'account_id'      => 'the service message counter, once per page of the channel screen',
            'attachment_id'   => 'the media guard before an upload is reused',
        ];
    }

    public function test_every_hot_path_column_is_indexed()
    {
        $leading = [];
        foreach (\DB::select('SHOW INDEX FROM meta_whatsapp_messages') as $index) {
            if ((int) $index->Seq_in_index === 1) {
                $leading[$index->Column_name] = true;
            }
        }

        $this->assertNotEmpty($leading, 'no indexes were read back at all, so this guard proves nothing');

        foreach ($this->indexedColumns() as $column => $why) {
            $this->assertArrayHasKey(
                $column,
                $leading,
                $column.' is not the leading column of any index, and it is read by '.$why
            );
        }
    }
}
