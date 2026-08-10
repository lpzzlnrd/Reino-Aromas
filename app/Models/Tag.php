<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = ['name', 'color'];

    public function tickets(): BelongsToMany
    {
        // Tabla explícita: por convención Eloquent buscaría 'tag_ticket' (orden
        // alfabético) pero la migración la creó como 'ticket_tag'.
        return $this->belongsToMany(Ticket::class, 'ticket_tag');
    }
}
