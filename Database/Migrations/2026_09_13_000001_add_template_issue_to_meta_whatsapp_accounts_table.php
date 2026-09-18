<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta can reject, pause or disable an approved template at any time, and it
 * tells us over the webhook. Until now nothing read that, so the first sign
 * was a send failing.
 *
 * One template at a time on purpose: the column is the current state, not a
 * history. With two broken templates the panel names the latest, because the
 * administrator's next move is the same either way, open WhatsApp Manager.
 */
class AddTemplateIssueToMetaWhatsAppAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->string('templates_issue', 191)->nullable();
            $table->timestamp('templates_issue_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['templates_issue', 'templates_issue_at']);
        });
    }
}
