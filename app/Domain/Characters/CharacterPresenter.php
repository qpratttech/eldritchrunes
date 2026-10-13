<?php

namespace App\Domain\Characters;

use App\Domain\Rules\Percentile;
use App\Models\Character;

class CharacterPresenter
{
    public function present(Character $character): array
    {
        $character->load([
            'species', 'background', 'specialization', 'campaign',
            'attributes.attribute', 'skills.skill.attribute',
            'resources', 'classLevels.heroClass.role', 'classLevels.heroClass.primarySkills', 'features.feature',
            'spells.spell.order.castingSkill', 'techniques.technique', 'techniques.weaponType.item', 'inventory.item.weapon.skill', 'inventory.item.weapon.techniques', 'inventory.item.armor',
            'wallet', 'conditions.condition',
        ]);

        $suffering = (int) $character->conditions
            ->filter(fn ($row) => $row->condition?->key === 'suffering')
            ->sum('severity');
        $broken = $character->conditions->contains(fn ($row) => $row->condition?->key === 'broken');

        $attributes = $character->attributes->map(function ($row) use ($suffering, $broken) {
            $tier = Percentile::tier((int) $row->base_step, 0, 0, $suffering, $broken);

            return [
                'key' => $row->attribute->key,
                'name' => $row->attribute->name,
                'step' => $row->base_step,
                'tier' => $tier,
                'percent' => Percentile::successPercent($tier),
                'outcome' => Percentile::outcome($tier),
            ];
        })->values();

        $byKey = $attributes->keyBy('key');

        $enhanced = collect($character->derivation_json['flags'] ?? [])
            ->filter(fn ($flag) => is_string($flag) && str_starts_with($flag, 'enhanced:'))
            ->map(fn ($flag) => substr($flag, strlen('enhanced:')))
            ->all();

        $skills = $character->skills->map(function ($row) use ($byKey, $suffering, $broken, $enhanced) {
            $attribute = $row->skill->attribute;
            $step = $byKey[$attribute->key]['step'] ?? 6;
            $tier = Percentile::tier($step, $row->base_rank, 0, $suffering, $broken);
            if (in_array(strtolower($row->skill->name), $enhanced, true)) {
                $tier += 1;
            }

            return [
                'id' => $row->skill_id,
                'key' => $row->skill->key,
                'name' => $row->skill->name,
                'attribute' => $attribute->name,
                'rank' => $row->base_rank,
                'attack' => (bool) $row->skill->is_attack_skill,
                'tier' => $tier,
                'percent' => Percentile::successPercent($tier),
                'outcome' => Percentile::outcome($tier),
            ];
        })->sortBy('name')->values();

        $resources = $character->resources->mapWithKeys(fn ($row) => [$row->resource_key => [
            'max' => $row->base_maximum,
            'current' => $row->current_value,
        ]]);

        $silver = $character->wallet?->balance_silver ?? 0;

        return [
            'id' => $character->id,
            'name' => $character->name,
            'letter' => strtoupper(substr($character->name, 0, 1)),
            'portrait_url' => $character->portraitUrl(),
            'portrait_path' => $character->portrait_path,
            'species_portrait_url' => $character->species?->portrait_url,
            'gender' => $character->gender,
            'pronouns' => $character->pronouns,
            'height' => $character->height,
            'weight' => $character->weight,
            'age' => $character->age,
            'eyes' => $character->eyes,
            'hair' => $character->hair,
            'biography' => $character->biography,
            'notes' => $character->notes,
            'xp' => $character->xp,
            'level' => (int) $character->classLevels->sum('class_level'),
            'species' => $character->species?->name,
            'background' => $character->background?->name,
            'specialization' => $character->specialization?->name,
            'specialization_detail' => $this->specializationDetail($character->specialization),
            'campaign_id' => $character->campaign_id,
            'classes' => $character->classLevels->map(function ($row) {
                $class = $row->heroClass;
                $role = $class?->role;
                $skills = $class?->primarySkills?->pluck('name')->filter()->implode(', ');

                return [
                    'id' => $row->class_id,
                    'name' => $class?->name,
                    'level' => $row->class_level,
                    'detail' => trim(implode("\n\n", array_filter([
                        $class?->description,
                        $role ? 'Role: '.$this->roleLabel($role->name).($role->description ? "\n".$role->description : '') : null,
                        $skills !== '' ? 'Primary skills: '.$skills : null,
                    ]))),
                ];
            })->values(),
            'attributes' => $attributes,
            'skills' => $skills,
            'resources' => $resources,
            'gold' => intdiv($silver, 10),
            'silver' => $silver % 10,
            'features' => $character->features->map(fn ($row) => [
                'name' => $row->feature?->name,
                'category' => $row->feature?->category,
                'source' => $row->source_type,
                'flavor' => $row->feature?->flavor_text,
                'rules' => $row->feature?->rules_text,
                'choices' => $row->choices_json,
            ])->values(),
            'spells' => $character->spells->map(fn ($row) => [
                'id' => $row->id,
                'spell_id' => $row->spell_id,
                'name' => $row->spell?->name,
                'tier' => $row->spell?->tier,
                'order' => $row->spell?->order?->name,
                'detail' => $this->spellDetail($row->spell),
            ])->filter(fn ($row) => filled($row['name']))->values(),
            'techniques' => $character->techniques->map(fn ($row) => [
                'id' => $row->id,
                'technique_id' => $row->technique_id,
                'weapon_type_id' => $row->weapon_type_id,
                'name' => $row->technique?->name,
                'weapon' => $row->weaponType?->item?->name,
                'detail' => $row->technique?->description,
            ])->sortBy(['weapon', 'name'])->values(),
            'attacks' => $this->attacks($character, $skills),
            'inventory' => $character->inventory->map(fn ($row) => [
                'id' => $row->id,
                'item_id' => $row->item_id,
                'name' => $row->custom_name ?: $row->item?->name,
                'quantity' => $row->quantity,
                'equipped' => $row->equipped_slot,
                'category' => $row->item?->category,
                'detail' => $this->itemDetail($row->item),
            ])->values(),
            'conditions' => $character->conditions->map(fn ($row) => [
                'id' => $row->id,
                'condition_id' => $row->condition_id,
                'name' => $row->condition?->name,
                'key' => $row->condition?->key,
                'severity' => $row->severity,
                'detail' => $row->condition?->description,
            ])->values(),
            'ledger' => $character->derivation_json['ledger'] ?? [],
            'flags' => $character->derivation_json['flags'] ?? [],
            'spell_techniques' => array_values($character->derivation_json['spell_techniques'] ?? []),
            'companion' => $this->companion($character),
            'specialization_progression' => collect($character->specialization?->progression_json ?? [])->map(fn ($level) => [
                'level' => (int) ($level['level'] ?? 0),
                'feature' => $level['feature'] ?? '',
                'choice' => \App\Domain\Characters\CharacterAdvancement::choiceKinds((string) ($level['feature'] ?? '')),
                'spell' => \App\Domain\Characters\CharacterAdvancement::spellChoice((string) ($level['feature'] ?? '')),
            ])->values(),
            'orders' => \App\Models\CharacterOrderAccess::query()
                ->where('character_id', $character->id)
                ->get()
                ->map(function ($row) {
                    $name = \App\Models\MagicOrder::query()->whereKey($row->order_id)->value('name');

                    return $name ? ['id' => $row->id, 'name' => $name] : null;
                })
                ->filter()
                ->values(),
            'armor' => $character->armorSavePercent(),
            'suffering' => $suffering,
            'broken' => $broken,
        ];
    }

