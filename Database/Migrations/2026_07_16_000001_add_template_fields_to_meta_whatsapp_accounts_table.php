<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTemplateFieldsToMetaWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            // MVP «expired window recovery» (issue #2): una sola plantilla
            // predefinida per compte. El llindar és un marge operatiu intern,
            // NO la regla de 24 h de Meta.
            $table->string('template_name', 512)->nullable();
            $table->string('template_lang', 15)->nullable();
            $table->unsignedInteger('template_threshold_minutes')->default(1435);
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'template_lang', 'template_threshold_minutes']);
        });
    }
}
