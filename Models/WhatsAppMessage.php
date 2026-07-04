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
        'contact_phone',
        'direction',
        'status',
        'error_code',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'account_id');
    }
}
