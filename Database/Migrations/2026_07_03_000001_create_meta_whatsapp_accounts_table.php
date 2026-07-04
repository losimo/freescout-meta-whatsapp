<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMetaWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::create('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('mailbox_id')->unique();
            $table->string('name', 100);
            $table->string('phone_number', 20);
            $table->string('phone_number_id', 50)->unique();
            $table->string('waba_id', 50);
            $table->text('access_token');
            $table->string('app_secret', 500);
            $table->string('verify_token', 64);
            $table->boolean('auto_created_mailbox')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('mailbox_id')
                ->references('id')->on('mailboxes')
                ->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_whatsapp_accounts');
    }
}
