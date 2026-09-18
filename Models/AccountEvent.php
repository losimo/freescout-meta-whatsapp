<?php

namespace Modules\MetaWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class AccountEvent extends Model
{
    const UPDATED_AT = null;

    protected $table = 'meta_whatsapp_account_events';

    protected $fillable = ['account_id', 'event_type', 'severity', 'details'];

    protected $casts = ['created_at' => 'datetime'];
}
