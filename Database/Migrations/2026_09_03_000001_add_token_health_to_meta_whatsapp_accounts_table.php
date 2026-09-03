<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the module say when the access token expires, instead of the
 * administrator finding out through error 190 when a message does not arrive.
 *
 * Meta's /debug_token answers that, but it will not accept the token being
 * inspected as its own credential: it needs an app access token, which is
 * `<APP_ID>|<APP_SECRET>`. The secret was already stored, the App ID was not,
 * and it sits on the same Meta screen the help text already points at.
 *
 * Both columns are nullable on purpose. Accounts created before this release
 * have no App ID, so no check runs and nothing changes for them until someone
 * fills it in.
 */
class AddTokenHealthToMetaWhatsAppAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('meta_whatsapp_accounts', 'app_id')) {
                $table->string('app_id', 50)->nullable()->after('waba_id');
            }
            // Null means "not checked". A stored expiry does not go stale the
            // way a validity flag would: the date a token expires does not
            // move, so it is worth persisting and re-reading for free, while
            // validity and permissions are reported live when they are asked
            // for and never written down.
            if (!Schema::hasColumn('meta_whatsapp_accounts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('app_id');
            }
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            foreach (['app_id', 'token_expires_at'] as $column) {
                if (Schema::hasColumn('meta_whatsapp_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
