<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional record of what Meta reports about the account: template status
 * changes, quality ratings, restrictions.
 *
 * The name carries the rule. It is not called "whatsapp events" because there
 * are none here: there are things Meta says about the account. A name
 * mentioning WhatsApp would invite someone to look for messages here and,
 * worse, to put them here. Named after the account, anyone who later wants to
 * store a customer's phone number has to argue against the name of the table
 * they are writing the INSERT into.
 *
 * Nothing in this table identifies a customer, which is what keeps it out of
 * docs/personal-data.md.
 */
class CreateMetaWhatsAppAccountEventsTable extends Migration
{
    public function up()
    {
        Schema::create('meta_whatsapp_account_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_id')->index();
            $table->string('event_type', 64);
            $table->string('severity', 16);
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            // No foreign key, consistent with meta_whatsapp_messages.
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_whatsapp_account_events');
    }
}
