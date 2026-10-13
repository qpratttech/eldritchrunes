<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\CharacterResource;
use App\Models\Encounter;
use App\Models\CreatureAction;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignNpcTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_company_lists_an_npc_and_an_attack_reduces_wounds(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $template = CreatureTemplate::create([
            'name' => 'Direwolf',
            'category' => 'companion',
            'base_wounds' => 4,
            'armor_save_percent' => 0,
            'stats_json' => ['attack_tier' => 18],
        ]);
        CreatureAction::create([
            'creature_template_id' => $template->id,
            'name' => 'Bite',
            'action_cost' => 'major',
            'attack_tier' => 18,
            'range_ft' => 5,
            'resolution_type' => 'attack',
            'effects_json' => ['damage' => '1 Physical'],
        ]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Old Bruin',
            'current_wounds' => 4,
        ]);
        $hero = Character::create([
            'user_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'name' => 'Vash',
        ]);
        CharacterResource::create([
            'character_id' => $hero->id,
            'resource_key' => 'wounds',
            'base_maximum' => 5,
            'current_value' => 5,
        ]);
        Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);

        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.creatures.0.name', 'Old Bruin')
            ->where('roster.creatures.0.wounds', 4)
            ->where('roster.characters.0.wounds', 5)
        );

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/attacks", [
            'creature_instance_id' => $wolf->id,
            'attack' => 'Bite',
            'target_type' => 'character',
            'target_id' => $hero->id,
        ])->assertRedirect()->assertSessionHas('roll');

        $this->assertSame(4, CharacterResource::query()->where('character_id', $hero->id)->where('resource_key', 'wounds')->value('current_value'));
        $this->assertDatabaseHas('combat_events', [
            'event_type' => 'attack',
        ]);
    }

    public function test_the_gm_can_remove_an_npc_from_the_campaign(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $template = CreatureTemplate::create(['name' => 'Direwolf', 'base_wounds' => 4]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Old Bruin',
            'current_wounds' => 4,
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'name' => 'Skirmish',
            'status' => 'active',
        ]);
        $combatant = Combatant::create([
            'encounter_id' => $encounter->id,
            'creature_instance_id' => $wolf->id,
            'team' => 'enemy',
        ]);
        $encounter->update(['active_combatant_id' => $combatant->id]);
        SceneToken::create(['scene_id' => $scene->id, 'combatant_id' => $combatant->id, 'x' => 1, 'y' => 1, 'label' => 'O']);

        $this->actingAs($player)->delete("/npc-instances/{$wolf->id}")->assertForbidden();
        $this->assertDatabaseHas('creature_instances', ['id' => $wolf->id]);

        $this->actingAs($gm)->delete("/npc-instances/{$wolf->id}")->assertRedirect();
        $this->assertDatabaseMissing('creature_instances', ['id' => $wolf->id]);
        $this->assertDatabaseMissing('combatants', ['id' => $combatant->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $combatant->id]);
        $this->assertNull($encounter->fresh()->active_combatant_id);
    }
}
