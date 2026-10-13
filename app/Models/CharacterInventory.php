<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterInventory extends Model
{
    protected $table = 'character_inventory';
    protected $guarded = [];
    protected $casts = [
        'state_json' => 'array',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Item::class); }
}
