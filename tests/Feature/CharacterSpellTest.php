<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSpell;
use App\Models\MagicOrder;
use App\Models\Spell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CharacterSpellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sheet_lists_known_spells_and_the_owner_can_add_or_remove_them(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $character = Character::create(['user_id' => $owner->id, 'name' => 'Vash']);
        $order = MagicOrder::create(['name' => 'Circle', 'power_source' => 'Dazgolmic']);
        $spark = Spell::create([
            'name' => 'Arcane Spark',
            'order_id' => $order->id,
            'tier' => 0,
            'flavor_text' => 'A blue-white spark.',
            'rules_text' => 'Make a direct spell attack.',
            'casting_time' => 'Major action',
            'targeting_json' => ['text' => '30 feet'],
            'duration_json' => ['text' => 'Instant.'],
            'empowered_effects_json' => ['text' => 'The attack gains Armor Piercing.'],
        ]);
        $secret = Spell::create([
            'name' => 'Secret Spark',
            'tier' => 0,
            'created_by' => $other->id,
            'rules_text' => 'Hidden.',
        ]);

        $this->actingAs($other)->post("/characters/{$character->id}/spells", [
            'spell_id' => $spark->id,
        ])->assertForbidden();

        $this->actingAs($owner)->post("/characters/{$character->id}/spells", [
            'spell_id' => $secret->id,
        ])->assertNotFound();

        $this->actingAs($owner)->post("/characters/{$character->id}/spells", [
            'spell_id' => $spark->id,
        ])->assertRedirect();

        $known = CharacterSpell::query()->where('character_id', $character->id)->where('spell_id', $spark->id)->firstOrFail();

        $this->actingAs($owner)->get("/characters/{$character->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.spells.0.name', 'Arcane Spark')
            ->where('sheet.spells.0.detail', fn ($detail) => str_contains($detail, 'A blue-white spark.') && str_contains($detail, 'Empowered. The attack gains Armor Piercing.'))
            ->where('spells', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Arcane Spark')
                && collect($rows)->doesntContain(fn ($row) => $row['name'] === 'Secret Spark'))
        );

        $this->actingAs($owner)->delete("/characters/{$character->id}/spells/{$known->id}")->assertRedirect();
        $this->assertDatabaseMissing('character_spells', ['id' => $known->id]);
    }
}
