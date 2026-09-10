<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de DM automático a quien comentó un post.
 *
 * Su razón de ser es la idempotencia: ver la migración. Se registran también
 * los intentos omitidos y los fallidos, no solo los enviados, para que un
 * comentario nunca se evalúe dos veces.
 */
class InstagramCommentReply extends Model
{
    public const STATUS_SENT    = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'comment_id',
        'media_id',
        'commenter_igsid',
        'commenter_username',
        'comment_text',
        'status',
        'skip_reason',
        'message_id',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** Los que de verdad se enviaron, para contar contra el tope diario. */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }
}