    private function companion(Character $character): ?array
    {
        $names = $character->features->map(fn ($row) => $row->feature?->rules_text.' '.$row->feature?->name)->implode(' ');
        $grant = null;
        if (preg_match('/direwolf, panther, or bear/i', $names)) {
            $grant = 'greater';
        }
        if (preg_match('/small companion/i', $names)) {
            $grant = $grant === 'greater' ? 'both' : 'small';
        }
        $saved = $character->derivation_json['companion'] ?? null;
        if (! $grant && ! $saved) {
            return null;
        }
        $profiles = [
            'Direwolf' => '4 wounds · attack 10 · endurance 10 · reflexes 9 · willpower 7 · other 8 · move 30/15 ft. Bite deals 1 Physical damage.',
            'Panther' => '3 wounds · attack 10 · endurance 8 · reflexes 11 · willpower 7 · other 8 · move 30/15 ft.',
            'Bear' => '5 wounds · attack 10 · endurance 11 · reflexes 7 · willpower 8 · other 8 · move 20/10 ft.',
            'Raven' => 'Small companion. 2 wounds · attack 7 · endurance 7 · reflexes 10 · willpower 7 · other 7 · move 20/10 ft. Flies.',
            'Owl' => 'Small companion. 2 wounds · attack 7 · endurance 7 · reflexes 10 · willpower 7 · other 7 · move 20/10 ft. Flies.',
            'Eagle' => 'Small companion. 2 wounds · attack 7 · endurance 7 · reflexes 10 · willpower 7 · other 7 · move 20/10 ft. Flies.',
            'Monkey' => 'Small companion. 2 wounds · attack 7 · endurance 7 · reflexes 10 · willpower 7 · other 7 · move 20/10 ft. Climbs.',
            'Snake' => 'Small companion. 2 wounds · attack 7 · endurance 7 · reflexes 10 · willpower 7 · other 7 · move 20/10 ft. Climbs.',
            'Bat' => 'Small companion. 2 wounds · attack 7 · endurance 7 · reflexes 10 · willpower 7 · other 7 · move 20/10 ft. Flies. Navigates ordinary darkness within 20 feet by hearing.',
        ];
        $name = $saved['name'] ?? null;

        return [
            'grant' => $grant,
            'name' => $name,
            'aptitude' => $saved['aptitude'] ?? null,
            'detail' => $name ? ($profiles[$name] ?? null) : null,
        ];
    }

