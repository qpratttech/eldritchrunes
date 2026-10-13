<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmorType extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'properties_json' => 'array',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Item::class); }
}
