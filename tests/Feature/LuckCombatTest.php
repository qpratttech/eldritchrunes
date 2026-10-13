<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\CharacterResource;
use App\Models\CombatEvent;
use App\Models\Combatant;
use App\Models\Encounter;
use App\Models\Skill;
use App\Models\User;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LuckCombatTest extends TestCase
{
    use RefreshDatabase;

    public function test_luck_reverses_a_miss_into_a_hit_and_applies_the_wound(): void
    {
        [$player, $hero, $foe, $encounter, $foeCombatant] = $this->combat();

        $event = CombatEvent::create([
            'encounter_id' => $encounter->id,
            'target_id' => $foeCombatant->id,
            'event_type' => 'attack',
            'payload_json' => [
                'roll' => 90,
                'percent' => 35,
                'success' => false,
                'critical' => false,
                'damage' => 0,
                'damage_base' => 1,
                'heavy' => false,
                'can_crit' => true,
                'crit_range' => 3,
                'target_armor' => 0,
                'attacker' => 'Sera',
                'target' => 'Brute',
                'attack' => 'Sword',
            ],
        ]);

        $this->actingAs($player)->post("/characters/{$hero->id}/luck", [
            'event_id' => $event->id,
        ])->assertRedirect();

        $payload = $event->fresh()->payload_json;
        $this->assertSame(9, (int) $payload['roll']);
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['critical']);
        $this->assertSame(1, (int) $payload['damage']);
        $this->assertStringContainsString('Hit!', $payload['summary']);
        $this->assertStringContainsString('Luck reverses 90 to 9.', $payload['summary']);
        $this->assertSame(2, (int) CharacterResource::query()->where('character_id', $foe->id)->where('resource_key', 'wounds')->value('current_value'));
        $this->assertSame(0, (int) CharacterResource::query()->where('character_id', $hero->id)->where('resource_key', 'luck')->value('current_value'));

        $this->actingAs($player)->post("/characters/{$hero->id}/luck", [
            'event_id' => $event->id,
        ])->assertSessionHasErrors('luck');
    }

    public function test_luck_reverses_a_hit_into_a_miss_and_restores_the_wound(): void
    {
        [$player, $hero, $foe, $encounter, $foeCombatant] = $this->combat();
        CharacterResource::query()->where('character_id', $foe->id)->where('resource_key', 'wounds')->update(['current_value' => 2]);

        $event = CombatEvent::create([
            'encounter_id' => $encounter->id,
            'target_id' => $foeCombatant->id,
            'event_type' => 'attack',
            'payload_json' => [
                'roll' => 18,
                'percent' => 35,
                'success' => true,
                'critical' => false,
                'damage' => 1,
                'damage_base' => 1,
                'heavy' => false,
                'can_crit' => true,
                'crit_range' => 3,
                'target_armor' => 0,
                'attacker' => 'Sera',
                'target' => 'Brute',
                'attack' => 'Sword',
            ],
        ]);

        $this->actingAs($player)->post("/characters/{$hero->id}/luck", [
            'event_id' => $event->id,
        ])->assertRedirect();

        $payload = $event->fresh()->payload_json;
        $this->assertSame(81, (int) $payload['roll']);
        $this->assertFalse($payload['success']);
        $this->assertSame(0, (int) $payload['damage']);
        $this->assertStringContainsString('Miss!', $payload['summary']);
        $this->assertStringContainsString('Luck reverses 18 to 81.', $payload['summary']);
        $this->assertSame(3, (int) CharacterResource::query()->where('character_id', $foe->id)->where('resource_key', 'wounds')->value('current_value'));
    }

    public function test_a_roll_of_100_cannot_be_reversed(): void
    {
        [$player, $hero, , $encounter, $foeCombatant] = $this->combat();
        $event = CombatEvent::create([
            'encounter_id' => $encounter->id,
            'target_id' => $foeCombatant->id,
            'event_type' => 'attack',
            'payload_json' => [
                'roll' => 100,
                'percent' => 95,
                'success' => false,
                'damage' => 0,
                'damage_base' => 1,
                'attacker' => 'Sera',
                'target' => 'Brute',
                'attack' => 'Sword',
            ],
        ]);

        $this->actingAs($player)->post("/characters/{$hero->id}/luck", [
            'event_id' => $event->id,
        ])->assertSessionHasErrors('luck');
        $this->assertSame(1, (int) CharacterResource::query()->where('character_id', $hero->id)->where('resource_key', 'luck')->value('current_value'));
    }

    /**
     * @return array{0: User, 1: Character, 2: Character, 3: Encounter, 4: Combatant}
     */
    private function combat(): array
    {
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $player->id, 'name' => 'Ash Road']);
        $hero = Character::create(['user_id' => $player->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $foe = Character::create(['user_id' => $player->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        CharacterResource::create(['character_id' => $hero->id, 'resource_key' => 'luck', 'base_maximum' => 1, 'current_value' => 1]);
        CharacterResource::create(['character_id' => $foe->id, 'resource_key' => 'wounds', 'base_maximum' => 3, 'current_value' => 3]);
        $body = Attribute::create(['key' => 'body', 'name' => 'Body']);
        $endurance = Skill::create(['key' => 'endurance', 'name' => 'Endurance', 'attribute_id' => $body->id, 'is_attack_skill' => false]);
        CharacterAttribute::create(['character_id' => $foe->id, 'attribute_id' => $body->id, 'base_step' => 20]);
        $encounter = Encounter::create(['campaign_id' => $campaign->id, 'name' => 'Skirmish', 'status' => 'active', 'round_number' => 1]);
        Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $hero->id, 'controller_user_id' => $player->id, 'team' => 'ally']);
        $foeCombatant = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $foe->id, 'team' => 'enemy']);

        return [$player, $hero, $foe, $encounter, $foeCombatant];
    }
}
