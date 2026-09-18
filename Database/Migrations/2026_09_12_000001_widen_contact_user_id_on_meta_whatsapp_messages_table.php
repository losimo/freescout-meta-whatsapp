<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-scoped user IDs are longer than this column allowed.
 *
 * Meta's specification is a two-letter ISO country code, a period, and up to
 * 128 alphanumeric characters, so up to 131. The column held 100, and the
 * webhook refused anything longer; when that message carried no phone number
 * there was no other way to resolve the sender, so the message was discarded
 * and the customer's words never appeared anywhere.
 *
 * 191 rather than 131: the column is indexed, and 191 is the longest utf8mb4
 * string MySQL will index under the older 767-byte key limit. It leaves
 * headroom over Meta's current ceiling without risking the index.
 */
class WidenContactUserIdOnMetaWhatsAppMessagesTable extends Migration
{
    public function up()
    {
        // El canvi de tipus passa per Doctrine, que no coneix `enum` i peta en
        // llegir la taula sencera (direction/status ho són). Mapatge previ.
        Schema::getConnection()->getDoctrineSchemaManager()
            ->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->string('contact_user_id', 191)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::getConnection()->getDoctrineSchemaManager()
            ->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->string('contact_user_id', 100)->nullable()->change();
        });
    }
}
