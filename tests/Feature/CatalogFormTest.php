<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_designing_an_item_requires_its_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/items', [
            'name' => 'Blank',
        ])->assertSessionHasErrors(['description', 'category', 'cost_silver', 'weight']);
        $this->assertDatabaseMissing('items', ['name' => 'Blank']);

        $this->actingAs($user)->post('/items', [
            'name' => 'Pebble',
            'description' => 'A smooth stone.',
            'category' => 'gear',
            'cost_silver' => 0,
            'weight' => 0,
        ])->assertRedirect();
        $this->assertNotNull(Item::query()->where('name', 'Pebble')->first());
    }

    public function test_designing_a_spell_requires_its_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/spells', [
            'name' => 'Blink',
        ])->assertSessionHasErrors(['order', 'tier', 'rules_text', 'casting_time', 'range_text', 'duration_text']);
    }
}
