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
}
