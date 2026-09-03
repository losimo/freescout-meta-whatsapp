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
        'delivered_at',
        'read_at',
        'failure_noted_at',
    ];

    protected $casts = [
        'delivered_at'     => 'datetime',
        'read_at'          => 'datetime',
        'failure_noted_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'account_id');
    }

    /**
     * La finestra de servei s'ha de tractar com a expirada per a aquesta
     * conversa? Es basa en l'últim missatge inbound i el llindar operatiu
     * del compte.
     *
     * ATENCIÓ, això NO és la regla de Meta i no s'ha d'"arreglar" perquè ho
     * sigui. Meta compta 24 hores exactes des de l'últim missatge del client;
     * nosaltres comptem `template_threshold_minutes`, que per defecte són
     * 1435 minuts, o sigui 23 h 55 min. Els cinc minuts de diferència són
     * deliberats: entre que el treballador de la cua agafa la feina i Meta
     * rep la petició hi passa temps, i enviar text lliure quan la finestra
     * acaba de tancar-se és una entrega fallida amb 131047 en comptes d'una
     * plantilla que sí que arriba. Val més tancar abans que Meta i no
     * després.
     *
     * L'administrador pot moure el llindar de 1 a 1440 minuts des del
     * formulari del canal, i el text d'ajuda de la interfície ja diu que
     * només canvia quan el mòdul considera la finestra tancada, no la regla
     * de Meta. Si algun dia es posa a 1440, tornem a coincidir amb Meta i
     * perdem el marge.
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
