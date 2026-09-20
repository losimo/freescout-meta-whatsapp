<?php

namespace Modules\MetaWhatsApp\Tests;

/**
 * El README promet que el mòdul va amb PHP 7.1, que és el terra que declara
 * FreeScout. El 2026-09-18 una auditoria va trobar un `[...$array, 'x']` a
 * ProcessInboundWebhook, que és de PHP 7.4: amb 7.1, 7.2 o 7.3 aquell fitxer
 * ni es parsejava. I com que només es carrega en processar un webhook, la
 * pantalla de configuració anava bé, el canal es desava, el handshake de Meta
 * passava, i els missatges entrants es perdien amb un 500 que no es veu des
 * de dins del FreeScout.
 *
 * Això no és un analitzador sintàctic: el contenidor va amb PHP 8.2 i no pot
 * parsejar com si fos 7.1. És una xarxa que caça les construccions que se'ns
 * colen més fàcilment. La comprovació de veritat és:
 *
 *   docker run --rm -v "$PWD:/m:ro" php:7.1-cli sh -c
 *     'find /m -name "[*].php" | while read f; do php -l "$f"; done'
 *
 * (excloent-ne el directori Tests, i amb l'asterisk escrit entre claudàtors
 *  perquè una barra i un asterisc dins d'un comentari de bloc el tancarien.)
 */
class PhpFloorTest extends TestCase
{
    /** Construccions posteriors a PHP 7.1, amb la versió que les va introduir. */
    const FORBIDDEN = [
        '/\[\s*\.\.\./'                              => '7.4: spread dins d\'un literal d\'array',
        '/(?<![\w$])fn\s*\(/'                        => '7.4: funció fletxa',
        '/(?<![\w$])\?\?=/'                          => '7.4: assignació amb ??=',
        '/\?->/'                                     => '8.0: operador nullsafe',
        '/(?<![\w$])match\s*\(/'                     => '8.0: expressió match',
        '/\)\s*:\s*mixed/'                           => '8.0: tipus de retorn mixed',
        '/\)\s*:\s*\??\w+\s*\|/'                     => '8.0: tipus de retorn d\'unió',
        '/^\s*#\[/m'                                 => '8.0: atribut',
        '/(?<![\w$])(enum|readonly)\s+\w/'            => '8.1',
    ];

    protected function moduleFiles(): array
    {
        $base  = realpath(__DIR__ . '/..');
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            // Les plantilles queden fora: 'readonly' i 'disabled' són atributs
            // d'HTML corrents, i les construccions que vigilem aquí viuen als
            // fitxers de classe, no a les vistes.
            if (strpos($path, '/Tests/') !== false
                || strpos($path, '/vendor/') !== false
                || substr($path, -10) === '.blade.php') {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    public function test_no_syntax_newer_than_the_php_floor_we_promise()
    {
        $files = $this->moduleFiles();

        // Que el recorregut funciona es comprova trobant un fitxer que hi ha
        // de ser, no comptant-ne un nombre rodó que caduca cada vegada que el
        // mòdul creix o s'aprima.
        $names = array_map('basename', $files);
        $this->assertContains('ProcessInboundWebhook.php', $names, 'El recorregut de fitxers no arriba on hauria.');

        $found = [];

        foreach ($files as $path) {
            $code = file_get_contents($path);

            foreach (self::FORBIDDEN as $pattern => $why) {
                if (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                    $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
                    $found[] = basename($path) . ':' . $line . '  ' . $why;
                }
            }
        }

        $this->assertEmpty(
            $found,
            "Sintaxi més nova que el PHP 7.1 que promet el README:\n  " . implode("\n  ", $found)
        );
    }
}
