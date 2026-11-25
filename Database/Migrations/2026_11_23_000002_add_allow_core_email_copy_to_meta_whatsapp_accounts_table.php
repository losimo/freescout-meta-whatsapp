<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in switch for the core's "Also send reply to customer by email"
 * checkbox on this channel's conversations.
 *
 * The defect behind #57 was not that the checkbox existed, it was that it
 * arrived ticked: an agent replying in a WhatsApp mailbox outside chat
 * mode sent the same answer twice, over WhatsApp and by e-mail, without
 * choosing to and without being told. The module answered by dropping the
 * flag server-side for every WhatsApp mailbox, which closed the accident
 * and also took the feature away from anyone who wanted it. MarkoD asked
 * for it back, and he is right: the core's own default is to offer it
 * (`config/app.php`, `chat_conversation_reply_by_email`, defaults to
 * true), so refusing it for everyone is the module overruling the core.
 *
 * Off by default, because the accident has to stay closed for an
 * installation that never thinks about this. Per channel and not global,
 * like every other switch here: one number may be the only way a business
 * talks to a customer while another doubles a formal confirmation by
 * e-mail.
 *
 * The administrator's intent cannot be read from the core's parameter,
 * which is why this column exists: that parameter is on by default, so it
 * records nobody's decision.
 */
class AddAllowCoreEmailCopyToMetaWhatsAppAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('allow_core_email_copy')->default(false);
        });
    }

    public function down()
    {
        Schema::table('meta_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('allow_core_email_copy');
        });
    }
}
