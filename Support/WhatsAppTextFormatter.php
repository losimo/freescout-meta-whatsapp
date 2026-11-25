<?php

namespace Modules\MetaWhatsApp\Support;

class WhatsAppTextFormatter
{
    /**
     * Matches a URL or an e-mail address so it can be shielded from the
     * marker passes. The URL alternative comes first so that a scheme wins
     * over the e-mail rule inside things like
     * `https://user_name@example.com/a`, while an address whose domain
     * starts with `www.` is still caught whole, because the e-mail
     * alternative is reached at an earlier offset than the inner `www.`.
     *
     * The incoming text has already been through htmlspecialchars(), so a
     * query string arrives as `?a=1&amp;b=2` and quotes arrive as `&quot;`
     * or `&#039;`. The body class therefore has to accept `&`, `#` and `;`;
     * only the very last character is constrained, to keep sentence
     * punctuation that happens to follow a URL out of the match. A marker
     * is barred from that last position too, so that `*<url>*` still bolds
     * instead of the URL eating its own closing marker; a URL that really
     * does end in a marker just leaves that one character outside the
     * token, where it has no partner to pair with.
     */
    const URL_OR_EMAIL = '~(?:(?:https?://|ftp://|mailto:|www\.)[^\s\x00]*[^\s\x00.,;:!?)\]}*_\~]|[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+)~i';

    /**
     * A marker only opens when it is not preceded by a word character, and
     * only closes when it is not followed by one. Bytes >= 0x80 count as
     * word characters so that a letter outside ASCII (accents, emoji) is
     * treated like any other letter without needing the /u modifier, which
     * would make the whole pass return null on malformed UTF-8.
     *
     * Each marker also blocks itself on both sides, so `my__page__name`
     * stays plain, while the other two markers are allowed to touch it so
     * that `_*bold*_` and `*_italic_*` still nest.
     */
    const WORD_BYTES = 'A-Za-z0-9\x80-\xFF';

    /**
     * Render WhatsApp's own inline markup (single-delimiter, not CommonMark)
     * on text that has already been through htmlspecialchars(). Only *bold*,
     * _italic_, ~strikethrough~ and ```monospace``` are handled, matching
     * WhatsApp's official formatting docs — bulleted/numbered lists, block
     * quotes and single-backtick inline code are deliberately left as-is
     * (issue #21 scoped them out).
     *
     * Matching is restricted to a single line: WhatsApp's own client applies
     * the same delimiter rule per line, and keeping it single-line avoids
     * a run of `*` on one line pairing with one three paragraphs down.
     *
     * Two defences keep the markers away from text that only looks like
     * markup (issue #48). URLs and e-mail addresses are pulled out into
     * placeholder tokens before any marker pass and put back verbatim at the
     * end, the same technique already used for monospace; and a marker is
     * only honoured at a word boundary, which is what saves `file_name_v2`
     * and `2*3*4` outside URLs.
     *
     * This does NOT reproduce WhatsApp's own boundary rules. The public
     * formatting documentation does not state them, so the rule here is
     * deliberately conservative: when in doubt, do not format. Leaving a
     * run of text plain that WhatsApp would have italicised is a cosmetic
     * miss, while formatting inside a URL rewrites the text that gets
     * stored on the thread and destroys the original.
     */
    public static function format(string $escapedHtml): string
    {
        $tokens = [];

        $text = preg_replace_callback(
            '/```([^\n]+?)```/',
            function ($m) use (&$tokens) {
                $token = "\0t".count($tokens)."\0";
                $tokens[$token] = '<code>'.$m[1].'</code>';

                return $token;
            },
            $escapedHtml
        );

        $text = preg_replace_callback(
            self::URL_OR_EMAIL,
            function ($m) use (&$tokens) {
                $token = "\0t".count($tokens)."\0";
                $tokens[$token] = $m[0];

                return $token;
            },
            $text
        );

        $text = self::pair($text, '\*', 'strong');
        $text = self::pair($text, '_', 'em');
        $text = self::pair($text, '~', 's');

        return strtr($text, $tokens);
    }

    /**
     * Replace one balanced marker pair on a single line with an HTML tag.
     * The marker arrives already escaped for use both as a pattern atom and
     * inside a character class.
     *
     * @param string $text   text to rewrite
     * @param string $marker the marker, e.g. '\*'
     * @param string $tag    HTML tag to wrap the content in
     *
     * @return string
     */
    private static function pair($text, $marker, $tag)
    {
        $edge = '['.self::WORD_BYTES.$marker.']';

        $replaced = preg_replace(
            '/(?<!'.$edge.')'.$marker.'(\S(?:[^\n]*?\S)?)'.$marker.'(?!'.$edge.')/',
            '<'.$tag.'>$1</'.$tag.'>',
            $text
        );

        return $replaced === null ? $text : $replaced;
    }
}
