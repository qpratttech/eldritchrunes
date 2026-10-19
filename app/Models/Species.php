<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Model;

class Species extends Model
{
    use VisibleTo;

    protected $guarded = [];

    protected $appends = ['portrait_url'];

    protected $casts = [
        'traits_json' => 'array',
    ];

    public function getPortraitUrlAttribute(): ?string
    {
        $base = config('filesystems.disks.s3.url');
        if (! $this->portrait_path || ! filled($base)) {
            return null;
        }

        $url = rtrim((string) $base, '/').'/'.ltrim($this->portrait_path, '/');
        if (str_starts_with($this->portrait_path, 'species/')) {
            $url .= '?v=2';
        }

        return $url;
    }
}
