<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs the duplicate folders left behind by issue #30.
 *
 * Until this release the account form created the mailbox and then called
 * createPublicFolders() itself, unaware that the core MailboxObserver already
 * does exactly that on the `created` model event. createPublicFolders() inserts
 * unconditionally, so every mailbox the module created got two copies of
 * Unassigned, Drafts, Assigned, Closed, Deleted and Spam. Personal folders were
 * spared because createUsersFolders() skips users that already have them.
 *
 * Only mailboxes linked to a WhatsApp account are touched. Duplicates elsewhere
 * would not come from this module, and a module has no business reshaping the
 * folder list of a mailbox it does not own.
 */
class RemoveDuplicateMailboxFolders extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('meta_whatsapp_accounts') || !Schema::hasTable('folders')) {
            return;
        }

        $mailboxIds = DB::table('meta_whatsapp_accounts')
            ->whereNotNull('mailbox_id')
            ->distinct()
            ->pluck('mailbox_id')
            ->all();

        if (!$mailboxIds) {
            return;
        }

        $groups = DB::table('folders')
            ->select('mailbox_id', 'user_id', 'type')
            ->whereIn('mailbox_id', $mailboxIds)
            ->groupBy('mailbox_id', 'user_id', 'type')
            ->havingRaw('count(*) > 1')
            ->get();

        $removed  = 0;
        $affected = [];

        foreach ($groups as $group) {
            $ids = DB::table('folders')
                ->where('mailbox_id', $group->mailbox_id)
                ->where('type', $group->type)
                ->when(
                    $group->user_id === null,
                    function ($query) {
                        return $query->whereNull('user_id');
                    },
                    function ($query) use ($group) {
                        return $query->where('user_id', $group->user_id);
                    }
                )
                ->orderBy('id')
                ->pluck('id')
                ->all();

            // The oldest row is the one the observer created, and the one the
            // counters and any existing conversations already point at.
            $keep    = array_shift($ids);
            $discard = $ids;

            if (!$discard) {
                continue;
            }

            DB::table('conversations')->whereIn('folder_id', $discard)->update(['folder_id' => $keep]);

            // conversation_folder has a UNIQUE(folder_id, conversation_id), and
            // the same conversation may sit in both copies, so the rows are
            // collected and re-inserted rather than updated in place.
            $conversationIds = DB::table('conversation_folder')
                ->whereIn('folder_id', $discard)
                ->distinct()
                ->pluck('conversation_id')
                ->all();

            DB::table('conversation_folder')->whereIn('folder_id', $discard)->delete();

            if ($conversationIds) {
                $existing = DB::table('conversation_folder')
                    ->where('folder_id', $keep)
                    ->pluck('conversation_id')
                    ->all();

                foreach (array_chunk(array_diff($conversationIds, $existing), 500) as $chunk) {
                    DB::table('conversation_folder')->insert(array_map(function ($conversationId) use ($keep) {
                        return ['folder_id' => $keep, 'conversation_id' => $conversationId];
                    }, $chunk));
                }
            }

            DB::table('folders')->whereIn('id', $discard)->delete();

            $removed += count($discard);
            $affected[$group->mailbox_id] = true;
        }

        if (!$removed) {
            return;
        }

        foreach (array_keys($affected) as $mailboxId) {
            $mailbox = \App\Mailbox::find($mailboxId);
            if ($mailbox) {
                $mailbox->updateFoldersCounters();
            }
        }

        Log::info('[MetaWhatsApp] Removed duplicate mailbox folders (issue #30)', [
            'folders_removed' => $removed,
            'mailbox_ids'     => array_keys($affected),
        ]);
    }

    /**
     * Deliberately empty: the duplicates were a defect, not data. Recreating
     * them on rollback would only put the sidebar back the way it was broken.
     */
    public function down()
    {
    }
}
