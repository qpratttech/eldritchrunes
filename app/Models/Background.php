<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Model;

class Background extends Model
{
    use VisibleTo;

    protected $guarded = [];
    protected $casts = [
        'grants_json' => 'array',
    ];
}
