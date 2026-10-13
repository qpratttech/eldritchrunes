<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\CharacterResource;
use App\Models\CharacterSkill;
use App\Models\Combatant;
use App\Models\CombatEvent;
use App\Models\Skill;
use App\Models\CreatureInstance;
use App\Models\CreatureTemplate;
use App\Models\Encounter;
use App\Models\Scene;
use App\Models\SceneToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignCharactersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gm_can_add_campaign_characters_to_combat(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player']);
        Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $character = Character::create(['user_id' => $player->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $template = CreatureTemplate::create(['name' => 'Wolf', 'category' => 'beast', 'base_wounds' => 3]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Grey',
            'current_wounds' => 3,
        ]);

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/combat", [
            'character_id' => $character->id,
        ])->assertForbidden();

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/combat", [
            'character_id' => $character->id,
        ])->assertRedirect();

        $encounter = Encounter::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($encounter);
        $combatant = Combatant::query()->where('encounter_id', $encounter->id)->where('character_id', $character->id)->first();
        $this->assertNotNull($combatant);
        $this->assertSame($player->id, $combatant->controller_user_id);
        $this->assertTrue(SceneToken::query()->where('combatant_id', $combatant->id)->exists());
        $this->assertNull($combatant->fresh()->initiative_total);
        $token = SceneToken::query()->where('combatant_id', $combatant->id)->first();
        $this->assertSame(40, $token->x);
        $this->assertSame(24, $token->y);

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/combat", [
            'character_id' => $character->id,
        ])->assertRedirect();
        $this->assertSame(1, Combatant::query()->where('character_id', $character->id)->count());

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/combat", [
            'creature_instance_id' => $wolf->id,
        ])->assertRedirect();
        $this->assertTrue(Combatant::query()->where('creature_instance_id', $wolf->id)->exists());
        $template->update(['stats_json' => ['stamina' => 2, 'power' => 1]]);
        $wolf->update(['overrides_json' => ['suffering' => 1, 'stamina' => 1]]);
        CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Shade',
            'current_wounds' => 3,
        ]);

        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.characters.0.name', 'Sera')
            ->where('roster.characters.0.can_open', true)
            ->where('roster.characters.0.in_combat', true)
            ->where('roster.creatures', fn ($rows) => collect($rows)->count() === 1 && $rows[0]['name'] === 'Grey' && $rows[0]['in_combat'] === true && $rows[0]['suffering'] === 1 && $rows[0]['stamina'] === 1 && $rows[0]['stamina_max'] === 2 && $rows[0]['power'] === 1 && $rows[0]['power_max'] === 1)
        );
        $this->actingAs($gm)->patch("/npc-instances/{$wolf->id}", [
            'name' => 'Grey',
            'current_wounds' => 3,
            'stamina' => 0,
            'power' => 5,
        ])->assertRedirect();
        $this->assertSame(0, $wolf->fresh()->overrides_json['stamina']);
        $this->assertSame(1, $wolf->fresh()->overrides_json['power']);
        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.creatures', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Grey', 'Shade'])
        );
        $this->actingAs($gm)->get("/characters/{$character->id}")->assertOk();
        $this->actingAs($player)->get("/characters/{$character->id}")->assertOk();

        $body = Attribute::create(['key' => 'body', 'name' => 'Body']);
        $endurance = Skill::create(['key' => 'endurance', 'name' => 'Endurance', 'attribute_id' => $body->id]);
        CharacterAttribute::create(['character_id' => $character->id, 'attribute_id' => $body->id, 'base_step' => 8]);
        CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $endurance->id, 'base_rank' => 2]);

        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roster.characters.0.attacks', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Unarmed'))
            ->where('roster.characters.0.saves', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Endurance' && $row['tier'] === 10))
        );

        $this->actingAs($player)->post("/characters/{$character->id}/rolls", [
            'skill_id' => $endurance->id,
            'circumstance' => 0,
        ])->assertRedirect()->assertSessionHas('roll', fn ($roll) => $roll['character_id'] === $character->id && $roll['skill'] === 'Endurance' && filled($roll['summary']));

        $this->assertTrue(CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'check')->exists());
    }

    public function test_the_combat_tracker_lists_combatants_in_initiative_order(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $slow = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Slow']);
        $fast = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Fast']);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $slow->id,
            'initiative_total' => 3,
            'team' => 'ally',
        ]);
        $leader = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $fast->id,
            'initiative_total' => 12,
            'team' => 'ally',
        ]);
        $encounter->update(['active_combatant_id' => $leader->id]);

        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('encounter.combatants.0.name', 'Fast')
            ->where('encounter.combatants.1.name', 'Slow')
            ->where('encounter.active_combatant_id', $leader->id)
        );
    }

    public function test_the_gm_can_remove_a_character_or_npc_from_combat(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $fast = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Fast']);
        $slow = Character::create(['user_id' => $player->id, 'campaign_id' => $campaign->id, 'name' => 'Slow']);
        $template = CreatureTemplate::create(['name' => 'Wolf', 'category' => 'beast', 'base_wounds' => 3]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Grey',
            'current_wounds' => 3,
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $campaign->scenes()->value('id'),
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        $leader = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $fast->id,
            'initiative_total' => 12,
            'team' => 'ally',
        ]);
        $trailer = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $slow->id,
            'initiative_total' => 3,
            'team' => 'ally',
        ]);
        $beast = Combatant::create([
            'encounter_id' => $encounter->id,
            'creature_instance_id' => $wolf->id,
            'initiative_total' => 8,
            'team' => 'enemy',
        ]);
        $encounter->update(['active_combatant_id' => $leader->id]);
        $sceneId = $encounter->scene_id;
        SceneToken::create(['scene_id' => $sceneId, 'combatant_id' => $leader->id, 'x' => 1, 'y' => 1, 'label' => 'F']);
        SceneToken::create(['scene_id' => $sceneId, 'combatant_id' => $beast->id, 'x' => 2, 'y' => 1, 'label' => 'G']);

        $this->actingAs($player)->delete("/campaigns/{$campaign->id}/combat", [
            'character_id' => $fast->id,
        ])->assertForbidden();

        $this->actingAs($gm)->delete("/campaigns/{$campaign->id}/combat", [
            'character_id' => $fast->id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('combatants', ['id' => $leader->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $leader->id]);
        $encounter->refresh();
        $this->assertSame($beast->id, $encounter->active_combatant_id);
        $this->assertTrue(CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'turn')->where('payload_json->summary', "It is Grey's turn. Round 1.")->exists());

        $this->actingAs($gm)->delete("/campaigns/{$campaign->id}/combat", [
            'creature_instance_id' => $wolf->id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('combatants', ['id' => $beast->id]);
        $this->assertDatabaseMissing('scene_tokens', ['combatant_id' => $beast->id]);
        $this->assertTrue(Combatant::query()->where('id', $trailer->id)->exists());
        $encounter->refresh();
        $this->assertSame($trailer->id, $encounter->active_combatant_id);
    }

    public function test_a_combatant_remembers_the_last_target_they_attacked(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        $sera = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $brute = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        $template = CreatureTemplate::create(['name' => 'Wolf', 'category' => 'beast', 'base_wounds' => 3]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Grey',
            'current_wounds' => 3,
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);
        $seraCombatant = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $sera->id, 'team' => 'ally', 'initiative_total' => 12]);
        $bruteCombatant = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $brute->id, 'team' => 'ally', 'initiative_total' => 8]);
        $wolfCombatant = Combatant::create(['encounter_id' => $encounter->id, 'creature_instance_id' => $wolf->id, 'team' => 'enemy', 'initiative_total' => 4]);
        $encounter->update(['active_combatant_id' => $seraCombatant->id]);
        CombatEvent::create([
            'encounter_id' => $encounter->id,
            'actor_id' => $seraCombatant->id,
            'target_id' => $bruteCombatant->id,
            'event_type' => 'attack',
            'payload_json' => ['target_type' => 'character', 'target_ref' => $brute->id],
        ]);
        CombatEvent::create([
            'encounter_id' => $encounter->id,
            'actor_id' => $seraCombatant->id,
            'target_id' => $wolfCombatant->id,
            'event_type' => 'attack',
            'payload_json' => ['target_type' => 'creature', 'target_ref' => $wolf->id],
        ]);
        CombatEvent::create([
            'encounter_id' => $encounter->id,
            'actor_id' => $wolfCombatant->id,
            'target_id' => $seraCombatant->id,
            'event_type' => 'attack',
            'payload_json' => [],
        ]);

        $this->actingAs($gm)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('encounter.combatants', function ($rows) use ($seraCombatant, $bruteCombatant, $wolfCombatant, $wolf, $sera) {
                $rows = collect($rows)->keyBy('id');

                return $rows[$seraCombatant->id]['last_target'] === 'creature:'.$wolf->id
                    && $rows[$bruteCombatant->id]['last_target'] === null
                    && $rows[$wolfCombatant->id]['last_target'] === 'character:'.$sera->id;
            })
        );
    }

    public function test_campaign_cards_log_manual_percentile_and_dice_rolls(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        CampaignMember::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player']);
        $sera = Character::create(['user_id' => $player->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $other = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        $template = CreatureTemplate::create(['name' => 'Wolf', 'category' => 'beast', 'base_wounds' => 3]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Grey',
            'current_wounds' => 3,
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 1,
        ]);

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/dice", [
            'character_id' => $sera->id,
            'die' => 'percentile',
        ])->assertRedirect()->assertSessionHas('roll', function ($roll) {
            return $roll['die'] === 'percentile'
                && $roll['roll'] >= 1
                && $roll['roll'] <= 100
                && $roll['summary'] === 'Sera rolls percentile. Rolled '.$roll['display'].'.';
        });
        $percentile = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'dice')->first();
        $this->assertNotNull($percentile);
        $this->assertSame('Sera rolls percentile. Rolled '.$percentile->payload_json['display'].'.', $percentile->payload_json['summary']);
        $this->assertArrayNotHasKey('percent', $percentile->payload_json);

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/dice", [
            'character_id' => $sera->id,
            'die' => '2d6',
        ])->assertRedirect()->assertSessionHas('roll', function ($roll) {
            return $roll['die'] === '2d6'
                && count($roll['faces']) === 2
                && $roll['roll'] === array_sum($roll['faces'])
                && $roll['roll'] >= 2
                && $roll['roll'] <= 12
                && str_contains($roll['summary'], 'Sera rolls 2d6.');
        });

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/dice", [
            'creature_instance_id' => $wolf->id,
            'die' => '1d6',
        ])->assertRedirect()->assertSessionHas('roll', function ($roll) use ($wolf) {
            return $roll['die'] === '1d6'
                && $roll['npc_id'] === $wolf->id
                && $roll['roll'] >= 1
                && $roll['roll'] <= 6
                && $roll['summary'] === 'Grey rolls 1d6. Rolled '.$roll['roll'].'.';
        });

        $this->actingAs($player)->post("/campaigns/{$campaign->id}/dice", [
            'character_id' => $other->id,
            'die' => '1d6',
        ])->assertForbidden();
        $this->actingAs($player)->post("/campaigns/{$campaign->id}/dice", [
            'creature_instance_id' => $wolf->id,
            'die' => 'percentile',
        ])->assertForbidden();

        $logged = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'dice')->pluck('payload_json');
        $this->assertCount(3, $logged);
        $this->assertTrue($logged->contains(fn ($payload) => str_starts_with($payload['summary'], 'Sera rolls 2d6.')));
        $this->assertTrue($logged->contains(fn ($payload) => str_starts_with($payload['summary'], 'Grey rolls 1d6.')));
    }

    public function test_joining_an_active_combat_rolls_initiative_into_the_log(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Ash Road']);
        Scene::create(['campaign_id' => $campaign->id, 'name' => 'Watchfire']);
        $leader = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        $sera = Character::create(['user_id' => $gm->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        CharacterResource::create([
            'character_id' => $sera->id,
            'resource_key' => 'initiative_bonus',
            'base_maximum' => 2,
            'current_value' => 2,
        ]);
        $template = CreatureTemplate::create([
            'name' => 'Wolf',
            'category' => 'beast',
            'base_wounds' => 3,
            'stats_json' => ['initiative' => 1],
        ]);
        $wolf = CreatureInstance::create([
            'creature_template_id' => $template->id,
            'campaign_id' => $campaign->id,
            'name' => 'Grey',
            'current_wounds' => 3,
        ]);
        $encounter = Encounter::create([
            'campaign_id' => $campaign->id,
            'name' => 'Skirmish',
            'status' => 'active',
            'round_number' => 2,
        ]);
        $leading = Combatant::create([
            'encounter_id' => $encounter->id,
            'character_id' => $leader->id,
            'team' => 'ally',
            'initiative_total' => 30,
            'initiative_tiebreak' => 6,
        ]);
        $encounter->update(['active_combatant_id' => $leading->id]);

        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/combat", [
            'character_id' => $sera->id,
        ])->assertRedirect();
        $this->actingAs($gm)->post("/campaigns/{$campaign->id}/combat", [
            'creature_instance_id' => $wolf->id,
        ])->assertRedirect();

        $joined = Combatant::query()->where('character_id', $sera->id)->first();
        $beast = Combatant::query()->where('creature_instance_id', $wolf->id)->first();
        $this->assertGreaterThanOrEqual(2, $joined->initiative_roll);
        $this->assertLessThanOrEqual(12, $joined->initiative_roll);
        $this->assertSame($joined->initiative_roll + 2, $joined->initiative_total);
        $this->assertSame($beast->initiative_roll + 1, $beast->initiative_total);
        $this->assertSame($leading->id, $encounter->fresh()->active_combatant_id);
        $this->assertSame(2, $encounter->fresh()->round_number);

        $events = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'initiative')->get();
        $this->assertCount(2, $events);
        $seraLog = $events->first(fn ($event) => $event->actor_id === $joined->id);
        $this->assertSame(
            'Sera rolls initiative. Rolled '.$seraLog->payload_json['dice'][0].' and '.$seraLog->payload_json['dice'][1].'. Modifier +2. Initiative '.$joined->initiative_total.'.',
            $seraLog->payload_json['summary']
        );
        $wolfLog = $events->first(fn ($event) => $event->actor_id === $beast->id);
        $this->assertStringContainsString('Grey rolls initiative. Rolled '.$wolfLog->payload_json['dice'][0].' and '.$wolfLog->payload_json['dice'][1].'.', $wolfLog->payload_json['summary']);
        $this->assertStringContainsString('Modifier +1.', $wolfLog->payload_json['summary']);

        app(\App\Domain\Combat\CombatService::class)->rollInitiative($encounter->fresh());
        $session = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'initiative')->get();
        $this->assertCount(5, $session);
        $this->assertTrue($session->every(fn ($event) => str_contains($event->payload_json['summary'], 'rolls initiative.') && str_contains($event->payload_json['summary'], 'Modifier ')));
    }
}
