<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatureAction extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'effects_json' => 'array',
    ];
}
