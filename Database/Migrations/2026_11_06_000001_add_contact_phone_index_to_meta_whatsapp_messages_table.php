<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddContactPhoneIndexToMetaWhatsappMessagesTable extends Migration
{
    /**
     * The erasure screen resolves a person by iterating to a fixed point,
     * which means several lookups by phone per search, on a table that
     * grows one row per message. contact_user_id and wamid were indexed
     * from the start; contact_phone was not.
     *
     * No registerDoctrineTypeMapping('enum','string') here, unlike every
     * other migration on this table: that workaround is only needed by
     * ->change() on a table whose columns are typed enum, and
     * $table->index() does not go through Doctrine at all.
     */
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->index('contact_phone');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['contact_phone']);
        });
    }
}
