<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterSpell extends Model
{
    protected $guarded = [];
    protected $casts = [
        'restrictions_json' => 'array',
    ];

    public function spell(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Spell::class); }
}
