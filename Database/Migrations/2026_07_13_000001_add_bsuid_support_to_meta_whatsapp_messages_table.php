<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBsuidSupportToMetaWhatsappMessagesTable extends Migration
{
    public function up()
    {
        // ->change() amb doctrine/dbal peta si la taula té columnes enum
        // (direction/status) i el tipus no està mapejat.
        $this->mapEnumType();

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            // Business-Scoped User ID (BSUID) de Meta: contacts[].user_id.
            // Indexat per al lookup de la Fase 2 (resolució de customer).
            $table->string('contact_user_id', 100)->nullable()->index()->after('contact_phone');
        });

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            // Un inbound d'usuari amb número ocult arriba sense telèfon usable
            // i, a la Fase 1, sense conversa resoluble: cal poder persistir el
            // missatge igualment.
            $table->string('contact_phone', 20)->nullable()->change();
            $table->unsignedBigInteger('conversation_id')->nullable()->change();
        });
    }

    public function down()
    {
        $this->mapEnumType();

        // Les files només-BSUID no són representables amb l'esquema antic.
        DB::table('meta_whatsapp_messages')
            ->whereNull('contact_phone')
            ->orWhereNull('conversation_id')
            ->delete();

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->string('contact_phone', 20)->nullable(false)->change();
            $table->unsignedBigInteger('conversation_id')->nullable(false)->change();
        });

        Schema::table('meta_whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['contact_user_id']);
            $table->dropColumn('contact_user_id');
        });
    }

    protected function mapEnumType()
    {
        Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->getDatabasePlatform()
            ->registerDoctrineTypeMapping('enum', 'string');
    }
}
