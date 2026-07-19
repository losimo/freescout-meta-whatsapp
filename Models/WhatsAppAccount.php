<?php

namespace Modules\MetaWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAccount extends Model
{
    /**
     * Canal enter propi del mòdul a customer_channel (el core no en defineix cap;
     * la columna és unsignedTinyInteger). El nom visible es registra via el
     * filter Eventy 'channel.name'.
     */
    const CHANNEL = 100;
    const CHANNEL_NAME = 'WhatsApp';

    /**
     * Canal dedicat per als BSUID (user_id de Meta): el core només permet una
     * fila de customer_channel per client i canal, així que el telèfon viu al
     * canal 100 i el BSUID en aquest.
     */
    const CHANNEL_BSUID      = 101;
    const CHANNEL_BSUID_NAME = 'WhatsApp ID';

    protected $table = 'meta_whatsapp_accounts';

    protected $fillable = [
        'mailbox_id',
        'name',
        'phone_number',
        'phone_number_id',
        'waba_id',
        'verify_token',
        'auto_created_mailbox',
        'is_active',
        'template_name',
        'template_lang',
        'template_threshold_minutes',
    ];

    // access_token i app_secret mai fillable: s'assignen explícitament amb encrypt().

    protected $casts = [
        'auto_created_mailbox' => 'boolean',
        'is_active'            => 'boolean',
    ];

    public function mailbox()
    {
        return $this->belongsTo(\App\Mailbox::class);
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'account_id');
    }

    /**
     * Estat per al llistat: 'active', 'inactive' o 'orphan' (bústia desvinculada).
     */
    public function getStatus(): string
    {
        if (!$this->mailbox) {
            return 'orphan';
        }
        return $this->is_active ? 'active' : 'inactive';
    }
}
