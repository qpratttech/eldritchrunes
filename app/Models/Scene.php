<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scene extends Model
{
    protected $guarded = [];
    protected $casts = [
        'settings_json' => 'array',
    ];

    public function tokens(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(SceneToken::class); }
    public function walls(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(SceneWall::class); }
    public function lights(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(SceneLight::class); }
    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Campaign::class); }
}
