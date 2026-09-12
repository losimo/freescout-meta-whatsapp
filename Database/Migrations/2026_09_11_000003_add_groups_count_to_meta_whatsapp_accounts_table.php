<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many WhatsApp groups this business number belongs to, as of the last
 * connection test.
 *
 * The module never creates a group, so anything above zero means the number
 * was put in one through the API from somewhere else, and those messages are
 * being refused by the webhook. Worth surfacing rather than leaving the
 * administrator to find it in a log.
 *
 * Nullable on purpose: null means never checked, which is not the same as
 * checked and found none.
 */
class AddGroupsCountToMetaWhatsAppAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->unsignedInteger('groups_count')->nullable();
            $table->timestamp('groups_checked_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['groups_count', 'groups_checked_at']);
        });
    }
}
