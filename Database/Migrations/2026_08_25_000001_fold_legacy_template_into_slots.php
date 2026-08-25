<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Plega la plantilla única heretada (template_name/template_lang, v1.3.0)
 * dins la llista de ranures (v1.6.0) i elimina les dues columnes.
 *
 * Per què ara: a getTemplateList() les dues fonts eren un o l'altre, no
 * una fusió. Amb una sola ranura vàlida, el parell heretat no es llegia
 * mai, i el formulari el presentava primer, més gran i amb més ajuda que
 * el mecanisme que sí que guanya. El reportador de l'issue #2 ho va
 * demanar i va confirmar que al seu entorn ja no el fan servir.
 *
 * Guardada i idempotent: no toca res si les columnes ja no hi són, i mai
 * sobreescriu una ranura amb contingut.
 */
class FoldLegacyTemplateIntoSlots extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('meta_whatsapp_accounts', 'template_name')) {
            return;
        }

        foreach (DB::table('meta_whatsapp_accounts')->get() as $account) {
            $this->foldOne($account);
        }

        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'template_lang']);
        });
    }

    protected function foldOne($account): void
    {
        $name = trim((string) ($account->template_name ?? ''));
        $lang = trim((string) ($account->template_lang ?? ''));

        // Mitja parella no produïa cap plantilla utilitzable, així que no
        // hi ha res a conservar.
        if ($name === '' || $lang === '') {
            return;
        }

        $slots = json_decode((string) ($account->templates ?? ''), true);
        $slots = is_array($slots) ? $slots : [];

        // Ja hi és: la migració s'ha pogut executar abans, o l'admin l'hi
        // havia copiat a mà.
        foreach ($slots as $slot) {
            if (is_array($slot)
                && trim((string) ($slot['id'] ?? '')) === $name
                && trim((string) ($slot['language'] ?? '')) === $lang
            ) {
                return;
            }
        }

        $free = $this->firstFreeSlot($slots);

        if ($free === null) {
            // Les cinc ranures plenes. El valor heretat ja era inabastable
            // en aquest estat (les ranures guanyen), així que no es perd
            // res en ús, però queda al registre per si algú el vol.
            Log::warning('[MetaWhatsApp] Legacy template dropped: all five slots are in use', [
                'account_id'     => $account->id,
                'template_name'  => $name,
                'template_lang'  => $lang,
            ]);
            return;
        }

        $slots[$free] = [
            'id'            => $name,
            'language'      => $lang,
            'display_name'  => $name,
            'recovery_text' => null,
        ];

        DB::table('meta_whatsapp_accounts')
            ->where('id', $account->id)
            ->update(['templates' => json_encode(array_values($slots))]);
    }

    /**
     * Primera posició sense id o sense idioma, que és el que getTemplateList()
     * considera inservible i per tant lliure.
     */
    protected function firstFreeSlot(array $slots): ?int
    {
        for ($i = 0; $i < 5; $i++) {
            $slot = $slots[$i] ?? null;
            if (!is_array($slot) || empty($slot['id']) || empty($slot['language'])) {
                return $i;
            }
        }

        return null;
    }

    public function down()
    {
        if (Schema::hasColumn('meta_whatsapp_accounts', 'template_name')) {
            return;
        }

        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->string('template_name', 512)->nullable();
            $table->string('template_lang', 15)->nullable();
        });

        // Les columnes tornen buides a propòsit: el valor viu ara a una
        // ranura i tornar-lo a copiar aquí crearia dues fonts per al
        // mateix, que és exactament el problema que aquesta migració
        // elimina.
    }
}
