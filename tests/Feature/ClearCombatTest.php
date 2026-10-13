<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\CombatEvent;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearCombatTest extends TestCase
{
    use RefreshDatabase;

    public function test_clearing_combat_removes_the_fight_and_its_tokens(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 2,
        ]);
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'controller_user_id' => $gm->id,
            'team' => 'ally',
        ]);
        $encounter->update(['active_combatant_id' => $combatant->id]);
        SceneToken::create([
            'scene_id' => $scene->id,
            'combatant_id' => $combatant->id,
            'x' => 1,
            'y' => 1,
            'label' => 'G',
        ]);
        CombatEvent::create([
            'encounter_id' => $encounter->id,
            'actor_id' => $combatant->id,
            'event_type' => 'attack',
            'payload_json' => ['display' => '12'],
        ]);

        $this->actingAs($gm)->post("/encounters/{$encounter->id}/clear")->assertRedirect();

        $this->assertDatabaseMissing('encounters', ['id' => $encounter->id]);
        $this->assertDatabaseMissing('combatants', ['id' => $combatant->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $combatant->id]);
        $this->assertDatabaseMissing('combat_events', ['encounter_id' => $encounter->id]);
    }

    public function test_ending_a_turn_logs_whose_turn_is_next(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $sera = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $brute = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        $first = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $sera->id,
            'initiative_total' => 12,
            'team' => 'ally',
        ]);
        Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $brute->id,
            'initiative_total' => 4,
            'team' => 'enemy',
        ]);
        $encounter->update(['active_combatant_id' => $first->id]);

        $this->actingAs($gm)->post("/encounters/{$encounter->id}/end-turn")->assertRedirect();

        $event = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'turn')->first();
        $this->assertSame("It is Brute's turn. Round 1.", $event->payload_json['summary']);
    }

    public function test_a_player_can_end_their_own_turn(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $sera = Character::create(['user_id' => $player->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $brute = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        $seraCombatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $sera->id,
            'controller_user_id' => $player->id,
            'initiative_total' => 12,
            'team' => 'ally',
        ]);
        $bruteCombatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $brute->id,
            'controller_user_id' => $gm->id,
            'initiative_total' => 4,
            'team' => 'enemy',
        ]);
        $encounter->update(['active_combatant_id' => $seraCombatant->id]);

        $this->actingAs($other)->post("/encounters/{$encounter->id}/end-turn")->assertForbidden();
        $this->actingAs($player)->post("/encounters/{$encounter->id}/end-turn")->assertRedirect();
        $this->assertSame($bruteCombatant->id, $encounter->fresh()->active_combatant_id);
        $this->actingAs($player)->post("/encounters/{$encounter->id}/end-turn")->assertForbidden();
        $this->assertSame($bruteCombatant->id, $encounter->fresh()->active_combatant_id);
    }
}
