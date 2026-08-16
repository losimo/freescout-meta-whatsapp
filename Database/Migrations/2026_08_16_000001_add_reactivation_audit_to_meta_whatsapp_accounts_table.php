<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddReactivationAuditToMetaWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            // Guided reactivation audit trail (issue #9): when Test connection
            // succeeds and the account was inactive, it's reactivated
            // automatically. These two columns are the audit trail requested
            // in the issue — who/when the last recovery happened.
            $table->timestamp('reactivated_at')->nullable();
            $table->unsignedInteger('reactivated_by')->nullable();
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['reactivated_at', 'reactivated_by']);
        });
    }
}
