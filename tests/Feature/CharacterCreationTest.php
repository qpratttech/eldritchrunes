<?php

namespace Tests\Feature;

use App\Domain\Characters\CharacterAdvancement;
use App\Domain\Characters\CharacterCreator;
use App\Domain\Characters\CharacterPresenter;
use App\Domain\Combat\CombatService;
use App\Models\CharacterAttribute;
use App\Models\CharacterInventory;
use App\Models\Item;
use App\Models\CharacterSkill;
use App\Models\CharacterOrderAccess;
use App\Models\Skill;
use App\Models\Spell;
use App\Models\Background;
use App\Models\Campaign;
use App\Models\CombatEvent;
use App\Models\Encounter;
use App\Models\HeroClass;
use App\Models\Scene;
use App\Models\Species;
use App\Models\Specialization;
use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CharacterCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RulesetSeeder::class);
    }

    public function test_orc_soldier_warrior_records_species_background_and_role(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, [
            'name' => 'Vash',
            'gender' => 'woman',
            'height' => '6\'2"',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ]);

        $sheet = app(CharacterPresenter::class)->present($character);
        $steps = collect($sheet['attributes'])->pluck('step', 'key');

        $this->assertSame(9, $steps['strength']);
        $this->assertSame(8, $steps['vitality']);
        $this->assertSame(7, $steps['social']);
        config(['filesystems.disks.s3.url' => 'https://assets.example.test']);
        $this->assertSame('https://assets.example.test/species/orc.webp', app(CharacterPresenter::class)->present($character->fresh())['portrait_url']);
        $character->update(['portrait_path' => 'characters/vash.webp']);
        $this->assertSame('https://assets.example.test/characters/vash.webp', app(CharacterPresenter::class)->present($character->fresh())['portrait_url']);

        $this->assertSame(4, $sheet['resources']['wounds']['max']);
        $this->assertSame(3, $sheet['resources']['stamina']['max']);
        $this->assertSame(3, $sheet['resources']['luck']['max']);
        $this->assertSame(3, $sheet['skills']->firstWhere('key', 'melee')['rank']);
        $this->assertTrue(collect($sheet['inventory'])->contains(fn ($item) => $item['name'] === 'Helmet' && str_contains($item['detail'], 'Armor Save')));
        $this->assertNotNull(collect($sheet['attacks'])->firstWhere('kind', 'unarmed')['tier']);
        $this->assertSame(10, $sheet['gold']);
        $this->assertSame('woman', $sheet['gender']);
    }

    public function test_attribute_cap_moves_excess_to_another_attribute(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, [
            'name' => 'Sera',
            'species_id' => Species::query()->where('name', 'Elf')->value('id'),
            'variance_primary' => ['Greater Agility', 'Uncanny Agility'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Thief')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Outlaw')->value('id'),
            'primary_skills' => ['melee', 'stealth'],
            'other_skills' => ['awareness', 'athletics', 'survival'],
            'rogue_skill' => 'reflexes',
            'overflow' => ['agility' => 'strength'],
        ]);

        $steps = collect(app(CharacterPresenter::class)->present($character)['attributes'])->pluck('step', 'key');
        $this->assertSame(10, $steps['agility']);
        $this->assertSame(8, $steps['strength']);
    }

    public function test_attribute_focus_increases_the_chosen_attribute_by_one_step(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, [
            'name' => 'Vash',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Attribute Focus', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'choices' => ['Attribute Focus' => ['attribute' => 'agility']],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ]);

        $steps = collect(app(CharacterPresenter::class)->present($character)['attributes'])->pluck('step', 'key');
        $this->assertSame(7, $steps['agility']);
    }

    public function test_a_qualifying_variance_pick_opens_the_next_feature_in_the_chain(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, [
            'name' => 'Vash',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Monstrous Strength'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
            'overflow' => ['strength' => 'agility'],
        ]);

        $sheet = app(CharacterPresenter::class)->present($character);
        $steps = collect($sheet['attributes'])->pluck('step', 'key');
        $this->assertSame(10, $steps['strength']);
        $this->assertSame(7, $steps['agility']);
        $this->assertTrue(collect($sheet['features'])->contains(fn ($feature) => $feature['name'] === 'Monstrous Strength'));
    }

    public function test_a_chain_feature_is_refused_without_the_prior_pick(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(CharacterCreator::class)->create($user, [
            'name' => 'Vash',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Monstrous Strength', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ]);
    }

    public function test_a_character_can_hold_at_most_three_classes(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());
        $advancement = app(CharacterAdvancement::class);
        $advancement->addLevel($character, HeroClass::query()->where('name', 'Mage')->value('id'));
        $advancement->addLevel($character, HeroClass::query()->where('name', 'Outlaw')->value('id'));

        $this->expectException(ValidationException::class);
        $advancement->addLevel($character->fresh(), HeroClass::query()->where('name', 'Adept')->value('id'));
    }

    private function orc(): array
    {
        return [
            'name' => 'Vash',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ];
    }

    public function test_a_character_sheet_stores_suffering_from_zero_to_two(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());
        $this->assertSame(0, app(CharacterPresenter::class)->present($character)['suffering']);

        $this->actingAs($user)->patch("/characters/{$character->id}", ['suffering' => 2])->assertRedirect();
        $this->assertSame(2, app(CharacterPresenter::class)->present($character->fresh())['suffering']);

        $this->actingAs($user)->patch("/characters/{$character->id}", ['suffering' => 5])->assertSessionHasErrors('suffering');
        $this->actingAs($user)->patch("/characters/{$character->id}", ['suffering' => 0])->assertRedirect();
        $this->assertSame(0, app(CharacterPresenter::class)->present($character->fresh())['suffering']);
    }

    public function test_taking_wounds_adds_suffering_when_endurance_fails(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());
        $skill = Skill::query()->where('key', 'endurance')->firstOrFail();
        CharacterAttribute::query()->where('character_id', $character->id)->where('attribute_id', $skill->attribute_id)->update(['base_step' => 0]);
        CharacterSkill::query()->where('character_id', $character->id)->where('skill_id', $skill->id)->update(['base_rank' => 0]);
        $this->actingAs($user)->patch("/characters/{$character->id}", ['suffering' => 1])->assertRedirect();

        $result = app(CombatService::class)->endureWounds($character->fresh());

        $this->assertFalse($result['endurance']['success']);
        $this->assertSame(2, $result['suffering']);
        $this->assertSame(2, app(CombatService::class)->endureWounds($character->fresh())['suffering']);
    }

    public function test_a_character_attack_is_written_to_the_campaign_log(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());
        $campaign = Campaign::create(['owner_id' => $user->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $character->update(['campaign_id' => $campaign->id]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'name' => 'Skirmish',
            'status' => 'active',
        ]);
        $this->actingAs($user)->patch("/characters/{$character->id}", ['suffering' => 1])->assertRedirect();

        $this->actingAs($user)->post("/characters/{$character->id}/attacks", [
            'kind' => 'unarmed',
        ])->assertRedirect();

        $event = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'attack')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString($character->name.' attacked with Unarmed.', $event->payload_json['summary']);
        $this->assertStringNotContainsString('Major Action', $event->payload_json['summary']);
        $this->assertStringNotContainsString('Minor Action', $event->payload_json['summary']);
        $this->assertStringContainsString('Suffering 1.', $event->payload_json['summary']);
        $this->assertSame(0, \App\Models\CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'major_action')->value('current_value'));
        $this->actingAs($user)->post("/characters/{$character->id}/attacks", [
            'kind' => 'unarmed',
        ])->assertSessionHasErrors('action');
    }

    public function test_zero_wounds_defeats_a_character_until_they_come_to(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());
        $campaign = Campaign::create(['owner_id' => $user->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $character->update(['campaign_id' => $campaign->id]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'name' => 'Skirmish',
            'status' => 'active',
        ]);

        $this->actingAs($user)->patch("/characters/{$character->id}", [
            'resources' => ['wounds' => 0],
        ])->assertRedirect();

        $event = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'defeat')->first();
        $this->assertNotNull($event);
        $this->assertSame($character->name.' has been defeated.', $event->payload_json['summary']);
        $this->assertSame(0, \App\Models\CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'major_action')->value('current_value'));
        $this->assertSame(0, \App\Models\CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'minor_action')->value('current_value'));

        \App\Domain\Combat\ActionPool::refreshCharacter($character->fresh());
        $this->assertSame(0, \App\Models\CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'major_action')->value('current_value'));

        $this->actingAs($user)->post("/characters/{$character->id}/attacks", [
            'kind' => 'unarmed',
        ])->assertSessionHasErrors('action');

        $this->actingAs($user)->patch("/characters/{$character->id}", [
            'resources' => ['wounds' => 1],
        ])->assertRedirect();
        \App\Domain\Combat\ActionPool::refreshCharacter($character->fresh());
        $this->assertSame(1, \App\Models\CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'major_action')->value('current_value'));
        $this->assertSame(2, \App\Models\CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'minor_action')->value('current_value'));
    }

    public function test_a_magus_background_records_spell_order_access_without_timestamps(): void
    {
        $user = User::factory()->create();
        $spells = Spell::query()->where('tier', 0)->limit(2)->pluck('id')->all();

        $character = app(CharacterCreator::class)->create($user, [
            'name' => 'Iliya',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'choices' => ['Magus' => ['spells' => $spells]],
            'background_id' => Background::query()->where('name', 'Acolyte')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ]);

        $this->assertSame(2, $character->spells()->count());
        $this->assertGreaterThan(0, CharacterOrderAccess::query()->where('character_id', $character->id)->count());
    }

    public function test_a_character_sheet_saves_gold_and_silver(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());

        $this->actingAs($user)->patch("/characters/{$character->id}", [
            'gold' => 9,
            'silver' => 15,
        ])->assertRedirect();

        $sheet = app(CharacterPresenter::class)->present($character->fresh());
        $this->assertSame(10, $sheet['gold']);
        $this->assertSame(5, $sheet['silver']);
        $this->assertSame(105, $character->fresh()->wallet->balance_silver);
    }

    public function test_starting_packages_grant_gear_and_the_difference_in_gold(): void
    {
        $user = User::factory()->create();
        $spear = app(CharacterCreator::class)->create($user, array_merge($this->orc(), [
            'name' => 'Spearhand',
            'gear_package' => 'spear',
        ]));
        $names = $spear->inventory()->with('item')->get()->map(fn ($row) => $row->item->name);
        $this->assertTrue($names->contains('Light armor'));
        $this->assertTrue($names->contains('Spear'));
        $this->assertTrue($names->contains('Shield'));
        $this->assertTrue($names->contains('Backpack'));
        $this->assertSame(170, $spear->wallet->balance_silver);

        $cash = app(CharacterCreator::class)->create($user, array_merge($this->orc(), [
            'name' => 'Merchant',
            'gear_package' => 'cash',
        ]));
        $this->assertSame(230, $cash->wallet->balance_silver);
        $this->assertFalse($cash->inventory()->whereHas('item', fn ($query) => $query->where('name', 'Sword'))->exists());

        $sword = app(CharacterCreator::class)->create($user, array_merge($this->orc(), [
            'name' => 'Blade',
            'gear_package' => 'sword',
        ]));
        $this->assertSame(100, $sword->wallet->balance_silver);
        $this->assertTrue($sword->inventory()->whereHas('item', fn ($query) => $query->where('name', 'Medium armor'))->exists());
        $this->assertTrue($sword->inventory()->whereHas('item', fn ($query) => $query->where('name', 'Sword'))->exists());
    }

    public function test_talented_can_grant_an_eligible_tactic_and_refuses_an_unmet_requirement(): void
    {
        $user = User::factory()->create();
        $base = $this->orc();
        $base['name'] = 'Rook';
        $base['species_id'] = Species::query()->where('name', 'Halforc')->value('id');
        $base['choices'] = ['Talented' => ['talent' => 'Veteran']];

        try {
            app(CharacterCreator::class)->create($user, $base);
            $this->fail('Veteran should be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Veteran requires', $exception->errors()['choices'][0]);
        }

        $base['choices'] = ['Talented' => ['talent' => 'Charge']];
        $character = app(CharacterCreator::class)->create($user, $base);
        $this->assertTrue($character->features()->whereHas('feature', fn ($query) => $query->where('name', 'Charge'))->exists());
    }

    public function test_equipping_armor_adds_its_save_and_unequipping_removes_it(): void
    {
        $user = User::factory()->create();
        $character = app(CharacterCreator::class)->create($user, $this->orc());
        $light = Item::query()->where('name', 'Light armor')->firstOrFail();
        $medium = Item::query()->where('name', 'Medium armor')->firstOrFail();
        $lightRow = CharacterInventory::create(['character_id' => $character->id, 'item_id' => $light->id, 'quantity' => 1]);
        $mediumRow = CharacterInventory::create(['character_id' => $character->id, 'item_id' => $medium->id, 'quantity' => 1]);

        $this->assertSame(0, app(CharacterPresenter::class)->present($character->fresh())['armor']);

        $this->actingAs($user)->post("/characters/{$character->id}/equipment/{$lightRow->id}/equip")->assertRedirect();
        $this->assertSame(15, app(CharacterPresenter::class)->present($character->fresh())['armor']);
        $this->assertSame('worn', $lightRow->fresh()->equipped_slot);

        $this->actingAs($user)->post("/characters/{$character->id}/equipment/{$mediumRow->id}/equip")->assertRedirect();
        $this->assertSame(20, app(CharacterPresenter::class)->present($character->fresh())['armor']);
        $this->assertNull($lightRow->fresh()->equipped_slot);
        $this->assertSame('worn', $mediumRow->fresh()->equipped_slot);

        $this->actingAs($user)->post("/characters/{$character->id}/equipment/{$mediumRow->id}/equip")->assertRedirect();
        $this->assertSame(0, app(CharacterPresenter::class)->present($character->fresh())['armor']);
        $this->assertNull($mediumRow->fresh()->equipped_slot);
    }

    public function test_a_class_or_specialization_that_teaches_spells_uses_the_chosen_spells(): void
    {
        $user = User::factory()->create();
        $nature = Spell::query()->where('name', 'Ant Swarm')->firstOrFail();
        $circle = Spell::query()->where('name', 'Ancient Aegis')->firstOrFail();
        $psionic = Spell::query()->where('name', 'Mind Lance')->firstOrFail();
        $sword = \App\Models\WeaponType::query()->whereHas('item', fn ($query) => $query->where('name', 'Sword'))->firstOrFail();
        $base = [
            'name' => 'Vash',
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
        ];

        $outlander = app(CharacterCreator::class)->create($user, $base + [
            'class_id' => HeroClass::query()->where('name', 'Outlander')->value('id'),
            'primary_skills' => ['melee', 'ranged', 'survival', 'nature_lore'],
            'other_skills' => ['awareness', 'athletics', 'stealth'],
            'rogue_skill' => 'awareness',
            'choices' => ['Denizen of the Wild' => ['spells' => [$nature->id], 'weapon_type_id' => $sword->id]],
        ]);
        $this->assertTrue($outlander->spells()->where('spell_id', $nature->id)->exists());
        $this->assertTrue($outlander->techniques()->where('weapon_type_id', $sword->id)->exists());
        $this->assertSame(2, (int) $outlander->resources()->where('resource_key', 'sorcerous_soul')->value('base_maximum'));
        $this->assertSame(1, (int) $outlander->resources()->where('resource_key', 'focus')->value('base_maximum'));
        $this->assertTrue(\App\Models\CharacterOrderAccess::query()->where('character_id', $outlander->id)->where('order_id', $nature->order_id)->exists());

        try {
            app(CharacterCreator::class)->create($user, $base + [
                'name' => 'Wrong order',
                'class_id' => HeroClass::query()->where('name', 'Outlander')->value('id'),
                'primary_skills' => ['melee', 'ranged', 'survival', 'nature_lore'],
                'other_skills' => ['awareness', 'athletics', 'stealth'],
                'rogue_skill' => 'awareness',
                'choices' => ['Denizen of the Wild' => ['spells' => [$circle->id], 'weapon_type_id' => $sword->id]],
            ]);
            $this->fail('A Circle spell was accepted for a Nature grant.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Nature', $exception->validator->errors()->first('choices'));
        }

        $priest = app(CharacterCreator::class)->create($user, $base + [
            'name' => 'Priestess',
            'class_id' => HeroClass::query()->where('name', 'Priest')->value('id'),
            'primary_skills' => ['faith', 'mythos', 'medicine', 'diplomacy'],
            'other_skills' => ['awareness', 'athletics', 'stealth'],
            'choices' => ['Priesthood' => ['order' => 'Circle', 'spells' => [$circle->id]]],
        ]);
        $this->assertTrue($priest->spells()->where('spell_id', $circle->id)->exists());
        $this->assertSame(3, (int) $priest->resources()->where('resource_key', 'sorcerous_soul')->value('base_maximum'));

        $path = app(CharacterCreator::class)->create($user, $base + [
            'name' => 'Thinker',
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
            'specialization_id' => Specialization::query()->where('name', 'Psionic')->value('id'),
            'choices' => ['Psionic' => ['spells' => [$psionic->id]]],
        ]);
        $this->assertTrue($path->spells()->where('spell_id', $psionic->id)->exists());
        $this->assertSame(3, (int) $path->resources()->where('resource_key', 'sorcerous_soul')->value('base_maximum'));

        $knight = app(CharacterCreator::class)->create($user, array_merge($base, [
            'name' => 'Squire',
            'background_id' => Background::query()->where('name', 'Acolyte-Knight')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
            'choices' => ['Man-at-Arms' => ['weapon_type_id' => $sword->id]],
        ]));
        $known = $knight->techniques()->with('technique')->get()->map(fn ($row) => $row->technique?->name)->all();
        $this->assertSame(['Parry'], $known);
    }
}
