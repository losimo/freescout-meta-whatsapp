<?php

namespace Modules\MetaWhatsApp\Support;

/**
 * Which template states are a problem an administrator has to act on, which
 * ones mean the problem is over, and how loud each one is.
 *
 * Meta's own vocabulary is kept verbatim rather than translated into ours, so
 * that what the panel says can be matched against what WhatsApp Manager says
 * without a mapping in anyone's head.
 */
class TemplateStatus
{
    /**
     * States where the template cannot be used and someone has to do
     * something.
     *
     * PENDING_DELETION is deliberately not here: it means the administrator
     * already asked Meta to delete the template, so raising it as a problem
     * would tell them to go and fix something they chose, and no APPROVED is
     * ever coming for a template on its way out, so the row would never
     * clear.
     */
    const PROBLEM = [
        'REJECTED',
        'DISABLED',
        'PAUSED',
        'LOCKED',
        'LIMIT_EXCEEDED',
    ];

    /** States that mean an earlier problem is over. */
    const RESOLVED = ['APPROVED', 'REINSTATED'];

    public static function isProblem(string $event): bool
    {
        return in_array($event, self::PROBLEM, true);
    }

    /**
     * The identity of a template, which is not the same thing as how it reads
     * on screen. Keeping them apart is the point: the panel's wording can
     * change without the clearing quietly failing to match.
     */
    public static function key(array $value): string
    {
        return ($value['message_template_name'] ?? '?')
            . '|' . ($value['message_template_language'] ?? '?');
    }

    public static function clears(string $event): bool
    {
        return in_array($event, self::RESOLVED, true);
    }

    /**
     * DISABLED means Meta withdrew the template and it is not coming back on
     * its own; the rest of the problem states can be recovered from.
     */
    public static function severity(string $event): string
    {
        if ($event === 'DISABLED') {
            return 'error';
        }

        return self::isProblem($event) ? 'warning' : 'info';
    }

    /**
     * A single line for the panel: which template, in which language, and what
     * happened to it.
     */
    public static function summary(array $value): string
    {
        $summary = ($value['message_template_name'] ?? '?')
            . ' (' . ($value['message_template_language'] ?? '?') . '): '
            . ($value['event'] ?? '?');

        if (!empty($value['reason']) && $value['reason'] !== 'NONE') {
            $summary .= ' - ' . $value['reason'];
        }

        return mb_substr($summary, 0, 191);
    }
}
