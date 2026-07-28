<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDeliveredReadTimestampsToMetaWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('status');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'read_at']);
        });
    }
}
