<?php

namespace Modules\MetaWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    const DIRECTION_INBOUND  = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    const STATUS_RECEIVED  = 'received';
    const STATUS_SENT      = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ      = 'read';
    const STATUS_FAILED    = 'failed';

    protected $table = 'meta_whatsapp_messages';

    protected $fillable = [
        'wamid',
        'account_id',
        'conversation_id',
        'thread_id',
        'attachment_id',
        'contact_phone',
        'contact_user_id',
        'direction',
        'status',
        'error_code',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'account_id');
    }

    /**
     * La finestra de servei s'ha de tractar com a expirada per a aquesta
     * conversa? Es basa en l'últim missatge inbound i el llindar operatiu
     * del compte (marge intern; la regla real de Meta són 24 h).
     */
    public static function windowExpired(int $conversationId, WhatsAppAccount $account): bool
    {
        $last = static::where('conversation_id', $conversationId)
            ->where('direction', static::DIRECTION_INBOUND)
            ->max('created_at');

        if (!$last) {
            return true;
        }

        $threshold = (int) ($account->template_threshold_minutes ?: 1435);

        return \Carbon\Carbon::parse($last)->lt(now()->subMinutes($threshold));
    }
}
