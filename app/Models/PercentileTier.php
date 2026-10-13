<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PercentileTier extends Model
{
    protected $guarded = [];
    public $incrementing = false;
        protected $primaryKey = 'tier';
        public $timestamps = false;
}
