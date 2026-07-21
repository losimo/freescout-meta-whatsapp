<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAttachmentIdToMetaWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            // Permet més d'un missatge de sortida per thread (un per adjunt
            // multimèdia), a diferència del text pla (un únic outbound/thread).
            $table->unsignedBigInteger('attachment_id')->nullable()->index()->after('thread_id');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('attachment_id');
        });
    }
}
