<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SceneToken extends Model
{
    protected $guarded = [];

    protected $appends = ['portrait_url', 'name', 'footprint', 'portrait_scale', 'nightvision'];

    public function getPortraitUrlAttribute(): ?string
    {
        $combatant = $this->combatant;
        if ($combatant?->character_id) {
            return $combatant->character?->portraitUrl();
        }
        if ($combatant?->creature_instance_id) {
            return $combatant->creature?->template?->portrait_url;
        }

        return null;
    }

    public function getNameAttribute(): string
    {
        $combatant = $this->combatant;

        return $combatant?->character?->name
            ?? $combatant?->creature?->name
            ?? (string) ($this->attributes['label'] ?? '');
    }

    public function getFootprintAttribute(): int
    {
        $template = strtolower((string) $this->combatant?->creature?->template?->name);

        return $template === 'bear' ? 2 : 1;
    }

    public function getPortraitScaleAttribute(): int
    {
        $template = strtolower((string) $this->combatant?->creature?->template?->name);

        return $template === 'elemental' ? 2 : 1;
    }

    public function getNightvisionAttribute(): bool
    {
        $character = $this->combatant?->character;
        if (! $character) {
            return false;
        }
        $flags = $character->derivation_json['flags'] ?? [];
        if (in_array('nightvision', $flags, true)) {
            return true;
        }
        $character->loadMissing('features.feature');

        return $character->features->contains(
            fn ($row) => $row->feature?->key === 'nightvision' || strcasecmp((string) $row->feature?->name, 'Nightvision') === 0
        );
    }

    public function combatant(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Combatant::class); }
    public function scene(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Scene::class); }
}
