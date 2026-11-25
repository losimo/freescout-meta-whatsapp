<?php

namespace Modules\MetaWhatsApp\Tests;

use Modules\MetaWhatsApp\Support\WhatsAppTextFormatter;

class WhatsAppTextFormatterTest extends TestCase
{
    public function test_renderitza_negreta_cursiva_ratllat_i_monoespai()
    {
        $this->assertSame('<strong>hola</strong>', WhatsAppTextFormatter::format('*hola*'));
        $this->assertSame('<em>hola</em>', WhatsAppTextFormatter::format('_hola_'));
        $this->assertSame('<s>hola</s>', WhatsAppTextFormatter::format('~hola~'));
        $this->assertSame('<code>hola</code>', WhatsAppTextFormatter::format('```hola```'));
    }

    public function test_renderitza_format_combinat_negreta_i_cursiva_imbricats()
    {
        $this->assertSame(
            '<strong><em>hola</em></strong>',
            WhatsAppTextFormatter::format('*_hola_*')
        );
    }

    public function test_renderitza_multiples_parells_independents_a_la_mateixa_linia()
    {
        $this->assertSame(
            '<strong>a</strong> i <em>b</em>',
            WhatsAppTextFormatter::format('*a* i _b_')
        );
    }

    public function test_no_renderitza_format_dins_de_monoespai()
    {
        $this->assertSame(
            '<code>_no_ *canvia*</code>',
            WhatsAppTextFormatter::format('```_no_ *canvia*```')
        );
    }

    public function test_no_renderitza_delimitador_amb_espai_immediatament_dins()
    {
        // Regla de WhatsApp: '* text*' o '*text *' no compten com a format.
        $this->assertSame('* text*', WhatsAppTextFormatter::format('* text*'));
        $this->assertSame('*text *', WhatsAppTextFormatter::format('*text *'));
    }

    public function test_ignora_asterisc_solt_sense_parella()
    {
        $this->assertSame('preu: 5€ *iva incl.', WhatsAppTextFormatter::format('preu: 5€ *iva incl.'));
    }

    public function test_treballa_sobre_text_ja_escapat_amb_htmlspecialchars()
    {
        $escaped = htmlspecialchars('<script>alert(1)</script> *bold*', ENT_QUOTES, 'UTF-8');

        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; <strong>bold</strong>',
            WhatsAppTextFormatter::format($escaped)
        );
    }

    /**
     * The four cases reported on issue #48. Each one used to be stored
     * mangled, and the mangled URL was then linkified by FreeScout into a
     * link pointing somewhere else.
     */
    public function test_leaves_a_url_with_underscores_untouched()
    {
        $this->assertSame(
            'https://example.com/my_page_name?x=1',
            WhatsAppTextFormatter::format('https://example.com/my_page_name?x=1')
        );
    }

    public function test_leaves_an_email_with_underscores_untouched()
    {
        $this->assertSame(
            'john_doe_smith@example.com',
            WhatsAppTextFormatter::format('john_doe_smith@example.com')
        );
    }

    public function test_leaves_a_multiplication_untouched()
    {
        $this->assertSame('2*3*4 = 24', WhatsAppTextFormatter::format('2*3*4 = 24'));
    }

    public function test_leaves_a_file_name_with_underscores_untouched()
    {
        $this->assertSame('file_name_v2.pdf', WhatsAppTextFormatter::format('file_name_v2.pdf'));
    }

    public function test_leaves_every_marker_inside_a_url_untouched()
    {
        $url = 'https://example.com/a_b_c/d*e*f/g~h~i';

        $this->assertSame($url, WhatsAppTextFormatter::format($url));
    }

    public function test_leaves_markers_at_a_word_boundary_inside_a_url_untouched()
    {
        // The underscores here sit at a word boundary, so only taking the
        // URL out of the text saves this one.
        $this->assertSame(
            'https://example.com/_foo_',
            WhatsAppTextFormatter::format('https://example.com/_foo_')
        );
    }

    public function test_leaves_an_escaped_query_string_untouched()
    {
        $escaped = htmlspecialchars(
            'https://example.com/s?a=1&b_c_d=2&e=*3*',
            ENT_QUOTES,
            'UTF-8'
        );

        $this->assertSame($escaped, WhatsAppTextFormatter::format($escaped));
    }

    public function test_formats_around_an_email_in_the_same_sentence()
    {
        $escaped = htmlspecialchars(
            'Write to john_doe_smith@example.com and put *urgent* in the subject',
            ENT_QUOTES,
            'UTF-8'
        );

        $this->assertSame(
            'Write to john_doe_smith@example.com and put <strong>urgent</strong> in the subject',
            WhatsAppTextFormatter::format($escaped)
        );
    }

    public function test_formats_around_a_url_in_the_same_sentence()
    {
        $escaped = htmlspecialchars(
            'See https://example.com/my_page_name?a=1&b=2 and reply _today_',
            ENT_QUOTES,
            'UTF-8'
        );

        $this->assertSame(
            'See https://example.com/my_page_name?a=1&amp;b=2 and reply <em>today</em>',
            WhatsAppTextFormatter::format($escaped)
        );
    }

    public function test_still_formats_markers_next_to_punctuation()
    {
        $this->assertSame('(<strong>bold</strong>)', WhatsAppTextFormatter::format('(*bold*)'));
        $this->assertSame(
            '&quot;<strong>bold</strong>&quot;',
            WhatsAppTextFormatter::format(htmlspecialchars('"*bold*"', ENT_QUOTES, 'UTF-8'))
        );
        $this->assertSame('<strong>bold</strong>.', WhatsAppTextFormatter::format('*bold*.'));
        $this->assertSame('<em>a</em>, <s>b</s>!', WhatsAppTextFormatter::format('_a_, ~b~!'));
    }

    public function test_still_formats_a_url_the_agent_wrapped_in_a_marker()
    {
        $this->assertSame(
            '<strong>https://example.com/my_page_name</strong>',
            WhatsAppTextFormatter::format('*https://example.com/my_page_name*')
        );
    }
}
