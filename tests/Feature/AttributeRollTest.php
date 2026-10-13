<?php

namespace Tests\Feature;

use App\Domain\Characters\CharacterPresenter;
use App\Models\Attribute;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\CombatEvent;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeRollTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_attribute_roll_is_written_to_the_combat_log(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $user->id, 'name' => 'Ash Road']);
        $character = Character::create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'name' => 'Vash',
        ]);
        $attribute = Attribute::create(['key' => 'strength', 'name' => 'Strength']);
        CharacterAttribute::create([
            'character_id' => $character->id,
            'attribute_id' => $attribute->id,
            'base_step' => 8,
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
        ]);

        $strength = collect(app(CharacterPresenter::class)->present($character)['attributes'])->firstWhere('key', 'strength');
        $this->assertSame(8, $strength['tier']);
        $this->assertSame(47, $strength['percent']);

        $this->actingAs($user)->post("/characters/{$character->id}/rolls", [
            'attribute' => 'strength',
        ])->assertRedirect()->assertSessionHas('roll.attribute', 'Strength');

        $event = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'check')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('Vash checks Strength.', $event->payload_json['summary']);
        $this->assertStringContainsString('47% chance.', $event->payload_json['summary']);
    }
}
