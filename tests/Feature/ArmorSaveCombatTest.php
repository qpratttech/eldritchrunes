<?php

namespace Tests\Feature;

use App\Domain\Characters\CharacterCreator;
use App\Domain\Combat\CombatService;
use App\Models\Background;
use App\Models\CharacterAttribute;
use App\Models\CharacterFeature;
use App\Models\CharacterResource;
use App\Models\CharacterInventory;
use App\Models\CharacterTechnique;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\Item;
use App\Models\Skill;
use App\Models\Species;
use App\Models\Technique;
use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArmorSaveCombatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RulesetSeeder::class);
    }

    public function test_a_hit_rolls_an_armor_save_and_armor_piercing_caps_it(): void
    {
        $user = User::factory()->create();
        $attacker = app(CharacterCreator::class)->create($user, $this->fighter('Brute'));
        $defender = app(CharacterCreator::class)->create($user, $this->fighter('Target'));
        $melee = Skill::query()->where('key', 'melee')->firstOrFail();
        CharacterAttribute::query()->where('character_id', $attacker->id)->where('attribute_id', $melee->attribute_id)->update(['base_step' => 20]);
        $heavy = Item::query()->where('name', 'Heavy armor')->firstOrFail();
        CharacterInventory::create([
            'character_id' => $defender->id,
            'item_id' => $heavy->id,
            'quantity' => 1,
            'equipped_slot' => 'worn',
        ]);
        $axe = Item::query()->where('name', 'Axe')->with('weapon')->firstOrFail();
        $hatchet = Item::query()->where('name', 'Hatchet')->firstOrFail();
        $combat = app(CombatService::class);

        $plain = $combat->sheetAttack($attacker->fresh(), 'weapon', $axe->id, null, false, null, null, $defender->fresh());
        $this->assertSame(30, $plain['armor_save']);
        $this->assertFalse($plain['armor_capped']);
        $this->assertStringContainsString('Target makes an Armor Save. (30% chance.)', $plain['summary']);
        $this->assertStringNotContainsString('Armor Piercing', $plain['summary']);
        $this->assertSame($plain['armor_saved'] ? 0 : 1, $plain['damage']);

        $this->ready($attacker);
        CharacterTechnique::create([
            'character_id' => $attacker->id,
            'technique_id' => Technique::query()->where('name', 'Armor Piercing')->value('id'),
            'weapon_type_id' => $axe->weapon->id,
            'source_type' => 'sheet',
        ]);

        $piercing = $combat->sheetAttack($attacker->fresh(), 'weapon', $axe->id, null, false, null, null, $defender->fresh());
        $this->assertSame(10, $piercing['armor_save']);
        $this->assertTrue($piercing['armor_capped']);
        $this->assertStringContainsString('Armor Piercing caps the Armor Save at 10%.', $piercing['summary']);
        $this->assertStringContainsString('Target makes an Armor Save. (10% chance.)', $piercing['summary']);

        $this->ready($attacker);
        $counted = $combat->sheetAttack($attacker->fresh(), 'weapon', $hatchet->id, null, false, null, null, $defender->fresh());
        $this->assertSame(10, $counted['armor_save']);
        $this->assertTrue($counted['armor_capped']);

        $this->ready($attacker);
        CharacterFeature::create([
            'character_id' => $defender->id,
            'feature_id' => Feature::query()->where('name', 'Rune Armor')->value('id'),
            'source_type' => 'class',
        ]);
        $protected = $combat->sheetAttack($attacker->fresh(), 'weapon', $axe->id, null, false, null, null, $defender->fresh());
        $this->assertSame(30, $protected['armor_save']);
        $this->assertTrue($protected['rune_armor']);
        $this->assertStringContainsString('Rune Armor prevents Armor Piercing.', $protected['summary']);
    }

    public function test_a_second_weapon_allows_a_diminished_minor_attack_and_a_third_is_greatly_diminished(): void
    {
        $user = User::factory()->create();
        $attacker = app(CharacterCreator::class)->create($user, $this->fighter('Brute'));
        $sword = Item::query()->where('name', 'Sword')->firstOrFail();
        $dagger = Item::query()->where('name', 'Dagger')->firstOrFail();
        CharacterInventory::create(['character_id' => $attacker->id, 'item_id' => $sword->id, 'quantity' => 1, 'equipped_slot' => 'wielded']);
        $combat = app(CombatService::class);

        $first = $combat->sheetAttack($attacker->fresh(), 'weapon', $sword->id, null, false);
        $this->assertSame(['major'], $first['actions']);
        $this->assertStringNotContainsString('Diminishment', $first['summary']);

        try {
            $combat->sheetAttack($attacker->fresh(), 'weapon', $dagger->id, null, false);
            $this->fail('A second attack needs a second wielded weapon or a feature.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame('Not enough actions to do that.', $exception->validator->errors()->first('action'));
        }

        CharacterInventory::create(['character_id' => $attacker->id, 'item_id' => $dagger->id, 'quantity' => 1, 'equipped_slot' => 'wielded']);
        $second = $combat->sheetAttack($attacker->fresh(), 'weapon', $dagger->id, null, false);
        $this->assertSame(['minor'], $second['actions']);
        $this->assertStringContainsString('Diminishment.', $second['summary']);
        $this->assertStringNotContainsString('Greater Diminishment', $second['summary']);

        $attacker->update(['derivation_json' => ['flags' => ['extra_attacks' => 1]]]);
        $third = $combat->sheetAttack($attacker->fresh(), 'weapon', $sword->id, null, false);
        $this->assertSame(['minor'], $third['actions']);
        $this->assertStringContainsString('Greater Diminishment.', $third['summary']);
    }

    public function test_armor_features_apply_when_their_conditions_are_met(): void
    {
        $user = User::factory()->create();
        $character = \App\Models\Character::create(['user_id' => $user->id, 'name' => 'Hide']);
        $this->grant($character, 'Armor Master');
        $this->grant($character, 'Battle Armor');
        $this->assertSame(0, $character->fresh()->armorSavePercent());

        $this->grant($character, 'Natural Armor');
        $this->assertSame(10, $character->fresh()->armorSavePercent());

        $heavy = Item::query()->where('name', 'Heavy armor')->firstOrFail();
        $worn = CharacterInventory::create([
            'character_id' => $character->id,
            'item_id' => $heavy->id,
            'quantity' => 1,
            'equipped_slot' => 'worn',
        ]);
        $this->assertSame(50, $character->fresh()->armorSavePercent());

        $worn->update(['equipped_slot' => null]);
        $this->assertSame(10, $character->fresh()->armorSavePercent());
    }

    private function grant(\App\Models\Character $character, string $name): void
    {
        CharacterFeature::create([
            'character_id' => $character->id,
            'feature_id' => Feature::query()->where('name', $name)->value('id'),
            'source_type' => 'class',
        ]);
    }

    private function ready(\App\Models\Character $character): void
    {
        CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'major_action')->update(['current_value' => 1]);
        CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'minor_action')->update(['current_value' => 2]);
        CharacterResource::query()->where('character_id', $character->id)->where('resource_key', 'extra_attacks_used')->update(['current_value' => 0]);
    }

    private function fighter(string $name): array
    {
        return [
            'name' => $name,
            'species_id' => Species::query()->where('name', 'Orc')->value('id'),
            'variance_primary' => ['Very Strong', 'Likable'],
            'variance_secondary' => ['Lucky'],
            'background_id' => Background::query()->where('name', 'Soldier')->value('id'),
            'class_id' => HeroClass::query()->where('name', 'Warrior')->value('id'),
            'primary_skills' => ['melee', 'athletics'],
            'other_skills' => ['awareness', 'stealth', 'survival'],
        ];
    }
}
