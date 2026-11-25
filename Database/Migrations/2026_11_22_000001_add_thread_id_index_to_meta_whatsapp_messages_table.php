<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddThreadIdIndexToMetaWhatsappMessagesTable extends Migration
{
    /**
     * Issue #52, reported by MarkoD. Nine production sites filter this table
     * by thread_id, all of them on the path that answers an agent pressing
     * Send: the three send jobs each ask once before dispatching and once in
     * failed(), and the reply dispatcher asks three times. The table grows
     * one row per message and never shrinks, so every one of those lookups
     * was a full scan that gets slower for as long as the install is used.
     *
     * Plain index and not a composite with `direction`, although the hottest
     * queries filter on both: a thread carries one outbound message, so
     * thread_id alone already narrows the scan to a row or two, and the
     * second column would only add write cost on a table written once per
     * message. Three of the nine sites filter by thread_id alone anyway.
     *
     * No registerDoctrineTypeMapping('enum','string') here, unlike the
     * migrations on this table that use ->change(): that workaround is only
     * needed by Doctrine, and $table->index() does not go through it.
     */
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->index('thread_id');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['thread_id']);
        });
    }
}
