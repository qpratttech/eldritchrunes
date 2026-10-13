<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterFeature extends Model
{
    protected $guarded = [];
    protected $casts = [
        'choices_json' => 'array',
    ];

    public function feature(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Feature::class); }
}
