<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use App\Subscription;
use App\Thread;
use Illuminate\Support\Facades\Log;

/**
 * Who hears about a note this module writes, and when they are told
 * (issue #64).
 *
 * Every note used to notify nobody. The core fires App\Events\UserAddedNote
 * and the conversation.note_added action from Thread::create()
 * (app/Thread.php:1307 on 1.8.252), and ConversationNote builds the Thread by
 * hand and saves it, so neither ran: a refused reply, an undelivered message
 * or a number change appeared in the conversation and nothing told anyone it
 * was there, not even an agent subscribed to notes.
 *
 * Three parts, and they fail in different ways.
 *
 * `noteAdded()` is the registration. It does what the core's two listeners
 * on UserAddedNote would have done, in their order, and it registers the
 * event itself rather than firing UserAddedNote. The reason is the "caused
 * by" argument, and it is MarkoD's (#64): Subscription drops the user an
 * event is attributed to from its own recipients (app/Subscription.php:373),
 * and the listener takes that user from the note's created_by_user_id
 * (SendNotificationToUsers.php:37-40). Since #43 the note carries the agent
 * it concerns, so firing the event would have notified everyone except the
 * one agent whose reply had just failed. Attributing it to the configured
 * note author instead means null on an installation that never set one, and
 * null excludes nobody.
 *
 * `around()` is the flush, which has no equivalent in core for a queue
 * worker. registerEvent() only appends to a static buffer, and core empties
 * that buffer in exactly two places, the TerminateHandler middleware and the
 * FetchEmails command. Neither runs in a worker, which was issue #46. So a
 * job that writes a note has to flush the buffer itself or the event dies in
 * the worker's memory and the fix looks done while notifying nobody.
 *
 * It wraps the whole job and not the end of handle(): the three outbound
 * jobs write notes on paths that return early, eight or nine of them each,
 * and failed() writes notes too, through OutboundFailure. A flush on the
 * last line of handle() would be missed by every one of those. Wrapping also
 * puts the discipline in one place instead of three copies, which is the
 * mistake this module has already paid for four times over (#18, #25, #28,
 * #29).
 *
 * `tell()` is the live refresh and the conversation.note_added action, and
 * the wrapper is what makes those safe too. Two of the module's four notes
 * are written inside a DB::transaction() for reasons of their own, so a note
 * is written now and told about once the run is over, when the row is
 * certain. The alternative was telling the core about rows that can still
 * roll back.
 */
class AgentNotifications
{
    /**
     * Whether a run is collecting what to tell the core, rather than telling
     * it as each note is written.
     *
     * @var bool
     */
    protected static $collecting = false;

    /**
     * The notes a collecting run has written, told about once it is over.
     *
     * @var array<int, array<int, mixed>> pairs of conversation and note
     */
    protected static $pending = [];

    /**
     * Runs a job with the subscription buffer flushed after it, once.
     *
     * Three rules are built in, and each one is a defect that was paid for.
     *
     * Nothing is flushed when the job registered nothing of its own. The
     * buffer is static and lives as long as the worker process, so another
     * job that ran before this one in the same process may have left events
     * in it, and a run of ours that wrote no note has no business deciding
     * what happens to them. Counting is enough to tell ours apart:
     * registerEvent() only appends.
     *
     * What that rule does not say, and should: once we do flush, the core
     * drains the whole buffer. processEvents() iterates everything in there
     * and clears it, and offers no way to flush part of it, so a run that
     * registered one note of its own sends another job's entries with it.
     * That is the core's design rather than a choice of ours, and it is why
     * a failed flush clears the buffer instead of leaving it.
     *
     * On an exception the buffer is restored rather than flushed, and this is
     * not a finally. Flushing after a rollback is worse than not flushing: on
     * a reopened conversation the conversation row survives the rollback and
     * only the new thread disappears, so processEvents() would pick the
     * previous thread and notify the whole mailbox about a message the agents
     * had already read, three times over because the job retries.
     *
     * The accepted case, stated so nobody has to rediscover it: when a run
     * does several pieces of work and a later one throws, the events already
     * registered by the earlier, committed ones are discarded with it. Those
     * agents get no notification for work that did land. It is the quieter of
     * the two failures, and it says so in the log.
     *
     * @param callable $run     the job's own body
     * @param array    $context what the log line should carry, e.g. the
     *                          account id and the job's class
     *
     * @return mixed whatever $run() returned
     */
    public static function around(callable $run, array $context = [])
    {
        // Whatever is in the buffer before we start is not ours.
        $preexisting = Subscription::$occurred_events;

        // Nested runs are not a case today, but the state is static and the
        // rule is the same one the buffer follows: put back exactly what was
        // there before, never clear the lot.
        $wasCollecting  = self::$collecting;
        $pendingBefore  = self::$pending;
        self::$collecting = true;

        try {
            $result = $run();
        } catch (\Throwable $e) {
            self::reportDiscarded($preexisting, $context, $e);

            // Only what we added. registerEvent() appends, so everything past
            // the point we started is ours and the rest goes back untouched.
            Subscription::$occurred_events = $preexisting;
            self::$collecting = $wasCollecting;
            self::$pending    = $pendingBefore;

            throw $e;
        }

        $ours = array_slice(self::$pending, count($pendingBefore));
        self::$collecting = $wasCollecting;
        self::$pending    = $pendingBefore;

        foreach ($ours as $written) {
            try {
                self::tell($written[0], $written[1]);
            } catch (\Throwable $e) {
                // conversation.note_added is a surface other modules and
                // Workflow rules are invited to use, so somebody else's code
                // runs here, after the work is done and committed. Letting it
                // out would skip the notes after this one, skip the flush
                // below, and bill the job a failed attempt that redoes work
                // already written. It is reported instead, which is the rule
                // this class exists for: a dropped notification leaves a
                // trace.
                Log::error('[MetaWhatsApp] A listener threw while being told about a note', array_merge($context, [
                    'error' => $e->getMessage(),
                ]));
            }
        }

        if (count(Subscription::$occurred_events) > count($preexisting)) {
            try {
                // Once per run and not per note, which is also how FetchEmails
                // flushes: several notes on the same conversation coalesce into
                // one notification instead of one per note.
                Subscription::processEvents();
            } catch (\Throwable $e) {
                // The buffer is cleared, and that is a change of mind worth
                // explaining. Leaving it looked kinder, on the argument that
                // the next flush would send what this one did not and a
                // repeated notification is visible while a missing one is
                // not. It is not kinder when the failure is persistent, and
                // the one failure already met is: an entry whose
                // conversation has lost its mailbox kills processEvents()
                // every time (userIdsHavingAccess() on null). Since the core
                // drains the whole buffer and offers no way to flush part of
                // it, leaving that entry means every later job of ours calls
                // processEvents(), dies on the same entry, and notifies
                // nobody for the life of the worker, with only this line
                // repeating to show it.
                //
                // Once this module calls processEvents() it has taken on the
                // whole buffer. One lost batch, counted here, beats a worker
                // that never notifies again.
                $discarded = count(Subscription::$occurred_events);
                Subscription::$occurred_events = [];

                Log::error('[MetaWhatsApp] Agent notifications could not be flushed', array_merge($context, [
                    'discarded_entries' => $discarded,
                    'error'             => $e->getMessage(),
                ]));
            }
        }

        return $result;
    }

