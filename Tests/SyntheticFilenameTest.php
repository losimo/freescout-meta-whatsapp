<?php

namespace Modules\MetaWhatsApp\Tests;

use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;

/**
 * The name we invent for a file that arrives without one.
 *
 * Voice notes arrive as `audio/ogg; codecs=opus`, a media type with a
 * parameter. The extension was taken from everything after the slash and
 * then stripped of anything that is not a letter or a digit, so the
 * parameter came along and the agent was offered
 * `audio_68f3c1.oggcodecsopus`, which no player opens and no one can
 * rename back. Reported by MarkoD (T20).
 *
 * The parameter is the defect. The length cap below is not part of it: it
 * stops the same line inventing a sixty-character extension out of a
 * `vnd.openxmlformats-...` document type, which is the same mistake in a
 * different disguise. No media-type-to-extension table is attempted here on
 * purpose, because guessing an extension we do not know is worse than
 * admitting we do not: the browser serves the file by its stored media
 * type either way.
 */
class SyntheticFilenameTest extends TestCase
{
    protected function syntheticFilename(string $type, string $mimeType): string
    {
        $job    = new ProcessInboundWebhook(1, []);
        $method = new \ReflectionMethod($job, 'syntheticFilename');
        $method->setAccessible(true);

        return $method->invoke($job, $type, $mimeType);
    }

    protected function extensionOf(string $filename): string
    {
        $parts = explode('.', $filename);

        return end($parts);
    }

    public function test_a_media_type_parameter_does_not_reach_the_extension()
    {
        $this->assertSame(
            'ogg',
            $this->extensionOf($this->syntheticFilename('audio', 'audio/ogg; codecs=opus')),
            'the codecs parameter of a voice note is still part of the file name'
        );
    }

    /** The parameter is optional-space in the grammar, so both forms arrive. */
    public function test_the_same_holds_without_the_space()
    {
        $this->assertSame(
            'ogg',
            $this->extensionOf($this->syntheticFilename('audio', 'audio/ogg;codecs=opus'))
        );
    }

    public function test_an_ordinary_media_type_is_unchanged()
    {
        $this->assertSame('jpeg', $this->extensionOf($this->syntheticFilename('image', 'image/jpeg')));
        $this->assertSame('mp4', $this->extensionOf($this->syntheticFilename('video', 'video/mp4')));
        $this->assertSame('pdf', $this->extensionOf($this->syntheticFilename('document', 'application/pdf')));
    }

    /**
     * A document sent without a filename of its own. There is no honest
     * extension to derive from this, and the one the old line produced was
     * fifty-three characters of run-together words.
     */
    public function test_an_unguessable_type_says_so_instead_of_inventing()
    {
        $this->assertSame(
            'bin',
            $this->extensionOf($this->syntheticFilename(
                'document',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ))
        );
    }

    public function test_a_type_with_no_subtype_at_all_falls_back()
    {
        $this->assertSame('bin', $this->extensionOf($this->syntheticFilename('document', 'application')));
        $this->assertSame('bin', $this->extensionOf($this->syntheticFilename('document', '')));
    }

    /** The name still carries what it carried: the kind, and something unique. */
    public function test_the_name_keeps_its_shape()
    {
        $name = $this->syntheticFilename('audio', 'audio/ogg; codecs=opus');

        $this->assertStringStartsWith('audio_', $name);
        $this->assertNotSame(
            $name,
            $this->syntheticFilename('audio', 'audio/ogg; codecs=opus'),
            'two files arriving together would be given the same name'
        );
    }
}
