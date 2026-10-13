<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gm_can_delete_a_campaign_and_keep_its_characters(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        Encounter::create(['campaign_id' => $campaign->id, 'scene_id' => $scene->id, 'name' => 'Skirmish', 'status' => 'active']);
        $character = Character::create([
            'user_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'name' => 'Vash',
        ]);
        $template = CreatureTemplate::create(['name' => 'Direwolf', 'base_wounds' => 4]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Old Bruin',
            'current_wounds' => 4,
        ]);

        $this->actingAs($player)->delete("/campaigns/{$campaign->id}")->assertForbidden();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);

        $this->actingAs($gm)->delete("/campaigns/{$campaign->id}")->assertRedirect('/campaigns');
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseMissing('creature_instances', ['id' => $wolf->id]);
        $this->assertNull($character->fresh()->campaign_id);
    }
}
