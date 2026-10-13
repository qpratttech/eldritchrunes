<?php

namespace App\Domain\Characters;

use App\Domain\Rules\SpellGrant;
use App\Models\Character;
use App\Models\CharacterClassLevel;
use App\Models\CharacterFeature;
use App\Models\CharacterOrderAccess;
use App\Models\CharacterResource;
use App\Models\CharacterSkill;
use App\Models\CharacterSpell;
use App\Models\CharacterTechnique;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\MagicOrder;
use App\Models\Skill;
use App\Models\Spell;
use App\Models\WeaponType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CharacterAdvancement
{
    /** @var list<string> */
    public const SPELL_TECHNIQUES = ['Potent', 'Far-Reaching', 'Lasting', 'Subtle'];

    /** @var list<string> */
    public const GREATER_COMPANIONS = ['Direwolf', 'Panther', 'Bear'];

    /** @var list<string> */
    public const SMALL_COMPANIONS = ['Raven', 'Owl', 'Eagle', 'Monkey', 'Snake', 'Bat'];

    /** @var list<string> */
    public const ATTACK_SKILLS = ['melee', 'ranged', 'unarmed', 'sorcery', 'faith', 'shamanism'];

    public function addLevel(Character $character, int $classId, array $choices = []): Character
    {
        $class = HeroClass::with('role')->find($classId);
        if (! $class) {
            throw ValidationException::withMessages(['class_id' => 'Choose a class.']);
        }

        DB::transaction(function () use ($character, $class, $choices) {
            $character->load('classLevels', 'specialization', 'features.feature');
            $levels = $character->classLevels;
            $total = (int) $levels->sum('class_level');
            if ($total >= 10) {
                throw ValidationException::withMessages(['class_id' => 'Character level cannot exceed 10.']);
            }

            $existing = $levels->firstWhere('class_id', $class->id);
            if (! $existing && $levels->count() >= 3) {
                throw ValidationException::withMessages(['class_id' => 'A character can have at most three classes.']);
            }

            $newClassLevel = $existing ? $existing->class_level + 1 : 1;
            $newTotal = $total + 1;
            $benefits = [];
            if (! $existing) {
                $this->applyStartingRole($character, $class->role?->name);
                $this->note($character, $class->name.' grants its starting '.$class->role?->name.' bonuses.');
            }
            $entry = collect($class->progression_json ?? [])->first(fn ($row) => (int) ($row['level'] ?? 0) === $newClassLevel);
            if ($entry && filled($entry['feature'] ?? null)) {
                $benefits[] = [(string) $entry['feature'], 'class', $class->id];
            }
            if (in_array($newTotal, [3, 6, 9], true) && $character->specialization) {
                $spec = collect($character->specialization->progression_json ?? [])->first(fn ($row) => (int) ($row['level'] ?? 0) === $newTotal);
                if ($spec && filled($spec['feature'] ?? null)) {
                    $benefits[] = [(string) $spec['feature'], 'specialization', $character->specialization_id];
                }
            }

            if ($existing) {
                $existing->update(['class_level' => $newClassLevel]);
            } else {
                CharacterClassLevel::create([
                    'character_id' => $character->id,
                    'class_id' => $class->id,
                    'class_level' => 1,
                ]);
            }

            foreach ($benefits as [$text, $source, $sourceId]) {
                $this->applyBenefit($character, $text, $choices[$text] ?? [], $source, $sourceId);
            }
            $this->raiseFocus($character, $newTotal);
            $this->note($character, 'Advanced to character level '.$newTotal.' as '.$class->name.' '.$newClassLevel.'.');
        });

        return $character->fresh('classLevels.heroClass');
    }

    /**
     * @return list<string>
     */
    public static function choiceKinds(string $text): array
    {
        $text = trim($text);
        $kinds = [];
        if (preg_match('/^magus\s*1$/i', $text)) {
            $kinds[] = 'two_spells';
        } elseif (preg_match('/^martial\s*1$/i', $text)) {
            $kinds[] = 'talent';
        } elseif (preg_match('/attack skill increase/i', $text)) {
            $kinds[] = 'attack_skill';
        } elseif (preg_match('/spell technique/i', $text)) {
            $kinds[] = 'spell_technique';
        } elseif (preg_match('/weapon technique/i', $text) && preg_match('/\bor\b/i', $text)) {
            $kinds[] = 'weapon_or_tactic';
        } elseif (preg_match('/weapon technique/i', $text)) {
            $kinds[] = 'weapon_technique';
        } elseif (preg_match('/learn one spell|1 spell learned/i', $text)) {
            $kinds[] = 'one_spell';
        }

        $feature = Feature::query()->whereRaw('lower(name) = ?', [mb_strtolower($text)])->first();
        if ($feature) {
            if (SpellGrant::fromText($feature->rules_text)) {
                $kinds[] = 'spells';
            } elseif (SpellGrant::choosesOrder($feature->rules_text)) {
                $kinds[] = 'order';
            }
            if (preg_match('/direwolf, panther, or bear/i', (string) $feature->rules_text)) {
                $kinds[] = 'greater_companion';
            }
            if (preg_match('/small companion/i', (string) $feature->rules_text)) {
                $kinds[] = 'small_companion';
            }
            if (preg_match('/learn one weapon technique|one weapon technique/i', (string) $feature->rules_text) && ! in_array('weapon_technique', $kinds, true)) {
                $kinds[] = 'weapon_technique';
            }
        }

        return array_values(array_unique($kinds));
    }

    /**
     * @return array{count: int, max_tier: int|null, choose_order: bool, orders: list<string>|null}|null
     */
    public static function spellChoice(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/^magus\s*1$/i', $text)) {
            return ['count' => 2, 'max_tier' => null, 'choose_order' => false, 'orders' => null];
        }
        $feature = Feature::query()->whereRaw('lower(name) = ?', [mb_strtolower($text)])->first();
        $grant = $feature ? SpellGrant::fromText($feature->rules_text) : null;
        if (! $grant) {
            return null;
        }

        return [
            'count' => $grant['count'],
            'max_tier' => $grant['max_tier'],
            'choose_order' => $grant['choose_order'],
            'orders' => $grant['all_orders'] ? SpellGrant::ORDERS : $grant['orders'],
        ];
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function applyBenefit(Character $character, string $text, array $choice, string $source, ?int $sourceId): void
    {
        $label = trim($text);
        if (preg_match('/^(Martial|Rogue|Magus)\s*1$/i', $label, $role)) {
            $this->applyRoleIncrease($character, ucfirst(strtolower($role[1])), $choice, $source, $sourceId);

            return;
        }
        if (preg_match('/attack skill increase/i', $label)) {
            $this->grantAttackSkill($character, (string) ($choice['skill'] ?? ''), $label);

            return;
        }
        if (preg_match('/spell technique/i', $label)) {
            $this->grantSpellTechnique($character, (string) ($choice['spell_technique'] ?? ''), $label);

            return;
        }
        if (preg_match('/weapon technique/i', $label) && preg_match('/\bor\b/i', $label)) {
            $this->grantWeaponOrTactic($character, $choice, $label, $source, $sourceId);

            return;
        }
        if (preg_match('/^1 attack$/i', $label)) {
            $this->flag($character, 'extra_attacks');
            $this->note($character, '1 Attack grants one additional attack.');

            return;
        }
        if (preg_match('/learn one spell|1 spell learned/i', $label)) {
            $this->bump($character, 'sorcerous_soul', 1);
            $this->learnSpells($character, $choice['spells'] ?? [], 1, $this->accessibleOrders($character), $this->resource($character, 'sorcerous_potential'), $label, false);
            $this->note($character, $label.'.');

            return;
        }
        if ($this->applyNumbers($character, $label)) {
            return;
        }

        $feature = Feature::query()->whereRaw('lower(name) = ?', [mb_strtolower($label)])->first();
        if ($feature) {
            $this->grantFeature($character, $feature, $choice, $source, $sourceId);

            return;
        }
        if (preg_match('/^(.+?) enhanced$/i', $label, $match)) {
            $this->flag($character, 'enhanced:'.mb_strtolower(trim($match[1])));
            $this->note($character, trim($match[1]).' checks are Enhanced.');

            return;
        }
        $this->note($character, $label.'.');
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function applyRoleIncrease(Character $character, string $role, array $choice, string $source, ?int $sourceId): void
    {
        if ($role === 'Martial') {
            $this->bump($character, 'wounds', 1);
            $this->grantTalent($character, (string) ($choice['talent'] ?? ''), 'Martial', $choice);
            $this->note($character, 'Martial increases maximum Wounds by 1.');
        } elseif ($role === 'Rogue') {
            $this->bump($character, 'initiative_bonus', 1);
            $this->bump($character, 'crit_range', 2);
            $this->note($character, 'Rogue increases Initiative by 1 and critical range by 2.');
        } elseif ($role === 'Magus') {
            $potential = $this->resource($character, 'sorcerous_potential');
            if ($potential < 5) {
                $this->bump($character, 'sorcerous_potential', 1);
            }
            $this->bump($character, 'sorcerous_soul', 2);
            $this->learnSpells($character, $choice['spells'] ?? [], 2, null, $this->resource($character, 'sorcerous_potential'), 'Magus', false);
            $this->note($character, 'Magus increases Sorcerous Potential and grants two Soul slots.');
        }
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function grantFeature(Character $character, Feature $feature, array $choice, string $source, ?int $sourceId): void
    {
        CharacterFeature::query()->firstOrCreate([
            'character_id' => $character->id,
            'feature_id' => $feature->id,
            'source_type' => $source,
        ], [
            'source_id' => $sourceId,
            'choices_json' => $choice ?: null,
        ]);
        $rules = (string) $feature->rules_text;
        if ($grant = SpellGrant::fromText($rules)) {
            if ($grant['choose_order']) {
                $order = SpellGrant::order((string) ($choice['order'] ?? ''));
                if (! $order) {
                    throw ValidationException::withMessages(['choices' => $feature->name.' needs an order.']);
                }
                $orders = [$order];
            } elseif ($grant['all_orders']) {
                $orders = SpellGrant::ORDERS;
            } elseif ($grant['orders']) {
                $orders = $grant['orders'];
            } else {
                $orders = $this->accessibleOrders($character);
            }
            $potential = $this->resource($character, 'sorcerous_potential');
            $maxTier = $grant['max_tier'] === null ? $potential : min($grant['max_tier'], $potential);
            $this->learnSpells($character, $choice['spells'] ?? [], (int) $grant['count'], $orders, $maxTier, $feature->name, true);
            $this->bump($character, 'sorcerous_soul', (int) $grant['count']);
            foreach ($orders as $orderName) {
                $this->grantOrder($character, $orderName, $source, $sourceId);
            }
        }
        if (preg_match('/direwolf, panther, or bear/i', $rules)) {
            $this->setCompanion($character, (string) ($choice['companion'] ?? ''), self::GREATER_COMPANIONS, null);
        }
        if (preg_match('/small companion/i', $rules)) {
            $this->setCompanion($character, (string) ($choice['companion'] ?? ''), self::SMALL_COMPANIONS, (string) ($choice['aptitude'] ?? ''));
        }
        if (preg_match('/weapon technique/i', $rules)) {
            $this->grantWeaponTechnique($character, (int) ($choice['weapon_type_id'] ?? 0), $feature->name);
        }
        if (SpellGrant::choosesOrder($rules) && ! (SpellGrant::fromText($rules)['choose_order'] ?? false)) {
            $this->grantChosenOrder($character, (string) ($choice['order'] ?? ''), $feature->name, $source, $sourceId);
        }
        if (preg_match('/learn one spell|1 spell learned/i', $rules) && ! SpellGrant::fromText($rules)) {
            $this->learnSpells($character, $choice['spells'] ?? [], 1, $this->accessibleOrders($character), $this->resource($character, 'sorcerous_potential'), $feature->name, false);
            $this->bump($character, 'sorcerous_soul', 1);
        }
        $this->note($character, 'Gained '.$feature->name.'.');
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function grantWeaponOrTactic(Character $character, array $choice, string $label, string $source, ?int $sourceId): void
    {
        $weapon = (int) ($choice['weapon_type_id'] ?? 0);
        $talent = (string) ($choice['talent'] ?? '');
        if (($weapon > 0) === ($talent !== '')) {
            throw ValidationException::withMessages(['choices' => $label.' needs either a weapon technique or a talent.']);
        }
        if ($weapon > 0) {
            $this->grantWeaponTechnique($character, $weapon, $label);
        } else {
            $this->grantTalent($character, $talent, $label, $choice);
        }
    }

    private function grantAttackSkill(Character $character, string $key, string $label): void
    {
        $skill = Skill::query()->where('key', $key)->where('is_attack_skill', true)->first();
        if (! $skill || ! in_array($skill->key, self::ATTACK_SKILLS, true)) {
            throw ValidationException::withMessages(['choices' => $label.' needs an attack skill.']);
        }
        $row = CharacterSkill::query()->firstOrCreate(
            ['character_id' => $character->id, 'skill_id' => $skill->id],
            ['base_rank' => 0],
        );
        $row->update(['base_rank' => $row->base_rank + 1]);
        $this->note($character, $label.' grants +1 '.$skill->name.'.');
    }

    private function grantSpellTechnique(Character $character, string $name, string $label): void
    {
        if (! in_array($name, self::SPELL_TECHNIQUES, true)) {
            throw ValidationException::withMessages(['choices' => $label.' needs Potent, Far-Reaching, Lasting, or Subtle.']);
        }
        $known = $character->derivation_json['spell_techniques'] ?? [];
        if (in_array($name, $known, true)) {
            throw ValidationException::withMessages(['choices' => $name.' is already known.']);
        }
        $known[] = $name;
        $this->putDerivation($character, 'spell_techniques', $known);
        $this->note($character, 'Learned the '.$name.' spell technique.');
    }

    private function grantWeaponTechnique(Character $character, int $weaponTypeId, string $label): void
    {
        $type = WeaponType::query()->with('item', 'techniques')->find($weaponTypeId);
        $technique = $type?->techniques->first();
        if (! $type || ! $technique || str_contains(strtolower((string) $type->item?->description), 'counts as')) {
            throw ValidationException::withMessages(['choices' => $label.' needs a weapon technique.']);
        }
        if (CharacterTechnique::query()->where('character_id', $character->id)->where('weapon_type_id', $type->id)->exists()) {
            throw ValidationException::withMessages(['choices' => 'That weapon technique is already known.']);
        }
        CharacterTechnique::create([
            'character_id' => $character->id,
            'technique_id' => $technique->id,
            'weapon_type_id' => $type->id,
            'source_type' => 'advancement',
        ]);
        $this->note($character, 'Learned '.$type->item?->name.' '.$technique->name.'.');
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function grantTalent(Character $character, string $name, string $label, array $choice = []): void
    {
        $talent = Feature::query()->whereIn('category', ['talent', 'tactic'])->where('name', $name)->first();
        if (! $talent) {
            throw ValidationException::withMessages(['choices' => $label.' needs a talent or tactic.']);
        }
        CharacterFeature::query()->firstOrCreate([
            'character_id' => $character->id,
            'feature_id' => $talent->id,
        ], ['source_type' => 'advancement', 'source_id' => null]);
        if (SpellGrant::choosesOrder($talent->rules_text)) {
            $this->grantChosenOrder($character, (string) ($choice['order'] ?? ''), $talent->name, 'advancement', null);
        }
        $this->note($character, $label.' grants '.$talent->name.'.');
    }

    /**
     * @param  list<int|string>  $ids
     * @param  list<string>|null  $orders
     */
    private function learnSpells(Character $character, array $ids, int $count, ?array $orders, int $maxTier, string $label, bool $allowFewer): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($allowFewer) {
            if (count($ids) > $count) {
                throw ValidationException::withMessages(['choices' => $label.' learns up to '.$count.' spells.']);
            }
        } elseif (count($ids) !== $count) {
            throw ValidationException::withMessages(['choices' => $label.' learns '.$count.' spells.']);
        }
        $accessible = $orders ?? $this->accessibleOrders($character);
        foreach ($ids as $id) {
            $spell = Spell::query()->with('order')->find($id);
            if (! $spell || (int) $spell->tier > $maxTier) {
                throw ValidationException::withMessages(['choices' => $label.' spells must be level '.$maxTier.' or lower.']);
            }
            if ($accessible !== [] && ! in_array($spell->order?->name, $accessible, true)) {
                throw ValidationException::withMessages(['choices' => $label.' spells must come from '.implode(' or ', $accessible).'.']);
            }
            CharacterSpell::query()->firstOrCreate([
                'character_id' => $character->id,
                'spell_id' => $spell->id,
            ], ['source_type' => 'advancement']);
            if ($spell->order) {
                $this->grantOrder($character, $spell->order->name, 'advancement', null);
            }
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function setCompanion(Character $character, string $name, array $allowed, ?string $aptitude): void
    {
        $match = collect($allowed)->first(fn ($row) => strcasecmp($row, $name) === 0);
        if (! $match) {
            throw ValidationException::withMessages(['choices' => 'Choose a companion: '.implode(', ', $allowed).'.']);
        }
        $aptitudes = ['Awareness', 'Stealth', 'Athletics'];
        if ($aptitude !== null) {
            $picked = collect($aptitudes)->first(fn ($row) => strcasecmp($row, $aptitude) === 0);
            if (! $picked) {
                throw ValidationException::withMessages(['choices' => 'Choose Awareness, Stealth, or Athletics for the companion.']);
            }
            $aptitude = $picked;
        }
        $this->putDerivation($character, 'companion', ['name' => $match, 'aptitude' => $aptitude]);
        $this->note($character, $match.' becomes your companion.'.($aptitude ? ' '.$aptitude.' is Enhanced.' : ''));
    }

    private function grantChosenOrder(Character $character, string $name, string $label, string $source, ?int $sourceId): void
    {
        $order = SpellGrant::order($name);
        if (! $order) {
            throw ValidationException::withMessages(['choices' => $label.' needs an order.']);
        }
        $this->grantOrder($character, $order, $source, $sourceId);
        $this->note($character, $label.' grants the '.$order.' order.');
    }

    /**
     * @return list<string>
     */
    private function accessibleOrders(Character $character): array
    {
        return MagicOrder::query()
            ->whereIn('id', CharacterOrderAccess::query()->where('character_id', $character->id)->pluck('order_id'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function grantOrder(Character $character, string $name, string $source, ?int $sourceId): void
    {
        $order = MagicOrder::query()->where('name', $name)->first();
        if (! $order) {
            return;
        }
        CharacterOrderAccess::query()->firstOrCreate([
            'character_id' => $character->id,
            'order_id' => $order->id,
        ], ['source_type' => $source, 'source_id' => $sourceId]);
    }

    private function applyNumbers(Character $character, string $label): bool
    {
        $map = [
            'wound' => 'wounds',
            'stamina' => 'stamina',
            'initiative bonus' => 'initiative_bonus',
            'sorcerous power' => 'sorcerous_power',
            'sorcerous soul' => 'sorcerous_soul',
            'sorcerous potential' => 'sorcerous_potential',
        ];
        if (preg_match('/learn one spell|1 spell learned/i', $label)) {
            return false;
        }
        if (! preg_match_all('/\+?\s*(\d+)\s+(maximum\s+)?([A-Za-z ]+?)(?=,| and |$)/i', $label, $matches, PREG_SET_ORDER)) {
            return false;
        }
        $applied = false;
        foreach ($matches as $match) {
            $key = $map[strtolower(trim($match[3]))] ?? null;
            if (! $key || preg_match('/spell/i', $match[3])) {
                continue;
            }
            $this->bump($character, $key, (int) $match[1]);
            $applied = true;
        }
        if (preg_match('/learn one spell|1 spell learned/i', $label)) {
            return false;
        }
        if ($applied) {
            $this->note($character, $label.'.');
        }

        return $applied;
    }

    private function raiseFocus(Character $character, int $level): void
    {
        if (! CharacterSpell::query()->where('character_id', $character->id)->exists()) {
            return;
        }
        $floor = $level >= 7 ? 3 : ($level >= 4 ? 2 : 0);
        if ($floor > 0 && $this->resource($character, 'focus') < $floor) {
            $this->setResource($character, 'focus', $floor);
        }
    }

    private function applyStartingRole(Character $character, ?string $role): void
    {
        if ($role === 'Martial') {
            $this->bump($character, 'wounds', 1);
            $this->bump($character, 'stamina', 1);
        } elseif ($role === 'Rogue') {
            $this->bump($character, 'initiative_bonus', 2);
        } elseif ($role === 'Magus') {
            $this->bump($character, 'sorcerous_potential', 1);
            $this->bump($character, 'sorcerous_power', 1);
        }
    }

    private function bump(Character $character, string $key, int $amount): void
    {
        $resource = CharacterResource::query()->firstOrCreate(
            ['character_id' => $character->id, 'resource_key' => $key],
            ['base_maximum' => 0, 'current_value' => 0],
        );
        $resource->update([
            'base_maximum' => $resource->base_maximum + $amount,
            'current_value' => $resource->current_value + $amount,
        ]);
    }

    private function setResource(Character $character, string $key, int $value): void
    {
        $resource = CharacterResource::query()->firstOrCreate(
            ['character_id' => $character->id, 'resource_key' => $key],
            ['base_maximum' => 0, 'current_value' => 0],
        );
        $resource->update([
            'base_maximum' => max($resource->base_maximum, $value),
            'current_value' => max($resource->current_value, $value),
        ]);
    }

    private function resource(Character $character, string $key): int
    {
        return (int) CharacterResource::query()->where('character_id', $character->id)->where('resource_key', $key)->value('base_maximum');
    }

    private function flag(Character $character, string $flag): void
    {
        $flags = $character->fresh()->derivation_json['flags'] ?? [];
        if ($flag === 'extra_attacks') {
            $flags['extra_attacks'] = (int) ($flags['extra_attacks'] ?? 0) + 1;
        } elseif (! in_array($flag, $flags, true)) {
            $flags[] = $flag;
        }
        $this->putDerivation($character, 'flags', $flags);
    }

    private function note(Character $character, string $line): void
    {
        $ledger = $character->fresh()->derivation_json['ledger'] ?? [];
        $ledger[] = $line;
        $this->putDerivation($character, 'ledger', $ledger);
    }

    private function putDerivation(Character $character, string $key, mixed $value): void
    {
        $character->refresh();
        $derivation = $character->derivation_json ?? [];
        $derivation[$key] = $value;
        $character->update(['derivation_json' => $derivation]);
    }
}
