<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Closes the leak on installations that answered a right-of-erasure
 * request before the identifier was being made unreadable (T02).
 *
 * Those rows cannot be reached by the erasure screen, because the screen
 * searches by identity and identity is precisely what they no longer
 * have. The receipt they produced said the identifiers were kept on
 * purpose and harmless, so this migration is what makes that sentence
 * true after the fact.
 *
 * Which rows: no identity left in either column, and an identifier that
 * came from Meta (the `wamid.` prefix). A row that still holds an
 * identity is a live row and keeps Meta's id, which an administrator
 * needs when raising anything with Meta. A synthetic identifier from a
 * send that never reached Meta (`failed-thread-N`) carries no phone
 * number, so there is nothing to close there. No live row matches this
 * filter: every inbound row is written with a phone number or a BSUID,
 * and every outbound row that got an id back from Meta is written with
 * the recipient's number.
 *
 * This cannot be undone, and down() says so rather than pretending. The
 * transformation is one-way by design, and what it replaces was a phone
 * number in disguise.
 *
 * Batched by id because the table grows one row per message, and a
 * single UPDATE across the whole table on a busy installation is a lock
 * nobody asked for. The value is computed in PHP and not in SQL so that
 * one definition of the transformation exists, in
 * WhatsAppMessage::erasedWamid().
 */
class HashWamidOfAlreadyErasedRows extends Migration
{
    const BATCH = 500;

    public function up()
    {
        $lastId = 0;

        while (true) {
            $rows = DB::table('meta_whatsapp_messages')
                ->select('id', 'wamid')
                ->whereNull('contact_phone')
                ->whereNull('contact_user_id')
                ->where('wamid', 'like', 'wamid.%')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            if (count($rows) === 0) {
                break;
            }

            foreach ($rows as $row) {
                DB::table('meta_whatsapp_messages')
                    ->where('id', $row->id)
                    ->update(['wamid' => WhatsAppMessage::erasedWamid($row->wamid)]);
                $lastId = $row->id;
            }
        }
    }

    public function down()
    {
        // Deliberately empty. The values this replaced were one-way
        // hashed, and they were a phone number in disguise.
    }
}
