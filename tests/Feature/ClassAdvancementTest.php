<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterClassLevel;
use App\Models\CharacterFeature;
use App\Models\CharacterResource;
use App\Models\CharacterOrderAccess;
use App\Models\CharacterSpell;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\MagicOrder;
use App\Models\Role;
use App\Models\Spell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassAdvancementTest extends TestCase
{
    use RefreshDatabase;

    public function test_advancing_applies_the_class_benefit_and_a_new_class_grants_its_starting_role(): void
    {
        $gm = User::factory()->create();
        $martial = Role::create(['name' => 'Martial']);
        $magus = Role::create(['name' => 'Magus']);
        $warrior = HeroClass::create([
            'name' => 'Warrior',
            'starting_role_id' => $martial->id,
            'progression_json' => [
                ['level' => 1, 'feature' => 'Hardened'],
                ['level' => 2, 'feature' => '+1 maximum Wound'],
            ],
        ]);
        $mage = HeroClass::create([
            'name' => 'Mage',
            'starting_role_id' => $magus->id,
            'progression_json' => [
                ['level' => 1, 'feature' => '+1 Sorcerous Power'],
            ],
        ]);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Vash', 'derivation_json' => ['ledger' => []]]);
        CharacterClassLevel::create(['character_id' => $character->id, 'class_id' => $warrior->id, 'class_level' => 1]);
        foreach (['wounds' => 3, 'sorcerous_power' => 0, 'sorcerous_potential' => 0] as $key => $value) {
            CharacterResource::create(['character_id' => $character->id, 'resource_key' => $key, 'base_maximum' => $value, 'current_value' => $value]);
        }

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", ['class_id' => $warrior->id])->assertRedirect();
        $this->assertSame(2, CharacterClassLevel::query()->where('class_id', $warrior->id)->value('class_level'));
        $this->assertSame(4, $this->resource($character, 'wounds'));

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", ['class_id' => $mage->id])->assertRedirect();
        $this->assertSame(1, CharacterClassLevel::query()->where('class_id', $mage->id)->value('class_level'));
        $this->assertSame(4, $this->resource($character, 'wounds'));
        $this->assertSame(2, $this->resource($character, 'sorcerous_power'));
        $this->assertSame(1, $this->resource($character, 'sorcerous_potential'));
    }

    public function test_a_level_that_needs_a_choice_waits_for_the_special_option(): void
    {
        $gm = User::factory()->create();
        $class = HeroClass::create([
            'name' => 'Druid',
            'progression_json' => [
                ['level' => 1, 'feature' => 'Druidic'],
                ['level' => 2, 'feature' => 'Greater Companion'],
            ],
        ]);
        Feature::create([
            'key' => 'greater-companion',
            'name' => 'Greater Companion',
            'category' => 'class',
            'rules_text' => 'Gain one direwolf, panther, or bear companion. Use its profile and the companion rules.',
        ]);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Vash']);
        CharacterClassLevel::create(['character_id' => $character->id, 'class_id' => $class->id, 'class_level' => 1]);

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
        ])->assertSessionHasErrors('choices');
        $this->assertSame(1, CharacterClassLevel::query()->where('class_id', $class->id)->value('class_level'));

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Greater Companion' => ['companion' => 'Bear']],
        ])->assertRedirect();
        $this->assertSame(2, CharacterClassLevel::query()->where('class_id', $class->id)->value('class_level'));
        $this->assertSame('Bear', $character->fresh()->derivation_json['companion']['name']);
        $this->assertTrue(CharacterFeature::query()->where('character_id', $character->id)->whereHas('feature', fn ($query) => $query->where('name', 'Greater Companion'))->exists());
    }

    public function test_templar_advancement_requires_an_order_and_level_zero_spells_from_it(): void
    {
        $gm = User::factory()->create();
        $class = HeroClass::create([
            'name' => 'Templar',
            'progression_json' => [
                ['level' => 1, 'feature' => 'Ready'],
                ['level' => 2, 'feature' => 'Warrior of the Temple'],
            ],
        ]);
        Feature::create([
            'key' => 'warrior-of-the-temple',
            'name' => 'Warrior of the Temple',
            'category' => 'class',
            'rules_text' => 'Gain access to one order of magic and learn up to two level-0 spells from it.',
        ]);
        $circle = MagicOrder::create(['name' => 'Circle']);
        $nature = MagicOrder::create(['name' => 'Nature']);
        $aegis = Spell::create(['name' => 'Ancient Aegis', 'order_id' => $circle->id, 'tier' => 0]);
        $swarm = Spell::create(['name' => 'Ant Swarm', 'order_id' => $nature->id, 'tier' => 0]);
        $lance = Spell::create(['name' => 'Mind Lance', 'order_id' => $circle->id, 'tier' => 1]);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Vash']);
        CharacterClassLevel::create(['character_id' => $character->id, 'class_id' => $class->id, 'class_level' => 1]);
        CharacterResource::create(['character_id' => $character->id, 'resource_key' => 'sorcerous_potential', 'base_maximum' => 3, 'current_value' => 3]);

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Warrior of the Temple' => ['spells' => [$aegis->id]]],
        ])->assertSessionHasErrors('choices');

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Warrior of the Temple' => ['order' => 'Circle', 'spells' => [$lance->id]]],
        ])->assertSessionHasErrors('choices');

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Warrior of the Temple' => ['order' => 'Circle', 'spells' => [$aegis->id, $swarm->id]]],
        ])->assertSessionHasErrors('choices');

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Warrior of the Temple' => ['order' => 'Circle', 'spells' => [$aegis->id]]],
        ])->assertRedirect();

        $this->assertTrue(CharacterSpell::query()->where('character_id', $character->id)->where('spell_id', $aegis->id)->exists());
        $this->assertFalse(CharacterSpell::query()->where('character_id', $character->id)->where('spell_id', $swarm->id)->exists());
        $this->assertTrue(CharacterOrderAccess::query()->where('character_id', $character->id)->where('order_id', $circle->id)->exists());
        $this->assertFalse(CharacterOrderAccess::query()->where('character_id', $character->id)->where('order_id', $nature->id)->exists());
    }

    public function test_an_order_only_advancement_asks_for_the_order_and_the_sheet_can_add_or_remove_one(): void
    {
        $gm = User::factory()->create();
        $class = HeroClass::create([
            'name' => 'Scholar',
            'progression_json' => [
                ['level' => 1, 'feature' => 'Ready'],
                ['level' => 2, 'feature' => 'Elder Knowledge'],
            ],
        ]);
        Feature::create([
            'key' => 'elder-knowledge',
            'name' => 'Elder Knowledge',
            'category' => 'talent',
            'rules_text' => 'Gain access to one order of magic. This does not grant spells, Soul capacity, or additional Potential.',
        ]);
        $circle = MagicOrder::create(['name' => 'Circle']);
        $shadow = MagicOrder::create(['name' => 'Shadow']);
        $character = Character::create(['user_id' => $gm->id, 'name' => 'Vash']);
        CharacterClassLevel::create(['character_id' => $character->id, 'class_id' => $class->id, 'class_level' => 1]);

        $this->assertContains('order', \App\Domain\Characters\CharacterAdvancement::choiceKinds('Elder Knowledge'));

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Elder Knowledge' => []],
        ])->assertSessionHasErrors('choices');

        $this->actingAs($gm)->post("/characters/{$character->id}/classes", [
            'class_id' => $class->id,
            'choices' => ['Elder Knowledge' => ['order' => 'Circle']],
        ])->assertRedirect();

        $access = CharacterOrderAccess::query()->where('character_id', $character->id)->where('order_id', $circle->id)->first();
        $this->assertNotNull($access);
        $this->assertSame(0, CharacterSpell::query()->where('character_id', $character->id)->count());

        $this->actingAs($gm)->post("/characters/{$character->id}/orders", ['name' => 'Shadow'])->assertRedirect();
        $added = CharacterOrderAccess::query()->where('character_id', $character->id)->where('order_id', $shadow->id)->first();
        $this->assertNotNull($added);

        $this->actingAs($gm)->delete("/characters/{$character->id}/orders/{$added->id}")->assertRedirect();
        $this->assertFalse(CharacterOrderAccess::query()->where('character_id', $character->id)->where('order_id', $shadow->id)->exists());
        $this->assertTrue(CharacterOrderAccess::query()->whereKey($access->id)->exists());
    }

    private function resource(Character $character, string $key): int
    {
        return (int) CharacterResource::query()->where('character_id', $character->id)->where('resource_key', $key)->value('base_maximum');
    }
}
