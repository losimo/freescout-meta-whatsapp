<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMetaWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::create('meta_whatsapp_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('wamid', 100)->unique();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('thread_id')->nullable();
            $table->string('contact_phone', 20);
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('status', ['received', 'sent', 'delivered', 'read', 'failed'])
                ->default('received');
            $table->string('error_code', 20)->nullable();
            $table->timestamps();

            // Sense FK a taules del core (conversations/threads): només índexs.
            $table->index('conversation_id');
            $table->index('account_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_whatsapp_messages');
    }
}
