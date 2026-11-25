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

    // Meta's billing categories. From 1 October 2026 the monthly free
    // allowance covers service messages only, so the record has to tell
    // them apart. Kept as strings so Meta's finer categories (utility,
    // marketing, authentication) fit here without a migration.
    const CATEGORY_SERVICE  = 'service';
    const CATEGORY_TEMPLATE = 'template';

    // Marks an identifier that an erasure has made unreadable. See
    // erasedWamid() for why it is kept at all.
    const ERASED_PREFIX = 'erased-';

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
        'category',
        'status',
        'error_code',
        'delivered_at',
        'meta_sent_at',
        'read_at',
        'failure_noted_at',
    ];

    protected $casts = [
        'delivered_at'     => 'datetime',
        'meta_sent_at'     => 'datetime',
        'read_at'          => 'datetime',
        'failure_noted_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'account_id');
    }

    /**
     * Meta's message id, made unreadable but still recognisable.
     *
     * The id is base64 and contains the contact's phone number, so it
     * cannot stay as it is once an erasure has been answered: the number
     * came back out of it with a single base64_decode, from the very row
     * the erasure had just reported as cleaned. It cannot be dropped
     * either, because the unique id is what makes a redelivered webhook a
     * no-op instead of recreating the person. A one-way hash serves the
     * second purpose without serving the first.
     *
     * No salt, deliberately, and the reason is the threat and not the
     * key management. A per-installation random kept in the module's own
     * options would be stable enough, so that is not the argument. The
     * argument is that the attacker this protects against is one holding
     * the database, who would hold the salt as well: a salt would add
     * nothing against them. What makes the hash safe here is the id's own
     * random part, at least twenty hex characters, which means it cannot
     * be walked back to a phone number by anyone who does not already
     * hold the id, and anyone holding the id holds the number inside it.
     *
     * Idempotent on purpose: erasing the same person twice must not wrap
     * the value twice, or the first redelivery stops being recognised.
     */
    public static function erasedWamid(string $wamid): string
    {
        if (strpos($wamid, self::ERASED_PREFIX) === 0) {
            return $wamid;
        }

        return self::ERASED_PREFIX . hash('sha256', $wamid);
    }

    /**
     * Idempotency asks about a message, not about a string.
     *
     * After an erasure the same message is stored under its hashed id,
     * while a redelivery from Meta still arrives under the original one.
     * The two places that decide whether an inbound message has already
     * been recorded ask through here, so the rule lives in one place:
     * the same guard copied into three jobs is what produced the bug
     * reported four times as #18, #25, #28 and #29.
     *
     * Two other lookups by wamid deliberately do not use this and ask for
     * the exact value: processStatus(), so a delivery receipt stops being
     * applied to the history of someone who asked to be forgotten, and
     * excerptForReactionTarget(), which already returns null for a target
     * it cannot find. Both would only be writing to or quoting from that
     * person's record.
     */
    public static function existsForWamid(string $wamid, $accountId = null): bool
    {
        $query = self::whereIn('wamid', [$wamid, self::erasedWamid($wamid)]);
        if ($accountId !== null) {
            $query->where('account_id', (int) $accountId);
        }

        return $query->exists();
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
        $last = static::lastInboundAt($conversationId);

        if (!$last) {
            return true;
        }

        $threshold = (int) ($account->template_threshold_minutes ?: 1435);

        return \Carbon\Carbon::parse($last)->lt(now()->subMinutes($threshold));
    }

    /**
     * Clock A: minutes left in Meta's real 24-hour customer service window,
     * from the last inbound message. Deliberately separate from
     * windowExpired() (Clock B): this one is never configurable and never
     * shares code or state with it, so the two can diverge, or either can
     * be removed, without touching the other.
     *
     * Negative once the real window has closed. Null when the conversation
     * has no inbound message on record.
     */
    public static function realWindowRemainingMinutes(int $conversationId): ?int
    {
        $last = static::lastInboundAt($conversationId);

        if (!$last) {
            return null;
        }

        return now()->diffInMinutes(\Carbon\Carbon::parse($last)->addHours(24), false);
    }

    /**
     * When the customer last wrote, as the window counts it.
     *
     * Meta's own time wins and created_at is only the fallback, which is
     * what T10 was about: created_at is when this module's worker wrote the
     * row, and every delay between Meta and that row made the module think
     * the window was more open than it was.
     *
     * The COALESCE is why no data had to be migrated. Rows written before
     * meta_sent_at existed, and any row whose payload carried no usable
     * time, keep answering with created_at.
     *
     * Both clocks read through here on purpose. They are allowed to diverge
     * in what they do with the answer, and they do, but not in what they
     * consider the last inbound message: that disagreement would be
     * invisible and would show the agent one window while the guard applied
     * another.
     */
    public static function lastInboundAt(int $conversationId): ?string
    {
        return static::where('conversation_id', $conversationId)
            ->where('direction', static::DIRECTION_INBOUND)
            ->max(\DB::raw('COALESCE(meta_sent_at, created_at)'));
    }
}
