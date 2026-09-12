<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in switch for the monthly service message counter.
 *
 * Per account rather than global, because Meta's allowance is per business
 * phone number: one number may also be used from the WhatsApp Business app
 * while another is only ever used from here.
 *
 * Off by default. A counter that appears on its own invites comparison
 * with Meta's bill, and the difference would be reported as our bug.
 */
class AddUsageCounterToMetaWhatsAppAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('usage_counter_enabled')->default(false);
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('usage_counter_enabled');
        });
    }
}
