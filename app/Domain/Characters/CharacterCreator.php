<?php

namespace App\Domain\Characters;

use App\Domain\Rules\FeatureMechanics;
use App\Domain\Rules\SpellGrant;
use App\Models\Background;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\CharacterClassLevel;
use App\Models\CharacterFeature;
use App\Models\CharacterInventory;
use App\Models\CharacterOrderAccess;
use App\Models\CharacterResource;
use App\Models\CharacterSkill;
use App\Models\CharacterSpell;
use App\Models\CharacterWallet;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\Item;
use App\Models\MagicOrder;
use App\Models\Skill;
use App\Models\Species;
use App\Models\Spell;
use App\Models\User;
use App\Models\WeaponType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CharacterCreator
{
    /** @var array{names: list<string>, shield: bool} */
    private array $creationGrants = ['names' => [], 'shield' => false];

    /** @var list<string> */
    private array $grantedOrderNames = [];

    /** @var list<int> */
    private array $techniqueGrants = [];

    private ?int $actorId = null;
    public function create(User $user, array $input): Character
    {
        return DB::transaction(function () use ($user, $input) {
            $this->grantedOrderNames = [];
            $this->techniqueGrants = [];
            $this->actorId = $user->id;
            $errors = [];
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                $errors['name'] = 'A character needs a name.';
            }

            $species = Species::find($input['species_id'] ?? null);
            $background = Background::find($input['background_id'] ?? null);
            $class = HeroClass::with('role', 'primarySkills')->find($input['class_id'] ?? null);
            if (! $species) {
                $errors['species_id'] = 'Choose a species.';
            }
            if (! $background) {
                $errors['background_id'] = 'Choose a background.';
            }
            if (! $class) {
                $errors['class_id'] = 'Choose a class.';
            }
            $this->throwIf($errors);
            $this->creationGrants = $this->creationFeatureNames($species, $background, $class, $input);

            $skills = Skill::all()->keyBy('key');
            $skillsByName = Skill::all()->keyBy(fn (Skill $skill) => strtolower($skill->name));
            $attrs = [];
            foreach (\App\Models\Attribute::all() as $attribute) {
                $attrs[$attribute->key] = 6;
            }
            $ranks = [];
            foreach ($skills as $skill) {
                $ranks[$skill->key] = 0;
            }
            $resources = [
                'wounds' => 3,
                'stamina' => 1,
                'luck' => 1,
                'sorcerous_power' => 0,
                'sorcerous_potential' => 0,
                'sorcerous_soul' => 0,
                'focus' => 0,
                'initiative_bonus' => 0,
                'crit_range' => 3,
                'movement_major' => 20,
                'movement_minor' => 10,
                'major_action' => 1,
                'minor_action' => 2,
            ];
            $flags = [];
            $ledger = ['All attributes start at 6. Wounds 3, Stamina 1, Luck 1, critical range 01–03.'];
            $granted = [];
            $spellIds = [];

            $traits = $species->traits_json ?? [];
            $sequence = [];
            foreach (['primary' => 'creation_primary', 'secondary' => 'creation_secondary'] as $slot => $category) {
                foreach ($traits[$slot] ?? [] as $featureName) {
                    $sequence[] = [$this->feature($featureName, [$category]), 'species', $species->id];
                }
            }
            $variancePrimary = array_values($input['variance_primary'] ?? []);
            $varianceSecondary = array_values($input['variance_secondary'] ?? []);
            if (count($variancePrimary) !== 2) {
                $errors['variance_primary'] = 'Choose two additional Primary features.';
            }
            if (count($varianceSecondary) !== 1) {
                $errors['variance_secondary'] = 'Choose one additional Secondary feature.';
            }
            foreach ($variancePrimary as $featureName) {
                $sequence[] = [$this->feature($featureName, ['creation_primary']), 'variance', $species->id];
            }
            foreach ($varianceSecondary as $featureName) {
                $sequence[] = [$this->feature($featureName, ['creation_secondary']), 'variance', $species->id];
            }
            $this->throwIf($errors);

            $choices = $input['choices'] ?? [];
            $owned = [];
            foreach ($sequence as [$feature, $source, $sourceId]) {
                if (! $feature) {
                    $errors['features'] = 'One of the selected features could not be found.';
                    break;
                }
                if (in_array(strtolower($feature->name), $owned, true)) {
                    $errors['features'] = $feature->name.' can be gained only once.';
                    break;
                }
                $prereq = $feature->prerequisites_json[0] ?? null;
                if ($prereq && ! in_array(strtolower($prereq), $owned, true)) {
                    $errors['features'] = $feature->name.' requires '.$prereq.'.';
                    break;
                }
                $this->applyFeature($feature, $choices, $attrs, $ranks, $resources, $flags, $spellIds, $skills, $errors, $ledger, $granted, $feature->name);
                $owned[] = strtolower($feature->name);
                $granted[] = [$feature, $source, $sourceId, $choices[$feature->name] ?? null];

                if ($feature->name === 'Accursed') {
                    $extra = $choices['Accursed']['primary'] ?? null;
                    $extraFeature = $extra ? $this->feature($extra, ['creation_primary']) : null;
                    if (! $extraFeature) {
                        $errors['choices'] = 'Accursed asks for an additional Primary feature.';
                    } elseif (in_array(strtolower($extraFeature->name), $owned, true)) {
                        $errors['choices'] = 'Choose a Primary feature you do not already have.';
                    } else {
                        $prereq = $extraFeature->prerequisites_json[0] ?? null;
                        if ($prereq && ! in_array(strtolower($prereq), $owned, true)) {
                            $errors['choices'] = $extraFeature->name.' requires '.$prereq.'.';
                        } else {
                            $this->applyFeature($extraFeature, $choices, $attrs, $ranks, $resources, $flags, $spellIds, $skills, $errors, $ledger, $granted, $extraFeature->name);
                            $owned[] = strtolower($extraFeature->name);
                            $granted[] = [$extraFeature, 'variance', $species->id, $choices[$extraFeature->name] ?? null];
                        }
                    }
                }
            }

            $grants = $background->grants_json ?? [];
            foreach ($grants['attributes'] ?? [] as $attributeName) {
                $key = $this->attributeKey($attributeName);
                if (! isset($attrs[$key])) {
                    $errors['background_id'] = 'Unknown background attribute '.$attributeName.'.';
                    continue;
                }
                $attrs[$key] += 1;
                $ledger[] = $background->name.' increases '.ucfirst($key).' by 1.';
            }
            foreach ($grants['skills'] ?? [] as $skillName) {
                $skill = $skillsByName[strtolower(trim($skillName))] ?? null;
                if (! $skill) {
                    $errors['background_id'] = 'Unknown background skill '.$skillName.'.';
                    continue;
                }
                $ranks[$skill->key] += 1;
                $ledger[] = $background->name.' grants +1 '.$skill->name.'.';
            }
            if (! empty($grants['feature'])) {
                $feature = $this->feature($grants['feature'], ['background']);
                if ($feature) {
                    $this->applyFeature($feature, $choices, $attrs, $ranks, $resources, $flags, $spellIds, $skills, $errors, $ledger, $granted, $feature->name);
                    $granted[] = [$feature, 'background', $background->id, $choices[$feature->name] ?? null];
                }
            }

            $otherPicks = array_values($input['other_skills'] ?? []);
            $primaryKeys = $class->primarySkills->pluck('key')->all();
            foreach ($primaryKeys as $key) {
                if (isset($ranks[$key])) {
                    $ranks[$key] += 2;
                    $ledger[] = $class->name.' grants +2 '.$skills[$key]->name.'.';
                }
            }
            if (count($otherPicks) !== 3 || count(array_unique($otherPicks)) !== 3) {
                $errors['other_skills'] = 'Choose three other different skills.';
            }
            foreach ($otherPicks as $key) {
                if (in_array($key, $primaryKeys, true) || ! isset($ranks[$key])) {
                    $errors['other_skills'] = 'The three additional skills must be different from each other and from the class primary skills.';
                } else {
                    $ranks[$key] += 1;
                    $ledger[] = 'Starting skills grant +1 '.$skills[$key]->name.'.';
                }
            }

            $roleName = $class->role?->name;
            if ($roleName === 'Martial') {
                $resources['wounds'] += 1;
                $resources['stamina'] += 1;
                $ledger[] = 'Martial starting role: +1 Wound, +1 Stamina.';
            } elseif ($roleName === 'Rogue') {
                $resources['initiative_bonus'] += 2;
                $rogueSkill = $input['rogue_skill'] ?? null;
                $skill = $skills[$rogueSkill] ?? null;
                if (! $skill || $skill->is_attack_skill) {
                    $errors['rogue_skill'] = 'Rogue asks for +1 rank in one nonattack skill.';
                } else {
                    $ranks[$skill->key] += 1;
                    $ledger[] = 'Rogue starting role: +2 Initiative and +1 '.$skill->name.'.';
                }
            } elseif ($roleName === 'Magus') {
                $resources['sorcerous_potential'] += 1;
                $resources['sorcerous_power'] += 1;
                $ledger[] = 'Magus starting role: +1 Sorcerous Potential, +1 Sorcerous Power.';
            }

            $levelOne = collect($class->progression_json ?? [])->firstWhere('level', 1);
            if ($levelOne) {
                $feature = Feature::query()->where('name', $levelOne['feature'])->first();
                if ($feature) {
                    $this->applyFeature($feature, $choices, $attrs, $ranks, $resources, $flags, $spellIds, $skills, $errors, $ledger, $granted, $feature->name);
                    $granted[] = [$feature, 'class', $class->id, $choices[$feature->name] ?? null];
                } else {
                    $ledger[] = $class->name.' 1: '.$levelOne['feature'].'.';
                    if (preg_match('/weapon technique/i', (string) $levelOne['feature'])) {
                        $this->learnTechnique($choices[$levelOne['feature']] ?? [], $errors, $levelOne['feature']);
                    }
                }
            }

            $specialization = null;
            if (! empty($input['specialization_id'])) {
                $specialization = \App\Models\Specialization::find($input['specialization_id']);
                $benefit = collect($specialization?->progression_json ?? [])->firstWhere('level', 1);
                if ($benefit) {
                    $feature = Feature::query()->where('name', $benefit['feature'])->first();
                    if ($feature) {
                        $this->applyFeature($feature, $choices, $attrs, $ranks, $resources, $flags, $spellIds, $skills, $errors, $ledger, $granted, $feature->name);
                        $granted[] = [$feature, 'specialization', $specialization->id, $choices[$feature->name] ?? null];
                    }
                    $ledger[] = ($specialization->name ?? 'Specialization').' 1: '.$benefit['feature'].'.';
                    if (! $feature && preg_match('/weapon technique/i', (string) $benefit['feature'])) {
                        $this->learnTechnique($choices[$benefit['feature']] ?? [], $errors, $benefit['feature']);
                    }
                }
            }

            $overflow = $input['overflow'] ?? [];
            foreach ($attrs as $key => $value) {
                if ($value <= 10) {
                    continue;
                }
                $excess = $value - 10;
                $target = $overflow[$key] ?? null;
                if (! $target || ! isset($attrs[$target]) || $target === $key) {
                    $errors['overflow'] = ucfirst($key).' would be '.$value.'. Assign the excess to another attribute below 10.';
                    break;
                }
                if ($attrs[$target] + $excess > 10) {
                    $errors['overflow'] = ucfirst($target).' cannot absorb the excess from '.ucfirst($key).'.';
                    break;
                }
                $attrs[$key] = 10;
                $attrs[$target] += $excess;
                $ledger[] = 'Attribute cap moves '.$excess.' step(s) from '.ucfirst($key).' to '.ucfirst($target).'.';
            }

            $this->throwIf($errors);

            $character = Character::create([
                'user_id' => $user->id,
                'name' => $name,
                'gender' => $input['gender'] ?? null,
                'pronouns' => $input['pronouns'] ?? null,
                'height' => $input['height'] ?? null,
                'weight' => $input['weight'] ?? null,
                'age' => $input['age'] ?? null,
                'eyes' => $input['eyes'] ?? null,
                'hair' => $input['hair'] ?? null,
                'species_id' => $species->id,
                'background_id' => $background->id,
                'specialization_id' => $specialization?->id,
                'biography' => $input['biography'] ?? null,
                'notes' => $input['notes'] ?? null,
                'xp' => 0,
                'derivation_json' => ['ledger' => $ledger, 'flags' => array_values(array_unique($flags))],
            ]);

            $attributeIds = \App\Models\Attribute::all()->keyBy('key');
            foreach ($attrs as $key => $step) {
                CharacterAttribute::create([
                    'character_id' => $character->id,
                    'attribute_id' => $attributeIds[$key]->id,
                    'base_step' => $step,
                ]);
            }
            foreach ($ranks as $key => $rank) {
                CharacterSkill::create([
                    'character_id' => $character->id,
                    'skill_id' => $skills[$key]->id,
                    'base_rank' => $rank,
                ]);
            }
            if ($spellIds !== [] && ($resources['focus'] ?? 0) < 1) {
                $resources['focus'] = 1;
            }
            foreach ($resources as $key => $value) {
                CharacterResource::create([
                    'character_id' => $character->id,
                    'resource_key' => $key,
                    'base_maximum' => $value,
                    'current_value' => $value,
                ]);
            }
            CharacterClassLevel::create([
                'character_id' => $character->id,
                'class_id' => $class->id,
                'class_level' => 1,
            ]);
            foreach ($granted as [$feature, $source, $sourceId, $choice]) {
                CharacterFeature::create([
                    'character_id' => $character->id,
                    'feature_id' => $feature->id,
                    'source_type' => $source,
                    'source_id' => $sourceId,
                    'choices_json' => $choice,
                ]);
            }
            foreach (array_unique($spellIds) as $spellId) {
                $spell = Spell::find($spellId);
                if (! $spell) {
                    continue;
                }
                CharacterSpell::create([
                    'character_id' => $character->id,
                    'spell_id' => $spell->id,
                    'source_type' => 'background',
                    'source_id' => $background->id,
                ]);
                if ($spell->order_id) {
                    CharacterOrderAccess::query()->firstOrCreate([
                        'character_id' => $character->id,
                        'order_id' => $spell->order_id,
                    ], ['source_type' => 'background', 'source_id' => $background->id]);
                }
            }
            foreach ($this->techniqueGrants as $weaponTypeId) {
                $type = WeaponType::query()->with('techniques')->find($weaponTypeId);
                $technique = $type?->techniques->first();
                if (! $technique) {
                    continue;
                }
                \App\Models\CharacterTechnique::create([
                    'character_id' => $character->id,
                    'technique_id' => $technique->id,
                    'weapon_type_id' => $type->id,
                    'source_type' => 'creation',
                ]);
            }
            foreach (array_unique($this->grantedOrderNames) as $orderName) {
                $order = MagicOrder::query()->where('name', $orderName)->first();
                if ($order) {
                    CharacterOrderAccess::query()->firstOrCreate([
                        'character_id' => $character->id,
                        'order_id' => $order->id,
                    ], ['source_type' => 'background', 'source_id' => $background->id]);
                }
            }

            $kit = ['Backpack', 'Sack', 'Torch', 'Ration, one day', 'Travel clothing', 'Waterskin, full'];
            $quantities = ['Torch' => 3, 'Ration, one day' => 3];
            foreach (array_merge($kit, $grants['equipment'] ?? []) as $itemName) {
                [$resolved, $quantity] = $this->startingGear($itemName);
                $item = Item::query()->whereRaw('lower(name) = ?', [strtolower($resolved)])->first();
                if (! $item) {
                    continue;
                }
                CharacterInventory::create([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                    'quantity' => $quantities[$resolved] ?? $quantity,
                ]);
            }

            $balance = 100 + $this->grantStartingPackage($character, (string) ($input['gear_package'] ?? 'cash'));
            CharacterWallet::create([
                'character_id' => $character->id,
                'balance_silver' => $balance,
            ]);

            return $character->load('classLevels.heroClass', 'species', 'background', 'wallet');
        });
    }

    /**
     * @return array<string, list<string>>
     */
    public static function startingPackages(): array
    {
        return [
            'spear' => ['Light armor', 'Spear', 'Shield'],
            'archer' => ['Light armor', 'Dagger', 'Bow', 'Arrows, 20'],
            'robes' => ['Robes', 'Staff', 'Healing draught'],
            'sword' => ['Medium armor', 'Sword'],
            'cash' => [],
        ];
    }

    private function grantStartingPackage(Character $character, string $package): int
    {
        $packages = self::startingPackages();
        if (! array_key_exists($package, $packages)) {
            throw ValidationException::withMessages(['gear_package' => 'Choose a starting package.']);
        }
        $costs = [];
        foreach ($packages as $key => $names) {
            $costs[$key] = $this->packageCost($names);
        }
        $bonus = max($costs) - $costs[$package];
        foreach ($packages[$package] as $name) {
            $item = Item::query()->whereRaw('lower(name) = ?', [strtolower($name)])->first();
            CharacterInventory::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'quantity' => 1,
            ]);
        }

        return $bonus;
    }

    /**
     * @param  list<string>  $names
     */
    private function packageCost(array $names): int
    {
        $total = 0;
        foreach ($names as $name) {
            $item = Item::query()->whereRaw('lower(name) = ?', [strtolower($name)])->first();
            if (! $item) {
                throw ValidationException::withMessages(['gear_package' => $name.' is not in the item catalog.']);
            }
            $total += (int) $item->cost_silver;
        }

        return $total;
    }

    private function feature(string $name, array $categories): ?Feature
    {
        $name = rtrim(trim($name), '.');

        return Feature::query()
            ->whereRaw('lower(name) = ?', [strtolower($name)])
            ->whereIn('category', $categories)
            ->first();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function startingGear(string $name): array
    {
        $clean = trim($name);
        $aliases = [
            '3 candles' => ['Candles, 5', 1],
            '3 rations' => ['Ration, one day', 3],
            'ration' => ['Ration, one day', 1],
            'rope' => ['Rope, 50 feet', 1],
            'grappling hook' => ['Grappling hook', 1],
            'fishing rod' => ['Fishing rod and tackle', 1],
            'history book' => ['History book', 1],
            'waterskin' => ['Waterskin, full', 1],
            'healer\'s supplies' => ['Healer\'s supplies, 10 treatments', 1],
        ];

        return $aliases[strtolower($clean)] ?? [$clean, 1];
    }

    private function attributeKey(string $label): string
    {
        $label = strtolower(trim(preg_replace('/\+\d+/', '', $label) ?? $label));

        return match ($label) {
            'agility' => 'agility',
            'intellect' => 'intellect',
            'resolve' => 'resolve',
            'social' => 'social',
            'strength' => 'strength',
            'vitality' => 'vitality',
            default => $label,
        };
    }

    /**
     * @param  array<string, int>  $attrs
     * @param  array<string, int>  $ranks
     * @param  array<string, int>  $resources
     * @param  array<int, string>  $flags
     * @param  array<int, int>  $spellIds
     * @param  array<string, Skill>  $skills
     * @param  array<string, string>  $errors
     * @param  array<int, string>  $ledger
     */
    private function applyFeature(Feature $feature, array $choices, array &$attrs, array &$ranks, array &$resources, array &$flags, array &$spellIds, $skills, array &$errors, array &$ledger, array &$granted, string $label): void
    {
        $choice = $choices[$feature->name] ?? [];
        $effects = FeatureMechanics::effects($feature->name);
        $hasSpellChoice = collect($effects)->contains(fn ($effect) => in_array($effect['choice'] ?? '', ['two_spells', 'learn_spells'], true));
        if (! $hasSpellChoice && ($grant = SpellGrant::fromText($feature->rules_text))) {
            $effects[] = ['choice' => 'learn_spells', 'grant' => $grant];
        }
        $hasTechnique = collect($effects)->contains(fn ($effect) => ($effect['choice'] ?? null) === 'technique');
        if (! $hasTechnique && preg_match('/learn one weapon technique/i', (string) $feature->rules_text)) {
            $effects[] = ['choice' => 'technique'];
        }
        foreach ($effects as $effect) {
            if (isset($effect['attribute'])) {
                $attrs[$effect['attribute']] += $effect['amount'];
                $ledger[] = $label.' increases '.ucfirst($effect['attribute']).' by '.$effect['amount'].'.';
            } elseif (isset($effect['skill'])) {
                $ranks[$effect['skill']] += $effect['amount'];
                $ledger[] = $label.' grants +'.$effect['amount'].' '.$effect['skill'].'.';
            } elseif (isset($effect['resource'])) {
                $resources[$effect['resource']] += $effect['amount'];
                $ledger[] = $label.' increases '.$effect['resource'].' by '.$effect['amount'].'.';
            } elseif (isset($effect['movement'])) {
                $resources['movement_major'] += $effect['movement']['major'];
                $resources['movement_minor'] += $effect['movement']['minor'];
                $ledger[] = $label.' increases movement.';
            } elseif (isset($effect['flag'])) {
                $flags[] = $effect['flag'];
                if (($choice['damage_type'] ?? null) && in_array($effect['flag'], ['uncanny_resistance', 'uncanny_weakness'], true)) {
                    $flags[] = $effect['flag'].':'.$choice['damage_type'];
                }
            } elseif (($effect['choice'] ?? null) === 'attribute') {
                $key = $choice['attribute'] ?? null;
                if (! isset($attrs[$key])) {
                    $errors['choices'] = $label.' needs an attribute.';
                } elseif (! empty($effect['capped']) && $attrs[$key] + $effect['amount'] > 10) {
                    $errors['choices'] = $label.' cannot raise an attribute above 10.';
                } else {
                    $attrs[$key] += $effect['amount'];
                    $ledger[] = $label.' increases '.ucfirst($key).' by '.$effect['amount'].'.';
                }
            } elseif (($effect['choice'] ?? null) === 'nonattack') {
                $skill = $skills[$choice['skill'] ?? ''] ?? null;
                if (! $skill || $skill->is_attack_skill) {
                    $errors['choices'] = $label.' needs one nonattack skill.';
                } else {
                    $ranks[$skill->key] += $effect['amount'];
                    $ledger[] = $label.' grants +'.$effect['amount'].' '.$skill->name.'.';
                }
            } elseif (($effect['choice'] ?? null) === 'nonattack_two') {
                $picked = $choice['skills'] ?? [];
                if (count($picked) !== 2 || count(array_unique($picked)) !== 2) {
                    $errors['choices'] = $label.' needs two different nonattack skills.';
                }
                foreach ($picked as $key) {
                    $skill = $skills[$key] ?? null;
                    if (! $skill || $skill->is_attack_skill) {
                        $errors['choices'] = $label.' needs nonattack skills.';
                    } else {
                        $ranks[$skill->key] += 1;
                    }
                }
            } elseif (($effect['choice'] ?? null) === 'talent') {
                $talent = Feature::query()->whereIn('category', ['talent', 'tactic'])->where('name', $choice['talent'] ?? '')->first();
                if (! $talent) {
                    $errors['choices'] = 'Talented needs a talent or tactic.';
                } elseif (! $this->talentRequirementsMet($talent, $this->creationGrants)) {
                    $errors['choices'] = $talent->name.' requires '.implode(', ', $talent->prerequisites_json ?: ['a requirement you do not meet']).'.';
                } else {
                    $this->applyFeature($talent, $choices, $attrs, $ranks, $resources, $flags, $spellIds, $skills, $errors, $ledger, $granted, $talent->name);
                    $granted[] = [$talent, 'talent', null, null];
                    $ledger[] = 'Talented grants '.$talent->name.'.';
                }
            } elseif (($effect['choice'] ?? null) === 'learn_spells') {
                $this->learnGrantedSpells($feature, $effect['grant'], $choice, $resources, $spellIds, $errors, $ledger, $label);
            } elseif (($effect['choice'] ?? null) === 'two_spells') {
                $ids = $choice['spells'] ?? [];
                if (count($ids) !== 2) {
                    $errors['choices'] = 'Magus learns two tier-0 spells.';
                } else {
                    foreach ($ids as $id) {
                        $spell = Spell::find($id);
                        if (! $spell || $spell->tier !== 0) {
                            $errors['choices'] = 'Magus spells must be tier 0.';
                        } else {
                            $spellIds[] = $spell->id;
                        }
                    }
                }
            } elseif (($effect['choice'] ?? null) === 'technique') {
                $this->learnTechnique($choice, $errors, $label);
            } elseif (($effect['choice'] ?? null) === 'damage_type') {
                if (empty($choice['damage_type'])) {
                    $errors['choices'] = $label.' needs a damage type: Fire, Storm, or Cold.';
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $choice
     * @param  array<string, string>  $errors
     */
    private function learnTechnique(array $choice, array &$errors, string $label): void
    {
        $id = (int) ($choice['weapon_type_id'] ?? 0);
        $type = $id > 0 ? WeaponType::query()->with(['item', 'techniques'])->find($id) : null;
        $technique = $type?->techniques->first();
        $hidden = $type?->item?->created_by && (int) $type->item->created_by !== (int) $this->actorId;
        if (! $technique || $hidden || str_contains(strtolower((string) $type->item?->description), 'counts as')) {
            $errors['choices'] = $label.' needs a weapon technique.';

            return;
        }
        if (in_array($type->id, $this->techniqueGrants, true)) {
            $errors['choices'] = 'That weapon technique is already chosen.';

            return;
        }
        $this->techniqueGrants[] = (int) $type->id;
    }

    /**
     * @param  array{count: int, orders: list<string>|null, choose_order: bool, all_orders: bool, max_tier: int|null}  $grant
     * @param  array<string, mixed>  $choice
     * @param  array<string, int>  $resources
     * @param  array<int, int>  $spellIds
     * @param  array<string, string>  $errors
     * @param  array<int, string>  $ledger
     */
    private function learnGrantedSpells(Feature $feature, array $grant, array $choice, array &$resources, array &$spellIds, array &$errors, array &$ledger, string $label): void
    {
        $ids = array_values(array_unique(array_map('intval', $choice['spells'] ?? [])));
        if (count($ids) > $grant['count']) {
            $errors['choices'] = $label.' learns up to '.$grant['count'].' spells.';

            return;
        }
        $orders = $grant['orders'];
        if ($grant['choose_order']) {
            $picked = trim((string) ($choice['order'] ?? ''));
            $orders = [SpellGrant::order($picked)];
            if ($orders[0] === null) {
                $errors['choices'] = $label.' needs an order.';

                return;
            }
        }
        $maxTier = $grant['max_tier'] ?? (int) ($resources['sorcerous_potential'] ?? 0);
        foreach ($ids as $id) {
            $spell = Spell::query()->with('order')->find($id);
            $visible = $spell && ($spell->created_by === null || (int) $spell->created_by === (int) $this->actorId);
            if (! $visible || (int) $spell->tier > $maxTier) {
                $errors['choices'] = $label.' spells must be level '.$maxTier.' or lower.';

                return;
            }
            if ($orders && ! in_array($spell->order?->name, $orders, true)) {
                $errors['choices'] = $label.' spells must come from '.implode(' or ', $orders).'.';

                return;
            }
            $spellIds[] = (int) $spell->id;
        }
        $alreadyGrantsSoul = collect(FeatureMechanics::effects($feature->name))->contains(fn ($effect) => ($effect['resource'] ?? null) === 'sorcerous_soul');
        if (! $alreadyGrantsSoul) {
            $resources['sorcerous_soul'] += $grant['count'];
            $ledger[] = $label.' grants '.$grant['count'].' Sorcerous Soul.';
        }
        if ($grant['all_orders']) {
            $this->grantedOrderNames = [...$this->grantedOrderNames, ...SpellGrant::ORDERS];
        } elseif ($orders) {
            $this->grantedOrderNames = [...$this->grantedOrderNames, ...$orders];
        }
    }

    /**
     * @return array{names: list<string>, shield: bool}
     */
    private function creationFeatureNames(Species $species, Background $background, HeroClass $class, array $input): array
    {
        $names = [];
        foreach (['primary', 'secondary'] as $slot) {
            foreach ($species->traits_json[$slot] ?? [] as $name) {
                $names[] = $name;
            }
        }
        foreach ([...($input['variance_primary'] ?? []), ...($input['variance_secondary'] ?? [])] as $name) {
            $names[] = $name;
        }
        if (! empty($input['choices']['Accursed']['primary'])) {
            $names[] = $input['choices']['Accursed']['primary'];
        }
        if (! empty($background->grants_json['feature'])) {
            $names[] = $background->grants_json['feature'];
        }
        $levelOne = collect($class->progression_json ?? [])->firstWhere('level', 1);
        if (! empty($levelOne['feature'])) {
            $names[] = $levelOne['feature'];
        }
        $shield = ($input['gear_package'] ?? '') === 'spear' || collect($input['purchases'] ?? [])->contains(function ($row) {
            $item = \App\Models\Item::query()->find($row['item_id'] ?? null);

            return $item && strcasecmp($item->name, 'Shield') === 0 && (int) ($row['quantity'] ?? 0) > 0;
        });

        return [
            'names' => collect($names)->map(fn ($name) => strtolower(rtrim(trim((string) $name), '.')))->values()->all(),
            'shield' => $shield,
        ];
    }

    /**
     * @param  array{names: list<string>, shield: bool}  $grants
     */
    private function talentRequirementsMet(Feature $talent, array $grants): bool
    {
        foreach ($talent->prerequisites_json ?? [] as $requirement) {
            if (! $this->requirementMet((string) $requirement, $grants['names'], $grants['shield'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $owned
     */
    private function requirementMet(string $text, array $owned, bool $shield): bool
    {
        $raw = rtrim(trim($text), '.');
        if ($raw === '' || strcasecmp($raw, 'none') === 0) {
            return true;
        }
        if (preg_match('/character level (\d+)/i', $raw, $level) === 1) {
            return (int) $level[1] <= 1;
        }
        if (strcasecmp($raw, 'Arms Mastery for that weapon') === 0) {
            return in_array('arms mastery', $owned, true);
        }
        if (preg_match('/\bor\b/i', $raw) === 1 && preg_match('/\band\b/i', $raw) !== 1) {
            foreach (preg_split('/\s+or\s+/i', $raw) ?: [] as $part) {
                if ($this->requirementMet($part, $owned, $shield)) {
                    return true;
                }
            }

            return false;
        }
        if (preg_match('/\band\b/i', $raw) === 1) {
            foreach (preg_split('/\s+and\s+/i', $raw) ?: [] as $part) {
                if (! $this->requirementMet($part, $owned, $shield)) {
                    return false;
                }
            }

            return true;
        }
        if (stripos($raw, 'wielded shield') !== false) {
            return $shield;
        }

        return in_array(strtolower($raw), $owned, true);
    }

    private function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
