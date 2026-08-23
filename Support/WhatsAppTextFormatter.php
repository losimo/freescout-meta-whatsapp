<?php

namespace Modules\MetaWhatsApp\Support;

class WhatsAppTextFormatter
{
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
     */
    public static function format(string $escapedHtml): string
    {
        $monospaceTokens = [];

        $text = preg_replace_callback(
            '/```([^\n]+?)```/',
            function ($m) use (&$monospaceTokens) {
                $token = "\0mono".count($monospaceTokens)."\0";
                $monospaceTokens[$token] = '<code>'.$m[1].'</code>';

                return $token;
            },
            $escapedHtml
        );

        $text = preg_replace('/\*(\S(?:[^\n]*?\S)?)\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/_(\S(?:[^\n]*?\S)?)_/', '<em>$1</em>', $text);
        $text = preg_replace('/~(\S(?:[^\n]*?\S)?)~/', '<s>$1</s>', $text);

        return strtr($text, $monospaceTokens);
    }
}
