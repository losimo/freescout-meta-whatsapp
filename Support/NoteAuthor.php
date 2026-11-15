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
 * One setting for the whole instance and not one per channel: who signs an
 * automatic note is a question about the installation, not about a phone
 * number, and an administrator who had to answer it once per channel would
 * eventually answer it differently by accident.
 *
 * Unset stays the default. An administrator who never opens this screen
 * keeps exactly the behaviour they have today, and nothing invents a user
 * on their behalf.
 */
class NoteAuthor
{
    /** Id of the user every automatic note is attributed to. Empty means none. */
    const OPTION_USER = 'metawhatsapp.note_author_user_id';

    /**
     * The user id a note must carry, or null for no author at all.
     *
     * @param int|null $concernsUserId the agent the note is about
     *
     * @return int|null
     */
    public static function forNote(?int $concernsUserId): ?int
    {
        $configured = self::configuredUserId();

        if ($configured !== null) {
            return $configured;
        }

        // The fallback is deliberately the same expression as before the
        // setting existed, including the null: a note with no resolvable
        // author still has to be written, because it carries the only
        // visible trace of the failure.
        return $concernsUserId ?: null;
    }

    /**
     * The configured user when it can still be used, or null.
     *
     * A user that has since been deleted answers null, which is what sends
     * the note back to the agent it concerns. That is the degradation the
     * reporter asked for by name, and it is not the same case as the
     * setting never having been touched, even though both end up here.
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
