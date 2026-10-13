<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_character_takes_them_out_of_a_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $owner->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $character = Character::create([
            'user_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'name' => 'Sera',
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $character->id,
            'team' => 'ally',
            'initiative_total' => 10,
        ]);
        $encounter->update(['active_combatant_id' => $combatant->id]);
        SceneToken::create([
            'scene_id' => $scene->id,
            'combatant_id' => $combatant->id,
            'x' => 4,
            'y' => 4,
            'label' => 'S',
        ]);

        $this->actingAs($other)->delete("/characters/{$character->id}")->assertForbidden();
        $this->assertDatabaseHas('characters', ['id' => $character->id]);

        $this->actingAs($owner)->delete("/characters/{$character->id}")->assertRedirect('/characters');
        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
        $this->assertDatabaseMissing('combatants', ['id' => $combatant->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $combatant->id]);
        $this->assertNull($encounter->fresh()->active_combatant_id);
    }
}
