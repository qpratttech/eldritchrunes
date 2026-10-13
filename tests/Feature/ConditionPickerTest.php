<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\CombatantCondition;
use App\Models\ConditionDefinition;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_character_and_npc_can_gain_and_lose_a_condition(): void
    {
        $owner = User::factory()->create();
        $gm = User::factory()->create(['is_gm' => true]);
        $character = Character::create(['user_id' => $owner->id, 'name' => 'Sera']);
        $bleeding = ConditionDefinition::create(['key' => 'bleeding', 'name' => 'Bleeding', 'description' => 'Lose a wound at the start of your turn.']);
        $suffering = ConditionDefinition::create(['key' => 'suffering', 'name' => 'Suffering', 'description' => 'Pain.']);

        $this->actingAs($owner)->post("/characters/{$character->id}/conditions", [
            'condition_id' => $bleeding->id,
        ])->assertRedirect();
        $row = CombatantCondition::query()->where('character_id', $character->id)->where('condition_id', $bleeding->id)->first();
        $this->assertNotNull($row);
        $this->actingAs($owner)->post("/characters/{$character->id}/conditions", [
            'condition_id' => $suffering->id,
        ])->assertNotFound();
        $this->actingAs($owner)->delete("/characters/{$character->id}/conditions/{$row->id}")->assertRedirect();
        $this->assertDatabaseMissing('combatant_conditions', ['id' => $row->id]);

        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $template = CreatureTemplate::create(['name' => 'Wolf', 'category' => 'beast', 'base_wounds' => 3]);
        $wolf = CreatureInstance::create(['creature_template_id' => $template->id, 'campaign_id' => $campaign->id, 'name' => 'Grey', 'current_wounds' => 3]);
        $this->actingAs($gm)->post("/npc-instances/{$wolf->id}/conditions", [
            'condition_id' => $bleeding->id,
        ])->assertRedirect();
        $npc = CombatantCondition::query()->where('creature_instance_id', $wolf->id)->first();
        $this->assertSame($bleeding->id, $npc->condition_id);
        $this->actingAs($gm)->delete("/npc-instances/{$wolf->id}/conditions/{$npc->id}")->assertRedirect();
        $this->assertDatabaseMissing('combatant_conditions', ['id' => $npc->id]);
    }
}
