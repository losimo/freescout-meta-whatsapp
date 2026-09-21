<?php

namespace Modules\MetaWhatsApp\Support;

/**
 * The channel's credentials were encrypted with an APP_KEY that is no longer
 * FreeScout's. It is not a Meta or network error, and retrying will not fix
 * it: the token and secret need to be re-entered on the channel screen.
 *
 * Named on purpose: the message it carries is the difference between an
 * admin who knows what to do and one staring at a decryption stack trace.
 */
class CredentialsUnreadable extends \RuntimeException
{
    const HINT = 'Re-enter the token and secret in the channel settings.';
}
