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
        'verify_token',
        'auto_created_mailbox',
        'is_active',
        'template_name',
        'template_lang',
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
    ];

    public function reactivatedBy()
    {
        return $this->belongsTo(\App\User::class, 'reactivated_by');
    }

    /**
     * Plantilles configurades (issue #2, punts 2-4): id, language,
     * display_name, recovery_text. Si 'templates' és buit, cau al parell
     * template_name/template_lang (comportament pre-existent, sense
     * recovery_text ni display_name propis) perquè les instal·lacions amb
     * una sola plantilla configurada no perdin res en actualitzar.
     */
    public function getTemplateList(): array
    {
        $list = array_values(array_filter($this->templates ?: [], function ($t) {
            return is_array($t) && !empty($t['id']) && !empty($t['language']);
        }));

        if (!empty($list)) {
            return $list;
        }

        if ($this->template_name && $this->template_lang) {
            return [[
                'id'            => $this->template_name,
                'language'      => $this->template_lang,
                'display_name'  => $this->template_name,
                'recovery_text' => null,
            ]];
        }

        return [];
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
