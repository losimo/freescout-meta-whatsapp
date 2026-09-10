<?php

namespace Modules\MetaWhatsApp\Support;

use Carbon\Carbon;

/**
 * Whether detailed logging is on, for how long, and how long its files are kept.
 *
 * Until now this was `METAWHATSAPP_DEBUG=true` in FreeScout's `.env`, which
 * asks an administrator to edit a file on the server. On shared hosting that
 * is often out of reach, and it was the first thing a reporter went looking
 * for in issue #33.
 *
 * What this log holds is why the controls look the way they do. It records
 * complete webhook payloads, so it contains the text of customers' messages
 * and their phone numbers: a second copy of conversations living outside
 * FreeScout, in a file that deleting a customer or a conversation cannot
 * rewrite. Erasure does not reach a rotating log file and never will, so the
 * only real control is how long the window is.
 *
 * That is a reason to explain, not a reason to decide for anyone. The
 * administrator owns the server and the data, so both the duration and the
 * retention are theirs to set, and the interface says why a short window is
 * usually the right answer.
 */
class DebugLog
{
    /** Until when detailed logging stays on. Absent or empty means off. */
    const OPTION_UNTIL = 'metawhatsapp.debug_until';

    /** How many daily files to keep. */
    const OPTION_RETENTION = 'metawhatsapp.debug_retention_days';

    /** Kept for compatibility: the value the module shipped with. */
    const DEFAULT_RETENTION_DAYS = 7;

    /** Stored instead of a date when the administrator asks for no end. */
    const ALWAYS = 'always';

    public static function isEnabled(): bool
    {
        // The .env flag still wins on its own. Anyone who set it up that way
        // keeps working without touching anything, and administrators who
        // manage servers by file often prefer it.
        if (config('metawhatsapp.debug')) {
            return true;
        }

        $until = self::option(self::OPTION_UNTIL, '');

        if (empty($until)) {
            return false;
        }

        if ($until === self::ALWAYS) {
            return true;
        }

        // A window that has run out is off, and stays stored so the panel can
        // say when it ended rather than pretending it was never on.
        return Carbon::parse($until)->isFuture();
    }

    /**
     * When the window ends, or null when there is none. Null covers both "off"
     * and "always", which the panel tells apart with isEnabled().
     */
    public static function expiresAt(): ?Carbon
    {
        $until = self::option(self::OPTION_UNTIL, '');

        if (empty($until) || $until === self::ALWAYS) {
            return null;
        }

        return Carbon::parse($until);
    }

    public static function isForcedByEnv(): bool
    {
        return (bool) config('metawhatsapp.debug');
    }

    public static function isAlwaysOn(): bool
    {
        return self::option(self::OPTION_UNTIL, '') === self::ALWAYS;
    }

    /** @param int|null $days null leaves it on with no end date */
    public static function enableFor(?int $days): void
    {
        \Option::set(self::OPTION_UNTIL, $days === null ? self::ALWAYS : now()->addDays($days)->toDateTimeString());
    }

    public static function disable(): void
    {
        \Option::set(self::OPTION_UNTIL, '');
    }

    public static function retentionDays(): int
    {
        $days = (int) self::option(self::OPTION_RETENTION, self::DEFAULT_RETENTION_DAYS);

        // A retention of zero would mean "keep every file for ever" in
        // Monolog, which is the opposite of what anyone typing 0 wants.
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    public static function setRetentionDays(int $days): void
    {
        \Option::set(self::OPTION_RETENTION, max(1, $days));
    }

    /**
     * Lectura d'una opció **sense** la memòria cau d'Option, i el motiu importa.
     *
     * `Option::set()` no invalida `Option::$cache`, que és estàtica i viu tot
     * el procés. En una petició web no es nota, però un `queue:work` de llarga
     * durada llegeix l'opció un cop i es queda amb aquell valor per sempre:
     * l'administrador apagaria el registre detallat des del panell i el
     * treballador continuaria escrivint converses al fitxer fins que algú el
     * reiniciés. És el mateix parany del `queue:restart` que ja tenim al
     * Troubleshooting, i aquí seria pitjor, perquè el que segueix corrent
     * guarda dades personals.
     *
     * El preu és una consulta per missatge registrat, que al costat de la
     * crida HTTP a Meta no es veu. Qui vingui a "optimitzar-ho" tornant a la
     * memòria cau, que llegeixi això primer.
     */
    protected static function option(string $name, $default)
    {
        return \Option::get($name, $default, true, false);
    }
}