    /**
     * Tells the core a note was added, the way Thread::create() would.
     *
     * In the core's own listener order (EventServiceProvider.php:93-96):
     * the notification first, then the live refresh of the conversation
     * lists, which is what RefreshConversations does and what not firing
     * UserAddedNote would otherwise skip.
     *
     * conversation.note_added fires for every note, notified or not. Other
     * modules should see our notes like any other, and a Workflow rule that
     * reacts to an added note will start matching them, which is why it goes
     * in the release notes and not only in the changelog.
     *
     * @param bool $notify MarkoD's rule from #64: a note that needs an agent
     *                     to come back notifies, a note that does not, does
     *                     not. ConversationNote holds which is which.
     */
    public static function noteAdded(Conversation $conversation, Thread $note, bool $notify): void
    {
        if ($notify) {
            Subscription::registerEvent(
                Subscription::EVENT_TYPE_USER_ADDED_NOTE,
                $conversation,
                // Null on an installation with no note author set, or one
                // whose chosen user has been deleted, and null excludes
                // nobody. The configured user is excluded from notifications
                // about the notes it signs, which is why the setting's help
                // text asks for a placeholder rather than someone who works
                // conversations.
                //
                // Deliberately not NoteAuthor::forNote(), which is who signs
                // the note. The two diverge on an installation that never
                // opened the settings screen: the note is signed by the first
                // administrator (#68) while the event is attributed to
                // nobody, so that administrator is notified about a note the
                // core also renders to them as "you". That is the trade, and
                // the direction is MarkoD's from #64: a fallback author did
                // not act and knows nothing about the note, so leaving them
                // out would silence the person most likely to be watching,
                // and in an installation with no administrator left it would
                // silence the agent whose reply failed, which is the one
                // notification this whole issue exists for. The way out is
                // the setting, and the help text asks for it.
                NoteAuthor::configuredUserId()
            );
        }

        if (self::$collecting) {
            // Inside a job the telling waits for the end of the run, because
            // two of the module's four notes are written inside a
            // DB::transaction() and the core would be told about a row that
            // can still disappear. Same rule the flush above already
            // follows, and the reason tell() checks the row is still there.
            self::$pending[] = [$conversation, $note];

            return;
        }

        self::tell($conversation, $note);
    }

    /**
     * Tells the core about one note, if it is still there.
     *
     * The existence check is not belt and braces. The number-change note has
     * to live or die with the identity move and the matched-several note has
     * to be written before the customer's thread, so both are written inside
     * a transaction that can still roll back, and on one of those paths the
     * rollback is silent: ProcessInboundWebhook swallows the duplicate-wamid
     * race (1062) and returns normally. Without the check, agents' browsers
     * would be pushed a new thread, and other modules a note, for a row that
     * no longer exists. Auto-increment ids are not reused after a rollback,
     * so asking for the row is a sound way to ask whether it survived.
     */
    protected static function tell(Conversation $conversation, Thread $note): void
    {
        if (!Thread::where('id', $note->id)->exists()) {
            return;
        }

        Conversation::refreshConversations($conversation, $note);

        \Eventy::action('conversation.note_added', $conversation, $note);
    }

    /**
     * The log line for notifications a failed run threw away.
     *
     * The number is buffer entries, and it is named that way because it is
     * not a count of notifications: registerEvent() appends a second
     * EVENT_TYPE_UPDATED entry for every event that is not NEW, so one note
     * leaves two. Reporting it as notifications would be off by roughly
     * double. Entries only, never the events themselves, which would put a
     * customer's conversation in the log for no operational gain.
     *
     * A dropped notification that leaves no trace is the same shape of defect
     * as the one this class fixes: something reaches the mailbox and nobody
     * is told.
     */
    protected static function reportDiscarded(array $preexisting, array $context, \Throwable $e): void
    {
        $ours = count(Subscription::$occurred_events) - count($preexisting);

        if ($ours <= 0) {
            return;
        }

        Log::warning(
            '[MetaWhatsApp] Job failed: agent notifications discarded',
            array_merge($context, [
                'buffered_entries' => $ours,
                'error'            => $e->getMessage(),
            ])
        );
    }
}
