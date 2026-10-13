<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Technique extends Model
{
    protected $guarded = [];
    protected $casts = [
        'effects_json' => 'array',
    ];
}
