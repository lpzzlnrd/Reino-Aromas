<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasActivityLogs, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'color',
    ];

    /**
     * @return BelongsToMany<Ticket, $this>
     */
    // Tickets marcados con esta etiqueta mediante la tabla pivot ticket_tag.
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class);
    }

    /**
     * @param Builder<Tag> $query
     * @return Builder<Tag>
     */
    public function scopeNamed(Builder $query, string $name): Builder
    {
        return $query->where('name', $name);
    }
}
