<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConversationSubjectTemplateToMetaWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->string('conversation_subject_template', 190)->nullable()->after('name');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('conversation_subject_template');
        });
    }
}
