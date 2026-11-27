<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use Illuminate\Support\Facades\Config;

/**
 * What this module expects from FreeScout, and what it does when it does not
 * get it.
 *
 * FreeScout does not enforce the `freescout_version` key in module.json. The
 * core only checks a required version for paid modules, through the licence
 * data the marketplace returns; a community module dropped into Modules/ is
 * simply loaded. So nothing stops the module from running on a core older
 * than it expects, and without this class the first sign of trouble would be
 * a queue worker dying on an undefined method, which is the quietest possible
 * way to fail.
 *
 * The answer is not to refuse to run. It is to keep working, and to say so
 * where an administrator will read it.
 */
class CoreCompat
{
    /**
     * Where the conversation status API this module uses was introduced.
     * Newer is recommended: 1.8.235, 1.8.236 and 1.8.237 each close security
     * issues in FreeScout itself.
     */
    const MINIMUM_FREESCOUT = '1.8.234';

    /**
     * The parameter with which an installation switches the core's chat
     * e-mail copy off for everybody. Added in the release after 1.8.252,
     * fed by APP_CHAT_CONVERSATION_REPLY_BY_EMAIL and on by default.
     */
    const CORE_CHAT_EMAIL_PARAMETER = 'app.chat_conversation_reply_by_email';

    public static function coreVersion(): string
    {
        return (string) config('app.version');
    }

    /**
     * checkAppVersion() is itself old enough to be safe to call anywhere:
     * verified present in 1.8.217, well below the floor this class describes.
     */
    public static function isSupportedCore(): bool
    {
        return \Helper::checkAppVersion(self::MINIMUM_FREESCOUT);
    }

    /**
     * Does the conversation currently sit in any of these statuses?
     *
     * From 1.8.234 the core can carry statuses added by other modules through
     * a hook, and `hasStatus()` resolves them to the standard status they
     * behave like. Comparing `status` by hand would file a custom status as
     * "none of the ones I know about" and decide wrongly.
     *
     * The fallback is not a lesser version of that. On a core without
     * `hasStatus()` there is no hook either, so a custom status cannot exist,
     * and a direct comparison is exactly right. Both branches are correct in
     * their own world, which is why this is worth having rather than forcing
     * everyone to upgrade first.
     */
    public static function conversationHasStatus(Conversation $conversation, array $statuses): bool
    {
        if (method_exists($conversation, 'hasStatus')) {
            return $conversation->hasStatus($statuses);
        }

        return in_array((int) $conversation->status, $statuses, true);
    }

    /**
     * Has this installation switched that feature off for everybody?
     *
     * The core gates both halves of it on this parameter: the view that
     * renders the checkbox (`resources/views/conversations/view.blade.php`)
     * and the controller block that reads the submitted field into the
     * thread's meta (`ConversationsController`). With it off there is no
     * checkbox and no meta, so the channel's own E-mail copy switch has
     * nothing left to switch and an administrator who turns it on gets
     * nothing. Reported by MarkoD on issue #57.
     *
     * The reason this is a method and not an inline `config()` call: on
     * 1.8.251 and 1.8.252 the key does not exist, so `config()` answers null,
     * and null is not the same answer as false. Those are the versions where
     * the feature is live and the channel switch is the only thing holding
     * the double send back, so telling an administrator there that the core
     * has it switched off would be false. Hence `has()` before the value,
     * which is also exactly what the core's two gates evaluate.
     *
     * A key that exists holding null counts as off, deliberately: the core's
     * own gate is a plain `config()` truth test, so null closes it there too.
     */
    public static function coreChatEmailCopyDisabled(): bool
    {
        return Config::has(self::CORE_CHAT_EMAIL_PARAMETER)
            && !Config::get(self::CORE_CHAT_EMAIL_PARAMETER);
    }
}
