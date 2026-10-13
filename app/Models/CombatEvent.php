<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CombatEvent extends Model
{
    protected $guarded = [];
    protected $casts = [
        'payload_json' => 'array',
    ];

    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Combatant::class, 'actor_id'); }
    public function target(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Combatant::class, 'target_id'); }
}
