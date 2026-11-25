<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time Meta says the message happened, which is the only clock that
 * decides the 24-hour customer service window.
 *
 * Until now both window clocks ran on created_at, the moment this module's
 * queue worker wrote the row. The two agree only when nothing is delayed.
 * A stopped queue, a job burning its retries, or Meta redelivering after an
 * endpoint came back all push created_at forward, and the error always falls
 * the same way: the module believes the window is more open than it is.
 *
 * The expensive part is not a failed send. The template guard refuses the
 * template when it thinks the window is open, which tells the agent to write
 * an ordinary reply instead, and that reply dies with a 131047 while the
 * customer receives nothing.
 *
 * Nullable, and deliberately not backfilled: the clocks read
 * MAX(COALESCE(meta_sent_at, created_at)), so every row written before this
 * migration keeps behaving exactly as it does today, and a payload that ever
 * arrives without a usable timestamp does too.
 */
class AddMetaSentAtToMetaWhatsAppMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->timestamp('meta_sent_at')->nullable()->after('read_at');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('meta_sent_at');
        });
    }
}
