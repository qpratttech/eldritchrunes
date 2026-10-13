<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SceneWall extends Model
{
    protected $guarded = [];
    protected $casts = [
        'geometry_json' => 'array',
    ];
}
