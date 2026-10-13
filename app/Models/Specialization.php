<?php

namespace App\Models;

use App\Models\Concerns\VisibleTo;
use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    use VisibleTo;

    protected $guarded = [];
    protected $casts = [
        'progression_json' => 'array',
    ];
}
