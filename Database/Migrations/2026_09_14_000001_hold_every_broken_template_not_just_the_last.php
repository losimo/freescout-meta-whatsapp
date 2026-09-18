<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One broken template at a time was not a display limitation, it was a lie.
 * With two broken and one fixed, the panel went clean while the other was
 * still rejected, and under a rule that says rows appear only when there is
 * something to say, the missing row asserts there is nothing.
 *
 * The column now holds one entry per template, keyed by name and language.
 * The key is the identity and the summary is the display, so rewording the
 * panel can no longer stop the clearing from matching.
 *
 * `templates_issue_at` goes: each entry carries its own date. Nothing here
 * has ever been released, so there is nothing to migrate.
 */
class HoldEveryBrokenTemplateNotJustTheLast extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['templates_issue', 'templates_issue_at']);
        });

        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->text('templates_issue')->nullable();
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('templates_issue');
        });

        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->string('templates_issue', 191)->nullable();
            $table->timestamp('templates_issue_at')->nullable();
        });
    }
}
