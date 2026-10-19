<?php

namespace Database\Seeders;

use App\Models\AdvancementThreshold;
use App\Models\ArmorType;
use App\Models\Attribute;
use App\Models\Background;
use App\Models\ConditionDefinition;
use App\Models\CreatureAction;
use App\Models\CreatureTemplate;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\Item;
use App\Models\MagicOrder;
use App\Models\OptionGrant;
use App\Models\PercentileTier;
use App\Models\Role;
use App\Models\RulesetVersion;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\Species;
use App\Models\Spell;
use App\Models\Technique;
use App\Models\WeaponType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RulesetSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/core-rules.json')), true);

        RulesetVersion::query()->firstOrCreate(['version' => '2e'], [
            'name' => 'Eldritch Runes 2E',
            'status' => 'active',
            'settings_json' => [
                'attribute_cap' => 10,
                'max_character_level' => 10,
                'max_classes' => 3,
                'gold_to_silver' => 10,
                'starting_gold' => 10,
            ],
        ]);

        $attributes = collect($data['attributes'])->mapWithKeys(function (array $row) {
            $attribute = Attribute::query()->updateOrCreate(['key' => $row['key']], $row);

            return [$row['key'] => $attribute];
        });

        $skills = collect($data['skills'])->mapWithKeys(function (array $row) use ($attributes) {
            $skill = Skill::query()->updateOrCreate(['key' => $row['key']], [
                'name' => $row['name'],
                'attribute_id' => $attributes[$row['attribute']]->id,
                'is_attack_skill' => $row['is_attack_skill'],
                'description' => null,
            ]);

            return [$row['key'] => $skill];
        });

        foreach ($data['tiers'] as $tier) {
            PercentileTier::query()->updateOrCreate(['tier' => $tier['tier']], [
                'success_percent' => $tier['success_percent'],
                'outcome_type' => $tier['outcome_type'],
            ]);
        }

        $roles = collect([
            'Magus' => ['Sorcerous Potential +1 and maximum Sorcerous Power +1.', ['potential' => 1, 'power' => 1], ['potential' => 1, 'spells' => 2]],
            'Martial' => ['Maximum Wounds +1 and maximum Stamina +1.', ['wounds' => 1, 'stamina' => 1], ['wounds' => 1, 'talent_or_tactic' => 1]],
            'Rogue' => ['Initiative Bonus +2 and +1 rank in one nonattack skill.', ['initiative' => 2, 'nonattack_skill' => 1], ['initiative' => 1, 'crit_range' => 2]],
        ])->mapWithKeys(function (array $row, string $name) {
            return [$name => Role::query()->updateOrCreate(['name' => $name], [
                'description' => $row[0],
                'starting_json' => $row[1],
                'increase_json' => $row[2],
            ])];
        });

        foreach ($data['features'] as $row) {
            if (in_array($row['category'] ?? '', ['talent', 'tactic'], true)) {
                continue;
            }
            $name = rtrim(trim($row['name']), '.');
            Feature::query()->updateOrCreate(
                ['category' => $row['category'], 'key' => $row['key'] ?: Str::slug($name)],
                [
                    'name' => $name,
                    'flavor_text' => $row['flavor_text'] ?? null,
                    'rules_text' => $row['rules_text'] ?? null,
                    'activation_type' => $row['activation_type'] ?? 'passive',
                    'prerequisites_json' => $row['prerequisites'] ?? [],
                    'effects_json' => null,
                    'choices_schema_json' => null,
                ]
            );
        }
        $this->seedTalentsAndTactics();

        foreach ($data['species'] as $row) {
            $clean = fn (string $name) => rtrim(trim($name), '.');
            $species = Species::query()->updateOrCreate(['name' => $row['name']], [
                'description' => $row['description'],
                'portrait_path' => $this->speciesPortraits()[$row['name']] ?? null,
                'traits_json' => [
                    'primary' => array_map($clean, $row['primary']),
                    'secondary' => array_map($clean, $row['secondary']),
                ],
            ]);
            foreach (array_merge($row['primary'], $row['secondary']) as $feature) {
                OptionGrant::query()->updateOrCreate([
                    'option_type' => 'species',
                    'option_id' => $species->id,
                    'grant_type' => 'feature',
                    'grant_key' => $clean($feature),
                ], ['amount' => 1]);
            }
        }

        foreach ($data['backgrounds'] as $row) {
            $grants = [
                'attributes' => array_map(fn ($value) => trim(preg_replace('/\+\d+/', '', $value) ?? $value), $row['attributes']),
                'skills' => array_map(fn ($value) => trim($value), $row['skills']),
                'feature' => rtrim(trim((string) $row['feature']), '.'),
                'equipment' => array_map('trim', $row['equipment']),
            ];
            $background = Background::query()->updateOrCreate(['name' => $row['name']], [
                'description' => $row['description'],
                'grants_json' => $grants,
            ]);
            OptionGrant::query()->updateOrCreate([
                'option_type' => 'background',
                'option_id' => $background->id,
                'grant_type' => 'feature',
                'grant_key' => $grants['feature'],
            ], ['amount' => 1]);
        }

        foreach ($data['classes'] as $row) {
            $class = HeroClass::query()->updateOrCreate(['name' => $row['name']], [
                'description' => $row['description'],
                'starting_role_id' => $roles[$row['starting_role']]->id ?? null,
                'progression_json' => $row['levels'],
                'note' => $row['note'] ?? null,
            ]);
            $class->primarySkills()->sync(
                collect($row['primary_skills'])->map(fn ($name) => $skills->first(fn ($skill) => strcasecmp($skill->name, trim($name)) === 0)?->id)->filter()->all()
            );
        }

        foreach ($data['specializations'] as $row) {
            if ($row['name'] === 'Beast') {
                $bestial = Specialization::query()->where('name', 'Bestial')->first();
                if ($bestial && ! Specialization::query()->where('name', 'Beast')->exists()) {
                    $bestial->update(['name' => 'Beast']);
                }
            }
            Specialization::query()->updateOrCreate(['name' => $row['name']], [
                'description' => $row['description'],
                'progression_json' => $row['levels'],
            ]);
        }

        foreach ($data['advancement'] as $row) {
            AdvancementThreshold::query()->updateOrCreate(['character_level' => $row['level']], [
                'cumulative_xp' => $row['xp'],
            ]);
        }

        $this->seedEquipment($skills);
        $this->seedMagic($skills);
        foreach ($data['conditions'] as $row) {
            ConditionDefinition::query()->updateOrCreate(['key' => $row['key']], [
                'name' => $row['name'],
                'description' => $row['description'],
            ]);
        }
        ConditionDefinition::query()->updateOrCreate(['key' => 'suffering'], [
            'name' => 'Suffering',
            'description' => 'Each level imposes −1 tier on checks, attacks, and saves, to a maximum of two levels.',
        ]);
        foreach ($data['creatures'] as $row) {
            $extras = $this->creatureExtras($row['name']);
            $template = CreatureTemplate::query()->updateOrCreate(['name' => $row['name']], [
                'category' => $row['category'],
                'description' => $row['description'],
                'portrait_path' => $this->creaturePortraits()[$row['name']] ?? null,
                'base_wounds' => $row['base_wounds'],
                'armor_save_percent' => $row['armor_save_percent'],
                'major_move_ft' => $row['major_move_ft'],
                'minor_move_ft' => $row['minor_move_ft'],
                'stats_json' => array_merge([
                    'attack_tier' => $row['attack_tier'],
                    'endurance' => $row['endurance'],
                    'reflexes' => $row['reflexes'],
                    'willpower' => $row['willpower'],
                    'other' => $row['other'],
                ], array_intersect_key($row, array_flip(['melee', 'ranged', 'stamina', 'initiative', 'armor_note', 'behavior']))),
                'traits_json' => [
                    'skills' => $extras['skills'],
                    'features' => $extras['features'],
                ],
            ]);
            $template->actions()->delete();
            foreach ($extras['attacks'] as $attack) {
                CreatureAction::query()->create([
                    'creature_template_id' => $template->id,
                    'name' => $attack['name'],
                    'action_cost' => $attack['cost'] ?? 'major',
                    'attack_tier' => ($attack['resolution'] ?? 'attack') === 'attack' ? ($attack['tier'] ?? $row['attack_tier']) : null,
                    'range_ft' => $attack['range_ft'] ?? 5,
                    'resolution_type' => $attack['resolution'] ?? 'attack',
                    'effects_json' => array_filter([
                        'damage' => $attack['damage'],
                        'tip' => $attack['tip'],
                        'heavy' => ($attack['heavy'] ?? false) ? true : null,
                        'two_handed' => ($attack['two_handed'] ?? false) ? true : null,
                    ], fn ($value) => $value !== null),
                ]);
            }
        }
    }

    /**
     * @return array{skills: array<int, array{name: string, tip: string}>, features: array<int, array{name: string, tip: string}>, attacks: array<int, array<string, mixed>>}
     */
    private function creatureExtras(string $name): array
    {
        $natural = 'This is a natural attack. It deals normal damage rather than Lesser Attack damage and does not take the armed-opponent penalty. Critical range is 01–03.';

        return match ($name) {
            'Small Companion' => [
                'skills' => [['name' => 'Chosen aptitude', 'tip' => 'Choose Awareness, Stealth, or Athletics. Checks with that skill are Enhanced.']],
                'features' => [
                    ['name' => 'Flight', 'tip' => 'Raven, owl, eagle, and bat fly at their normal movement.'],
                    ['name' => 'Climb', 'tip' => 'Monkey and snake climb at their normal movement.'],
                    ['name' => 'Hearing', 'tip' => 'A bat navigates ordinary darkness within 20 feet by hearing. Deafness prevents this.'],
                ],
                'attacks' => [['name' => 'Natural attack', 'damage' => '1 Physical', 'tip' => $natural]],
            ],
            'Direwolf' => [
                'skills' => [['name' => 'Survival', 'tip' => 'Survival checks to track by scent are Enhanced.']],
                'features' => [],
                'attacks' => [['name' => 'Bite', 'damage' => '1 Physical', 'tip' => 'Its bite deals 1 Physical damage. '.$natural]],
            ],
            'Panther' => [
                'skills' => [['name' => 'Stealth', 'tip' => 'Stealth checks are Enhanced.']],
                'features' => [],
                'attacks' => [['name' => 'Claws', 'damage' => '1 Physical', 'tip' => 'Its claws deal 1 Physical damage. '.$natural]],
            ],
            'Bear' => [
                'skills' => [['name' => 'Athletics', 'tip' => 'Athletics checks are Enhanced.']],
                'features' => [],
                'attacks' => [['name' => 'Claws', 'damage' => '1 Physical', 'tip' => 'Its claws deal 1 Physical damage. '.$natural]],
            ],
            'Spider of Kadthor' => [
                'skills' => [],
                'features' => [['name' => 'Climber', 'tip' => 'It climbs walls and ceilings at its normal movement.']],
                'attacks' => [
                    ['name' => 'Bite', 'damage' => '1 Eldritch', 'tip' => 'Its bite deals 1 Eldritch damage. '.$natural],
                    ['name' => 'Web', 'damage' => 'Held', 'range_ft' => 20, 'resolution' => 'save', 'tip' => 'Once during the summon, spend a major action instead of attacking. One creature within 20 feet makes a Reflexes save or becomes Held. A major action or a normal Held save removes the webbing.'],
                ],
            ],
            'Elemental' => [
                'skills' => [],
                'features' => [
                    ['name' => 'Uncanny Resistance', 'tip' => 'Choose earth (Physical), air (Storm), fire (Fire), or water (Cold). It has Uncanny Resistance to that damage type.'],
                    ['name' => 'Elemental movement', 'tip' => 'Air flies and water swims at normal movement. Earth and fire move on solid ground.'],
                ],
                'attacks' => [['name' => 'Strike', 'damage' => '1, type chosen', 'tip' => 'Its strike deals 1 damage of the chosen element. '.$natural]],
            ],
            'Grave Servitor' => [
                'skills' => [],
                'features' => [
                    ['name' => 'Undead', 'tip' => 'It is undead and immune to Fear and Poisoned. It otherwise suffers damage and Suffering normally.'],
                ],
                'attacks' => [['name' => 'Crude strike', 'damage' => '1 Physical', 'tip' => 'Its crude strike deals 1 Physical damage. '.$natural]],
            ],
            'Skeleton' => [
                'skills' => [],
                'features' => [
                    ['name' => 'Undead Frame', 'tip' => 'Immune to Bleeding, Fear, Horror, and Poisoned. It otherwise suffers Suffering normally, representing damage that disrupts its animation. At 0 Wounds, it collapses into inert bones and is destroyed.'],
                ],
                'attacks' => [
                    ['name' => 'Rusted Sword', 'tier' => 8, 'range_ft' => 5, 'damage' => '1 Physical', 'tip' => 'Make a Melee attack, dealing 1 Physical damage on a hit.'],
                ],
            ],
            'Goblin Archer' => [
                'skills' => [
                    ['name' => 'Awareness', 'tier' => 9, 'tip' => 'Awareness checks use tier 9.'],
                    ['name' => 'Stealth', 'tier' => 9, 'tip' => 'Stealth checks use tier 9.'],
                ],
                'features' => [
                    ['name' => 'Precision', 'tip' => 'Bow Technique. Spend a minor action to Enhance its next bow attack that turn.'],
                    ['name' => 'Nightvision', 'tip' => 'Ignores Diminishment from dim light.'],
                ],
                'attacks' => [
                    ['name' => 'Shortbow', 'tier' => 9, 'range_ft' => 60, 'damage' => '1 Physical', 'tip' => 'Make a Ranged attack, dealing 1 Physical damage on a hit. Long range is 120 feet.'],
                    ['name' => 'Dagger', 'tier' => 7, 'range_ft' => 5, 'damage' => '1 Physical', 'tip' => 'Make a Melee attack, dealing 1 Physical damage on a hit.'],
                    ['name' => 'Thrown Dagger', 'tier' => 9, 'range_ft' => 20, 'damage' => '1 Physical', 'tip' => 'Throw the dagger and make a Ranged attack, dealing 1 Physical damage on a hit.'],
                ],
            ],
            'Bandit Mauler' => [
                'skills' => [
                    ['name' => 'Athletics', 'tier' => 9, 'tip' => 'Athletics checks use tier 9.'],
                    ['name' => 'Coercion', 'tier' => 9, 'tip' => 'Coercion checks use tier 9.'],
                ],
                'features' => [
                    ['name' => 'Brutal', 'tip' => 'Maul Technique. When its maul causes a target to gain Suffering, direct melee attacks against that target are Enhanced for 1 round. The target can end this effect early with its normal start-of-turn Endurance save.'],
                    ['name' => 'Shake Off Pain', 'tip' => 'Spend a minor action and 1 Stamina to remove one level of Suffering.'],
                ],
                'attacks' => [
                    ['name' => 'Great Maul', 'tier' => 9, 'range_ft' => 5, 'damage' => '1 Physical', 'tip' => 'Make a Melee attack, dealing 1 Physical damage on a hit.'],
                    ['name' => 'Heavy Blow', 'tier' => 9, 'range_ft' => 5, 'cost' => 'major and minor', 'damage' => '1 Physical', 'heavy' => true, 'two_handed' => true, 'tip' => 'Attack with the maul, dealing 2 Physical damage on a hit. A critical adds 2 wounds, as normal for a two-handed melee attack.'],
                ],
            ],
            default => [
                'skills' => [],
                'features' => [],
                'attacks' => [['name' => 'Natural attack', 'damage' => '1', 'tip' => $natural]],
            ],
        };
    }

    private function seedTalentsAndTactics(): void
    {
        foreach (require database_path('data/talents.php') as $row) {
            Feature::query()->updateOrCreate(
                ['category' => $row['category'], 'key' => $row['key']],
                [
                    'name' => $row['name'],
                    'flavor_text' => $row['flavor_text'],
                    'rules_text' => $row['rules_text'],
                    'activation_type' => $row['activation_type'],
                    'prerequisites_json' => $row['prerequisites'],
                    'effects_json' => $row['effects'],
                    'choices_schema_json' => null,
                ]
            );
        }
    }

    private function seedEquipment($skills): void
    {
        $catalog = require database_path('data/equipment.php');
        foreach ([
            'Mace' => 'Mace/Maul',
            'Great Mace' => 'Great Mace/Maul',
            'Arrows or bolts, 20' => 'Arrows, 20',
            'Candle' => 'Candles, 5',
            'Ration' => 'Ration, one day',
            'Waterskin' => 'Waterskin, full',
            'Healer\'s supplies' => 'Healer\'s supplies, 10 treatments',
        ] as $from => $to) {
            if (! Item::query()->where('name', $to)->exists()) {
                Item::query()->where('name', $from)->update(['name' => $to]);
            }
        }

        $techniques = [];
        foreach ($catalog['techniques'] as $name => $rules) {
            $techniques[$name] = Technique::query()->updateOrCreate(['name' => $name], [
                'category' => 'weapon',
                'description' => $rules,
            ]);
        }

        foreach ($catalog['weapons'] as [$name, $skill, $hands, $weight, $cost, $reach, $range, $technique, $great, $moveOrFire, $worn, $flavor, $rules]) {
            $item = Item::query()->updateOrCreate(['name' => $name], [
                'category' => 'weapon',
                'description' => trim($flavor.' '.$rules.($technique ? ' Technique: '.$technique.'.' : '')),
                'cost_silver' => $cost,
                'weight' => $weight,
                'stackable' => false,
                'effects_json' => ['rules' => $rules, 'technique' => $technique],
            ]);
            $weapon = WeaponType::query()->updateOrCreate(['item_id' => $item->id], [
                'hands_required' => $hands,
                'attack_skill_id' => $skills[$skill]->id,
                'damage_type' => 'physical',
                'base_damage' => 1,
                'damage_mode' => $worn ? 'lesser' : 'normal',
                'melee_reach_ft' => $skill === 'ranged' ? null : ($reach ?? 5),
                'normal_range_ft' => $range,
                'properties_json' => [
                    'text' => $rules,
                    'great' => $great,
                    'move_or_fire' => $moveOrFire,
                    'worn' => $worn,
                ],
            ]);
            $weapon->techniques()->sync($technique && isset($techniques[$technique]) ? [$techniques[$technique]->id] : []);
        }

        foreach ($catalog['armor'] as [$name, $category, $save, $weight, $cost, $description]) {
            $item = Item::query()->updateOrCreate(['name' => $name], [
                'category' => 'armor',
                'description' => $description,
                'cost_silver' => $cost,
                'weight' => $weight,
                'stackable' => false,
            ]);
            ArmorType::query()->updateOrCreate(['item_id' => $item->id], [
                'armor_category' => $category,
                'base_save_percent' => $save,
                'properties_json' => [
                    'slowed' => $category === 'heavy',
                    'loud' => $category === 'heavy',
                    'diminishes_melee' => $category === 'shield',
                ],
            ]);
        }

        foreach ($catalog['gear'] as [$name, $category, $weight, $cost, $stackable, $description]) {
            Item::query()->updateOrCreate(['name' => $name], [
                'category' => $category,
                'description' => $description,
                'cost_silver' => $cost,
                'weight' => $weight,
                'stackable' => $stackable,
            ]);
        }
    }

    private function seedMagic($skills): void
    {
        $orders = [
            'Circle' => ['Dazgolmic', 'sorcery'],
            'Elemental' => ['Zaad-Zar', 'sorcery'],
            'Forbidden' => ['Old Gods', 'sorcery'],
            'Mythic' => ['Finnron', 'faith'],
            'Nature' => ['Zabnulian', 'shamanism'],
            'Necromancy' => ['Monthulikru', 'sorcery'],
            'Psionic' => ['Self', 'sorcery'],
            'Shamanism' => ['Vyesp', 'shamanism'],
            'Runic' => ['Ulthorian', 'sorcery'],
            'Shadow' => ["R'jelan", 'sorcery'],
            'Spirit' => ['Nyarn', 'shamanism'],
        ];
        $saved = [];
        foreach ($orders as $name => [$source, $skillKey]) {
            $saved[$name] = MagicOrder::query()->updateOrCreate(['name' => $name], [
                'power_source' => $source,
                'casting_skill_id' => $skills[$skillKey]->id,
            ]);
        }
        $spells = require database_path('data/spells.php');
        foreach ($spells as $row) {
            Spell::query()->updateOrCreate(['name' => $row['name']], [
                'order_id' => $saved[$row['order']]->id,
                'tier' => $row['tier'],
                'flavor_text' => $row['flavor_text'],
                'rules_text' => $row['rules_text'],
                'casting_time' => $row['casting_time'],
                'targeting_json' => ['text' => $row['range_text']],
                'duration_json' => ['text' => $row['duration_text']],
                'requires_focus' => (bool) $row['requires_focus'],
                'empowered_effects_json' => ['text' => $row['empowered']],
            ]);
        }
        Spell::query()->whereNull('created_by')->whereNotIn('name', array_column($spells, 'name'))->delete();
    }

    /**
     * @return array<string, string>
     */
    private function creaturePortraits(): array
    {
        return [
            'Bear' => 'npcs/bear.webp',
            'Bandit Mauler' => 'npcs/bandit-mauler.webp',
            'Direwolf' => 'npcs/direwolf.webp',
            'Elemental' => 'npcs/elemental-2.webp',
            'Goblin Archer' => 'npcs/goblin-archer.webp',
            'Grave Servitor' => 'npcs/grave-servitor.webp',
            'Panther' => 'npcs/panther.webp',
            'Skeleton' => 'npcs/skeleton.webp',
            'Small Companion' => 'npcs/small-companion.webp',
            'Spider of Kadthor' => 'npcs/spider.webp',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function speciesPortraits(): array
    {
        return [
            'Drakith' => 'species/drakith.webp',
            'Dwarf' => 'species/dwarf.webp',
            'Eldretchin' => 'species/eldretchin.webp',
            'Elf' => 'species/elf.webp',
            'Gnome' => 'species/gnome.webp',
            'Halfogre' => 'species/half-ogre.webp',
            'Halforc' => 'species/half-orc.webp',
            'Halfling' => 'species/halfling.webp',
            'Human' => 'species/human.webp',
            'Lizardman' => 'species/lizardman.webp',
            'Minotaur' => 'species/minotaur.webp',
            'Orc' => 'species/orc.webp',
        ];
    }
}
