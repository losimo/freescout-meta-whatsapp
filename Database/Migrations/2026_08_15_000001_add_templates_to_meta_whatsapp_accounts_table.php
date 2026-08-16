<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTemplatesToMetaWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            // Multi-plantilla (issue #2, punts 2-4): llista JSON de fins a 5
            // plantilles configurades estàticament per l'admin, cadascuna amb
            // id/language/display_name/recovery_text. Quan és buida, el
            // compte cau al parell template_name/template_lang existent
            // (WhatsAppAccount::getTemplateList()) — cap migració de dades
            // necessària, instal·lacions existents es comporten igual.
            $table->text('templates')->nullable();
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['templates']);
        });
    }
}
