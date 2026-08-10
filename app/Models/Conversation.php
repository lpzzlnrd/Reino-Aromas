<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'status',
        'last_message_at',
        'within_24h_window',
    ];

    protected $casts = [
        'last_message_at'    => 'datetime',
        'within_24h_window'  => 'boolean',
        'flow_sent_at'       => 'datetime',
    ];

    /**
     * Conversación cuya sesión de Flow corresponde a este token.
     *
     * El endpoint de Flows la usa para resolver a qué contacto pertenece cada
     * request del canal de datos.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Conversation> $query
     * @return \Illuminate\Database\Eloquent\Builder<Conversation>
     */
    public function scopeForFlowToken(\Illuminate\Database\Eloquent\Builder $query, string $token): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('flow_token', $token);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }
}
