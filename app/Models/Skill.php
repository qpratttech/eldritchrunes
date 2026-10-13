<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $guarded = [];
    protected $casts = [
        'is_attack_skill' => 'boolean',
    ];

    public function attribute(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Attribute::class); }
}
