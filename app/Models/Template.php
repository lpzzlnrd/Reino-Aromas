<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasActivityLogs, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'body',
        'city',
        'meta_template_name',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param Builder<Template> $query
     * @return Builder<Template>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<Template> $query
     * @return Builder<Template>
     */
    public function scopeForCity(Builder $query, ?string $city): Builder
    {
        return $query->where(function (Builder $query) use ($city): void {
            $query->whereNull('city');

            if ($city !== null) {
                $query->orWhere('city', $city);
            }
        });
    }

    /**
     * @param Builder<Template> $query
     * @return Builder<Template>
     */
    public function scopeApprovedMetaTemplate(Builder $query): Builder
    {
        return $query->whereNotNull('meta_template_name');
    }
}
