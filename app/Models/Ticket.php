<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'assigned_user_id',
        'created_by_user_id',
        'status',
        'priority',
        'city',
        'course_interest',
        'notes',
        'reserved_at',
        'closed_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function contact(): HasOneThrough
    {
        return $this->hasOneThrough(Contact::class, Conversation::class, 'id', 'id', 'conversation_id', 'contact_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
