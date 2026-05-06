<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasActivityLogs, HasFactory;

    public const UPDATED_AT = null;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_TEMPLATE = 'template';

    public const TYPE_SYSTEM = 'system';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'direction',
        'channel',
        'external_id',
        'type',
        'body',
        'media_url',
        'media_path',
        'meta_payload',
        'status',
        'failed_reason',
        'sent_at',
        'delivered_at',
        'read_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta_payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    // Conversacion a la que pertenece este mensaje inmutable.
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    // Usuario que envio el mensaje; null cuando el mensaje es entrante.
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @param Builder<Message> $query
     * @return Builder<Message>
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * @param Builder<Message> $query
     * @return Builder<Message>
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    /**
     * @param Builder<Message> $query
     * @return Builder<Message>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * @param Builder<Message> $query
     * @return Builder<Message>
     */
    public function scopeForConversation(Builder $query, int $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }

    /**
     * @param Builder<Message> $query
     * @return Builder<Message>
     */
    public function scopeWithThreadRelations(Builder $query): Builder
    {
        return $query->with(['sender']);
    }
}
