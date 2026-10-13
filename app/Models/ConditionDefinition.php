<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConditionDefinition extends Model
{
    protected $guarded = [];
    protected $casts = [
        'effects_json' => 'array',
        'successful_save_effects_json' => 'array',
    ];
}
