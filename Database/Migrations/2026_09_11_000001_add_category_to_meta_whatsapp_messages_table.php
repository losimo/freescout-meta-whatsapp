<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta bills outbound messages by category, and from 1 October 2026 the
 * monthly free allowance covers only service messages. Until now every
 * outbound row looked the same, so the record could not tell a reply from
 * a template.
 *
 * Text rather than a boolean on purpose: Meta's own finer categories
 * (utility, marketing, authentication) fit here later without another
 * migration. Nullable, and nothing is backfilled: rows written before
 * this migration stay unknown rather than being guessed at.
 */
class AddCategoryToMetaWhatsAppMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('direction');
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
}
