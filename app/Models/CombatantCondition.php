<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CombatantCondition extends Model
{
    protected $guarded = [];
    protected $casts = [
        'state_json' => 'array',
    ];

    public function condition(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(ConditionDefinition::class, 'condition_id'); }
}
