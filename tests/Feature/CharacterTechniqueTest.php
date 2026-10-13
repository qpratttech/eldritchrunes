<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterTechnique;
use App\Models\Item;
use App\Models\Technique;
use App\Models\User;
use App\Models\WeaponType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CharacterTechniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sheet_lists_weapon_techniques_and_the_owner_can_add_or_remove_them(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $character = Character::create(['user_id' => $owner->id, 'name' => 'Vash']);
        $sword = $this->weapon('Sword', 'Parry', 'Spend a minor action while wielding the trained weapon.');
        $this->weapon('Short Sword', 'Parry', 'Spend a minor action.', 'For technique training, this counts as a Sword.');
        $secret = $this->weapon('Hedge blade', 'Precision', 'A private technique.', null, $other->id);

        $this->actingAs($other)->post("/characters/{$character->id}/techniques", [
            'weapon_type_id' => $sword->id,
        ])->assertForbidden();

        $this->actingAs($owner)->post("/characters/{$character->id}/techniques", [
            'weapon_type_id' => $sword->id,
        ])->assertRedirect();
        $this->actingAs($owner)->post("/characters/{$character->id}/techniques", [
            'weapon_type_id' => $sword->id,
        ])->assertSessionHasErrors('weapon_type_id');
        $this->actingAs($owner)->post("/characters/{$character->id}/techniques", [
            'weapon_type_id' => $secret->id,
        ])->assertNotFound();

        $known = CharacterTechnique::query()->where('character_id', $character->id)->firstOrFail();
        $this->actingAs($owner)->get("/characters/{$character->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.techniques.0.name', 'Parry')
            ->where('sheet.techniques.0.weapon', 'Sword')
            ->where('sheet.techniques.0.detail', 'Spend a minor action while wielding the trained weapon.')
            ->where('techniques', function ($rows) use ($sword) {
                $names = collect($rows)->pluck('weapon');

                return $names->contains('Sword')
                    && ! $names->contains('Short Sword')
                    && ! $names->contains('Hedge blade')
                    && collect($rows)->contains(fn ($row) => (int) $row['id'] === $sword->id && $row['technique'] === 'Parry');
            })
        );

        $this->actingAs($owner)->delete("/characters/{$character->id}/techniques/{$known->id}")->assertRedirect();
        $this->assertDatabaseMissing('character_techniques', ['id' => $known->id]);
    }

    private function weapon(string $name, string $technique, string $rules, ?string $description = null, ?int $ownerId = null): WeaponType
    {
        $item = Item::create([
            'name' => $name,
            'category' => 'weapon',
            'description' => $description ?? 'A weapon.',
            'created_by' => $ownerId,
        ]);
        $type = WeaponType::create(['item_id' => $item->id]);
        $row = Technique::query()->firstOrCreate(['name' => $technique], [
            'category' => 'weapon',
            'description' => $rules,
        ]);
        $type->techniques()->attach($row->id);

        return $type;
    }
}
