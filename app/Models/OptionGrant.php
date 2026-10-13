<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptionGrant extends Model
{
    protected $guarded = [];
    protected $casts = [
        'selection_rules_json' => 'array',
    ];
}
