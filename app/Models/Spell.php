<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Model;

class Spell extends Model
{
    use VisibleTo;

    protected $guarded = [];
    protected $casts = [
        'targeting_json' => 'array',
        'duration_json' => 'array',
        'requires_focus' => 'boolean',
        'base_effects_json' => 'array',
        'empowered_effects_json' => 'array',
    ];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(MagicOrder::class, 'order_id'); }
}
