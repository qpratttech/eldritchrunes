<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Character extends Model
{
    protected $guarded = [];

    protected $casts = [
        'derivation_json' => 'array',
    ];

    public function portraitUrl(): ?string
    {
        $base = config('filesystems.disks.s3.url');
        $path = $this->portrait_path ?: $this->species?->portrait_path;
        if (! $path || ! filled($base)) {
            return null;
        }

        $url = rtrim((string) $base, '/').'/'.ltrim($path, '/');
        if (str_starts_with($path, 'portraits/')) {
            $url .= '?v=9';
        } elseif (str_starts_with($path, 'species/')) {
            $url .= '?v=2';
        }

        return $url;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function background(): BelongsTo
    {
        return $this->belongsTo(Background::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(CharacterAttribute::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(CharacterResource::class);
    }

    public function classLevels(): HasMany
    {
        return $this->hasMany(CharacterClassLevel::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(CharacterFeature::class);
    }

    public function spells(): HasMany
    {
        return $this->hasMany(CharacterSpell::class);
    }

    public function techniques(): HasMany
    {
        return $this->hasMany(CharacterTechnique::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(CharacterInventory::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(CharacterWallet::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(CombatantCondition::class);
    }

    public function armorSavePercent(): int
    {
        $this->loadMissing(['inventory.item.armor', 'features.feature']);
        $save = 0;
        $wearingSuit = false;
        foreach ($this->inventory as $row) {
            if (! $row->equipped_slot || ! $row->item?->armor) {
                continue;
            }
            if (in_array($row->item->armor->armor_category, ['light', 'medium', 'heavy'], true)) {
                $wearingSuit = true;
            }
            $save += (int) $row->item->armor->base_save_percent;
        }
        if ($wearingSuit) {
            if ($this->hasNamedFeature('Armor Master')) {
                $save += 10;
            }
            if ($this->hasNamedFeature('Battle Armor')) {
                $save += 10;
            }
        } elseif ($this->hasNamedFeature('Natural Armor') || in_array('natural_armor', $this->derivation_json['flags'] ?? [], true)) {
            $save += 10;
        }

        return min(95, $save);
    }

    public function hasNamedFeature(string $name): bool
    {
        $this->loadMissing('features.feature');

        return $this->features->contains(fn ($row) => strcasecmp((string) $row->feature?->name, $name) === 0);
    }

    public function wearingArmorSuit(): bool
    {
        $this->loadMissing('inventory.item.armor');
        foreach ($this->inventory as $row) {
            if ($row->equipped_slot && in_array($row->item?->armor?->armor_category, ['light', 'medium', 'heavy'], true)) {
                return true;
            }
        }

        return false;
    }

    public function preventsArmorPiercing(): bool
    {
        if (! $this->wearingArmorSuit()) {
            return false;
        }
        $this->loadMissing('features.feature');

        return $this->features->contains(fn ($row) => strcasecmp((string) $row->feature?->name, 'Rune Armor') === 0);
    }
}
