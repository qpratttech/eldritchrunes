<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SceneLight extends Model
{
    protected $guarded = [];
    protected $casts = [
        'enabled' => 'boolean',
    ];
}
