<?php

namespace Modules\MetaWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAccount extends Model
{
    /**
     * Canal enter propi del mòdul a customer_channel (el core no en defineix cap;
     * la columna és unsignedTinyInteger). El nom visible es registra via el
     * filter Eventy 'channel.name'. IDs oficials assignats per l'equip de
     * FreeScout (issue #4); abans de v1.5.1 el mòdul usava 100/101 provisionals.
     */
    const CHANNEL = 103;
    const CHANNEL_NAME = 'WhatsApp';

    /**
     * Canal dedicat per als BSUID (user_id de Meta): el core només permet una
     * fila de customer_channel per client i canal, així que el telèfon viu al
     * canal 103 i el BSUID en aquest.
     */
    const CHANNEL_BSUID      = 104;
    const CHANNEL_BSUID_NAME = 'WhatsApp ID';

    protected $table = 'meta_whatsapp_accounts';

    protected $fillable = [
        'mailbox_id',
        'name',
        'conversation_subject_template',
        'phone_number',
        'phone_number_id',
        'waba_id',
        'app_id',
        'verify_token',
        'auto_created_mailbox',
        'is_active',
        'template_threshold_minutes',
        'templates',
    ];

    // access_token i app_secret mai fillable: s'assignen explícitament amb encrypt().
    // reactivated_at/reactivated_by tampoc: només els escriu el controlador
    // en reactivar (issue #9), mai des d'un request de l'usuari.

    protected $casts = [
        'auto_created_mailbox' => 'boolean',
        'is_active'            => 'boolean',
        'templates'            => 'array',
        'reactivated_at'       => 'datetime',
        'token_expires_at'     => 'datetime',
    ];

    /**
     * Quan caduca el testimoni, en tres estats i no dos.
     *
     * null vol dir "no ho sabem", que no és el mateix que "no caduca". Un
     * testimoni d'usuari de sistema ben configurat és permanent, i Meta ho
     * diu amb expires_at = 0; això es guarda com a null també, però amb la
     * diferència que l'hem preguntat. Per distingir-ho cal mirar si hi ha
     * app_id: sense app_id no hem pogut preguntar mai.
     */
    public function tokenExpiryState(): string
    {
        if (!$this->app_id) {
            return 'unknown';
        }

        if (!$this->token_expires_at) {
            return 'never';
        }

        return $this->token_expires_at->isPast() ? 'expired' : 'expires';
    }

    /**
     * Dies que falten perquè caduqui, o null si no aplica. Serveix per decidir
     * si val la pena avisar: amb un testimoni permanent no hi ha res a dir.
     */
    public function daysUntilTokenExpiry(): ?int
    {
        if ($this->tokenExpiryState() !== 'expires') {
            return null;
        }

        // Arrodoniment cap amunt i a partir dels segons, no amb diffInDays():
        // aquell trunca, i un testimoni que caduca d'aquí a deu dies menys un
        // microsegon sortiria com a nou. En un compte enrere val més dir de
        // més que de menys, i el que caduca d'aquí a una hora ha de dir 1 dia,
        // no 0.
        return (int) ceil(($this->token_expires_at->getTimestamp() - time()) / 86400);
    }

    public function reactivatedBy()
    {
        return $this->belongsTo(\App\User::class, 'reactivated_by');
    }

    /**
     * Plantilles configurades del compte (id, language, display_name,
     * recovery_text). Una sola font des de la #2: el
     * parell heretat template_name/template_lang es va plegar dins les
     * ranures i les columnes ja no existeixen, així que aquí no hi ha cap
     * fallback ni cap regla de precedència a documentar.
     */
    public function getTemplateList(): array
    {
        return array_values(array_filter($this->templates ?: [], function ($t) {
            return is_array($t) && !empty($t['id']) && !empty($t['language']);
        }));
    }

    /**
     * Troba una plantilla configurada pel seu id+language exactes. Null si
     * no coincideix amb cap de les configurades (ni de la llista JSON ni del
     * fallback legacy) — evita enviar-ne una d'arbitrària des del request.
     */
    public function findTemplate(string $id, string $language): ?array
    {
        foreach ($this->getTemplateList() as $template) {
            if ($template['id'] === $id && $template['language'] === $language) {
                return $template;
            }
        }

        return null;
    }

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
