<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

class MigrateMetaWhatsappChannelIds extends Migration
{
    // IDs provisionals usats fins a la v1.5.0 i IDs oficials assignats per
    // l'equip de FreeScout (issue #4 del mòdul).
    const OLD_PHONE = 100;
    const NEW_PHONE = 103;
    const OLD_BSUID = 101;
    const NEW_BSUID = 104;

    public function up()
    {
        $this->remap(self::OLD_PHONE, self::NEW_PHONE, self::OLD_BSUID, self::NEW_BSUID);
    }

    public function down()
    {
        $this->remap(self::NEW_PHONE, self::OLD_PHONE, self::NEW_BSUID, self::OLD_BSUID);
    }

    protected function remap($fromPhone, $toPhone, $fromBsuid, $toBsuid)
    {
        foreach (['customer_channel', 'customers'] as $table) {
            // Canal de telèfon: NOMÉS les files d'aquest mòdul. El canal 100
            // el poden usar altres mòduls (és el conflicte que resolem):
            // una identitat és nostra si apareix a meta_whatsapp_messages.
            DB::table($table)
                ->where('channel', $fromPhone)
                ->whereIn('channel_id', function ($query) {
                    $query->select('contact_phone')
                        ->from('meta_whatsapp_messages')
                        ->whereNotNull('contact_phone');
                })
                ->update(['channel' => $toPhone]);

            // Canal BSUID: el va introduir aquest mòdul (cap altre l'usa).
            DB::table($table)
                ->where('channel', $fromBsuid)
                ->update(['channel' => $toBsuid]);
        }
    }
}
