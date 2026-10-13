<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RulesetVersion extends Model
{
    protected $guarded = [];
    protected $casts = [
        'settings_json' => 'array',
    ];
}