    private function roleLabel(?string $name): ?string
    {
        return $name === 'Magus' ? 'Spellcaster' : $name;
    }

    private function specializationDetail(?\App\Models\Specialization $specialization): ?string
    {
        if (! $specialization) {
            return null;
        }
        $levels = collect($specialization->progression_json ?? [])
            ->map(fn ($level) => trim(($level['level'] ?? '').': '.($level['feature'] ?? ''), ': '))
            ->filter()
            ->implode("\n");
        $detail = trim(implode("\n\n", array_filter([$specialization->description, $levels])));

        return $detail !== '' ? $detail : null;
    }

    private function spellDetail(?\App\Models\Spell $spell): ?string
    {
        if (! $spell) {
            return null;
        }
        $facts = implode(' · ', array_filter([
            $spell->order?->name,
            'Level '.$spell->tier,
            $spell->casting_time ? 'Casting '.$spell->casting_time : null,
            $spell->targeting_json['text'] ?? null,
            $spell->duration_json['text'] ?? null,
        ]));
        $empowered = $spell->empowered_effects_json['text'] ?? null;
        $detail = trim(implode("\n\n", array_filter([
            $spell->flavor_text,
            $facts,
            $spell->rules_text,
            filled($empowered) ? 'Empowered. '.$empowered : null,
        ])));

        return $detail !== '' ? $detail : null;
    }

    private function itemDetail(?\App\Models\Item $item): ?string
    {
        if (! $item) {
            return null;
        }
        $techniques = $item->weapon?->techniques
            ?->map(fn ($technique) => trim($technique->name.': '.$technique->description))
            ->filter()
            ->implode("\n\n");
        $facts = array_filter([
            (float) $item->weight > 0 ? rtrim(rtrim(number_format((float) $item->weight, 2, '.', ''), '0'), '.').' lb' : null,
            (int) $item->cost_silver > 0 ? intdiv((int) $item->cost_silver, 10).'g '.((int) $item->cost_silver % 10).'s' : null,
        ]);
        $detail = trim(implode("\n\n", array_filter([
            $item->description,
            $techniques,
            $facts === [] ? null : implode(' · ', $facts),
        ])));

        return $detail !== '' ? $detail : null;
    }

    private function attacks(Character $character, $skills): array
    {
        $flags = $character->derivation_json['flags'] ?? [];
        $natural = in_array('natural_weapons', $flags, true) || in_array('horns', $flags, true);
        $unarmed = $skills->firstWhere('key', 'unarmed');
        $attacks = [[
            'kind' => 'unarmed',
            'name' => $natural ? 'Unarmed (natural)' : 'Unarmed',
            'detail' => $natural ? '1 wound, +1 on a critical' : 'Lesser attack: 01–33 deals 1 wound',
            'tier' => $unarmed['tier'] ?? null,
            'skill_id' => $unarmed['id'] ?? null,
            'item_id' => null,
            'spell_id' => null,
            'heavy' => false,
        ]];

        foreach ($character->inventory as $row) {
            $weapon = $row->item?->weapon;
            if (! $weapon) {
                continue;
            }
            $great = (bool) ($weapon->properties_json['great'] ?? false);
            $twoHand = $weapon->hands_required >= 2 && $weapon->skill?->key === 'melee';
            $skill = $skills->firstWhere('id', $weapon->attack_skill_id);
            $attacks[] = [
                'kind' => 'weapon',
                'name' => $row->custom_name ?: $row->item->name,
                'detail' => $weapon->base_damage.' wound'.($weapon->base_damage === 1 ? '' : 's').($twoHand ? ', +2 on a critical' : ', +1 on a critical').($great ? ', Heavy +1' : ''),
                'tier' => $skill['tier'] ?? null,
                'skill_id' => $weapon->attack_skill_id,
                'item_id' => $row->item_id,
                'spell_id' => null,
                'heavy' => $great,
            ];
        }

        foreach ($character->spells as $row) {
            $rules = (string) ($row->spell?->rules_text ?? '');
            $save = \App\Domain\Rules\SpellAttack::forcedSave($rules);
            $direct = str_contains(strtolower($rules), 'direct spell attack');
            if (! $save && ! $direct) {
                continue;
            }
            $skill = $save
                ? null
                : $skills->firstWhere('id', $row->spell->order?->casting_skill_id);
            $attacks[] = [
                'kind' => 'spell',
                'name' => $row->spell->name,
                'detail' => $save ? $save.' save' : trim(strtok($rules, "\n") ?: 'Direct spell attack'),
                'tier' => $save ? null : ($skill['tier'] ?? null),
                'skill_id' => $save ? null : $row->spell->order?->casting_skill_id,
                'item_id' => null,
                'spell_id' => $row->spell_id,
                'heavy' => false,
                'save' => $save,
            ];
        }

        return $attacks;
    }
}
