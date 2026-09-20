<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same story as contact_user_id, found before a customer had to report it.
 *
 * Meta does not document a maximum length for a wamid. The column held 100,
 * and FreeScout turns MySQL's strict mode off in its own config/database.php,
 * so a longer id is not refused: it is silently cut to 100 and stored. From
 * that point the message is filed under an id that is not its own, delivery
 * and read receipts no longer match it, and two ids sharing their first 100
 * characters collide on this unique index, where the duplicate-key error is
 * read as "already processed" and the message is dropped without a word.
 *
 * 191 rather than something larger: the column is indexed, and 191 is the
 * longest utf8mb4 string MySQL will index under the older 767-byte key limit.
 *
 * Nothing is migrated: ids already truncated cannot be recovered, since the
 * missing characters were never stored.
 */
class WidenWamidOnMetaWhatsAppMessagesTable extends Migration
{
    public function up()
    {
        // El canvi de tipus passa per Doctrine, que no coneix `enum` i peta en
        // llegir la taula sencera (direction/status ho són). Mapatge previ.
        Schema::getConnection()->getDoctrineSchemaManager()
            ->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->string('wamid', 191)->change();
        });
    }

    public function down()
    {
        Schema::getConnection()->getDoctrineSchemaManager()
            ->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->string('wamid', 100)->change();
        });
    }
}
