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
