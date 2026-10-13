<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterInventory;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_add_catalog_and_custom_items_and_remove_them(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $character = Character::create(['user_id' => $owner->id, 'name' => 'Vash']);
        $torch = Item::create(['name' => 'Torch', 'category' => 'gear', 'stackable' => true]);

        $this->actingAs($other)->post("/characters/{$character->id}/inventory", [
            'item_id' => $torch->id,
            'quantity' => 1,
        ])->assertForbidden();

        $this->actingAs($owner)->post("/characters/{$character->id}/inventory", [
            'item_id' => $torch->id,
            'quantity' => 2,
        ])->assertRedirect();
        $this->actingAs($owner)->post("/characters/{$character->id}/inventory", [
            'name' => 'Lucky coin',
            'quantity' => 1,
        ])->assertRedirect();

        $coin = Item::query()->where('name', 'Lucky coin')->first();
        $this->assertNotNull($coin);
        $this->assertSame($owner->id, $coin->created_by);
        $this->assertDatabaseHas('character_inventory', [
            'character_id' => $character->id,
            'item_id' => $torch->id,
            'quantity' => 2,
        ]);
        $row = CharacterInventory::query()->where('character_id', $character->id)->where('item_id', $coin->id)->first();
        $this->assertNotNull($row);

        $this->actingAs($owner)->delete("/characters/{$character->id}/inventory/{$row->id}")->assertRedirect();
        $this->assertDatabaseMissing('character_inventory', ['id' => $row->id]);
        $this->assertDatabaseHas('character_inventory', ['item_id' => $torch->id]);
    }
}
