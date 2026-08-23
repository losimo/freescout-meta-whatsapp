<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFailureNotedAtToMetaWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            // Idempotència de la nota de fallida asíncrona (issue #19). Abans
            // es deduïa buscant el wamid dins el body dels threads amb LIKE;
            // en treure el wamid del text visible (ara hi va un extracte del
            // missatge), aquella clau desapareixia. Marcar-ho a la pròpia
            // fila és més robust i, com que és per missatge i no per
            // conversa, cada wamid fallit d'una tanda manté la seva nota.
            $table->timestamp('failure_noted_at')->nullable()->after('read_at');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('failure_noted_at');
        });
    }
}
