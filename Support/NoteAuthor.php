<?php

namespace Modules\MetaWhatsApp\Support;

use App\User;

/**
 * Who the module's automatic notes are attributed to (issue #43).
 *
 * The module writes a note in the conversation when a reply cannot be sent
 * or when Meta reports a message as undelivered. Since v2.1.0 that note
 * carries the agent it concerns, so the core gives it a face and lays it out
 * like any other note.
 *
 * That turned out to read wrongly. FreeScout renders the author as "you"
 * when the viewer is the author, so the agent whose reply failed is told, in
 * their own voice, something the module wrote about them. The reporter asked
 * for one user chosen by the administrator instead, a placeholder such as
 * "System" or "WhatsApp", and for the old rendering back when that user is
 * gone.
 *
 * Issue #68 took that last part back, and MarkoD is the reason: the agent
 * fallback restored the exact reading this setting exists to remove, and it
 * did so without anyone changing a setting. A deleted placeholder was
 * enough, and so was an installation that never opened the screen. So the
 * agent came out of the middle of the chain and the installation's first
 * administrator took its place.
 *
 * One setting for the whole instance and not one per channel: who signs an
 * automatic note is a question about the installation, not about a phone
 * number, and an administrator who had to answer it once per channel would
 * eventually answer it differently by accident.
 *
 * Unset stays the default, and since #68 that default is the installation's
 * first administrator rather than the agent. Nothing is invented even so: an
 * installation with no administrator at all gets no user made up for it,
 * which is where the chain ends in null.
 *
 * Issue #64 gave the chosen user a second property, and it is the reason the
 * help text asks for a placeholder rather than a colleague: the module's note
 * notifications leave that user out. They are attributed to it
 * (AgentNotifications::noteAdded() passes configuredUserId() as the event's
 * "caused by"), and the core drops whoever an event is attributed to from its
 * own recipients, exactly as it does for a person who adds a note by hand. So
 * an agent chosen here keeps working conversations and stops being told about
 * the module's notes on them. With nothing chosen the attribution is null,
 * which excludes nobody, and the agent whose reply just failed is told.
 */
class NoteAuthor
{
    /** Id of the user every automatic note is attributed to. Empty means none. */
    const OPTION_USER = 'metawhatsapp.note_author_user_id';

    /**
     * The user id a note must carry.
     *
     * The order is the administrator's choice, then the installation's first
     * administrator, then the agent the note is about. The agent is last and
     * not second on purpose (#68): a note signed by the agent whose reply
     * failed is rendered to them as "you", which is what #43 was opened to
     * remove, so the module reaches for it only where the two above it are
     * both empty.
     *
     * @param int|null $concernsUserId the agent the note is about
     *
     * @return int|null null only where there is no administrator and the
     *                  note concerns no agent either
     */
    public static function forNote(?int $concernsUserId): ?int
    {
        $configured = self::configuredUserId();

        if ($configured !== null) {
            return $configured;
        }

        $installation = self::installationAuthorId();

        if ($installation !== null) {
            return $installation;
        }

        return $concernsUserId ?: null;
    }

    /**
     * Who signs a note that concerns no agent at all (issue #55).
     *
     * Some notes are about nobody: the customer matched several records, or
     * Meta reported a new number. Those used to be written with no author,
     * and the core's print view reads the author's name without checking for
     * null (resources/views/conversations/partials/thread.blade.php:89, inside
     * the isPrint() branch), so printing such a conversation failed on a call
     * on null. The non-print path goes through conversations/thread_by, which
     * handles it, which is why the defect only showed when printing.
     *
     * The first non-deleted administrator, and not user id 1 which the report
     * proposed: nothing in FreeScout creates that id, an administrator can be
     * demoted later, and a deleted user keeps their row. It is also the user
     * the core itself falls back to when it needs to act as the installation.
     *
     * The query is the module's own rather than User::getSuperAdmin(), and
     * the difference is the ORDER BY. On 1.8.252 getSuperAdmin() is
     * nonDeleted()->where('role', ROLE_ADMIN)->first() with no ordering
     * (app/User.php:1077), so which administrator signs a note is up to the
     * storage engine and can change under the same data. MarkoD had that
     * fixed upstream (freescout-help-desk/freescout@aa8827d), but it ships in
     * the release after 1.8.252 and this module supports FreeScout from
     * 1.8.234, so ordering here gives the same answer on every version the
     * module runs on, and the same answer as the core once the floor passes
     * that release. Then this can go back to getSuperAdmin().
     *
     * The cost is real and was stated in the issue before taking it: a note
     * attributed to a real administrator is rendered by the core as "you" to
     * that administrator, which is the reason the author became configurable
     * in the first place (#43). The person who pays it is also the person who
     * can set a different author, which is what makes it the right trade.
     *
     * Null stays possible, because an installation with no administrator and
     * a note that concerns no agent leaves nobody to name, and this module
     * does not invent a user for it. The note is still written: it carries
     * the only visible trace of what happened.
     *
     * Zero non-deleted administrators is not a hand-edited database, which
     * is worth knowing before anyone simplifies this away. It is reachable
     * through the interface in three steps: deleting a user marks the status
     * and leaves the role (app/User.php:1475), the role select is rendered on
     * an administrator's own profile (users/profile.blade.php:37-43), and the
     * guard against the last administrator demoting themselves counts rows
     * with no status filter (UsersController.php:224), so a deleted
     * administrator makes the count two.
     */
    protected static function installationAuthorId(): ?int
    {
        $id = User::nonDeleted()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * The configured user when it can still be used, or null.
     *
     * A user that has since been deleted answers null, so the note falls
     * through to the installation's first administrator, which is also where
     * an installation that never touched the setting lands. The two cases
     * stay distinct in the code and read the same on screen, which is the
     * point: an administrator who deletes the placeholder gets the default
     * back rather than a user who is gone, and not the "you" rendering the
     * setting exists to remove (#68).
     *
     * @return int|null
     */
    public static function configuredUserId(): ?int
    {
        $stored = (int) self::option(self::OPTION_USER, '');

        if ($stored <= 0) {
            return null;
        }

        return self::isSelectable($stored) ? $stored : null;
    }

    /**
     * Whether an id names a user an administrator may pick.
     *
     * Narrower than "a row in the users table" on purpose: FreeScout deletes
     * a user by marking the row, so a deleted user is still findable and
     * would otherwise be storable. Both the dropdown and the save use this,
     * so what is offered and what is accepted cannot drift apart.
     */
    public static function isSelectable(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return User::nonDeleted()->where('id', $userId)->exists();
    }

    /** The users the dropdown offers, in the order the core lists people. */
    public static function selectableUsers()
    {
        return User::nonDeleted()->orderBy('first_name')->orderBy('last_name')->get();
    }

    /** @param int|null $userId null or 0 clears the setting */
    public static function setUserId(?int $userId): void
    {
        \Option::set(self::OPTION_USER, $userId ? (string) $userId : '');
    }

    /**
     * Read without Option's static cache, and the reason matters.
     *
     * Option::set() writes to the database and never touches Option::$cache,
     * which is static and lives as long as the process. Both callers of
     * forNote() run inside a queue worker: OutboundGuard from the outbound
     * jobs, and the delivery-failure note from ProcessInboundWebhook. A
     * worker started before an administrator chose the user would keep
     * signing notes with the agent for as long as it lived, and nobody
     * would connect that to a setting that looks saved on screen.
     *
     * The project has already paid for this once with the detailed log; see
     * the same note in DebugLog. Do not "optimise" this into a cached read.
     */
    protected static function option(string $name, $default)
    {
        return \Option::get($name, $default, true, false);
    }
}
