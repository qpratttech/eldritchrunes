<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatureTemplate extends Model
{
    protected $guarded = [];

    protected $appends = ['portrait_url'];

    protected $casts = [
        'stats_json' => 'array',
        'traits_json' => 'array',
    ];

    public function getPortraitUrlAttribute(): ?string
    {
        $base = config('filesystems.disks.s3.url');
        if (! $this->portrait_path || ! filled($base)) {
            return null;
        }

        return rtrim((string) $base, '/').'/'.ltrim($this->portrait_path, '/');
    }

    public function actions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CreatureAction::class); }
    public function instances(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(CreatureInstance::class); }
}
