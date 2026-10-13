<?php

namespace Tests\Feature;

use App\Domain\Combat\CombatService;
use App\Models\Attribute;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\CharacterResource;
use App\Models\CharacterSkill;
use App\Models\CombatEvent;
use App\Models\Combatant;
use App\Models\Encounter;
use App\Models\MagicOrder;
use App\Models\Skill;
use App\Models\Spell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpellSaveAttackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_spell_that_forces_a_save_is_logged_as_an_attack(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $user->id, 'name' => 'Ash Road']);
        $caster = Character::create(['user_id' => $user->id, 'campaign_id' => $campaign->id, 'name' => 'Sera']);
        $target = Character::create(['user_id' => $user->id, 'campaign_id' => $campaign->id, 'name' => 'Brute']);
        CharacterResource::create(['character_id' => $caster->id, 'resource_key' => 'major_action', 'base_maximum' => 1, 'current_value' => 1]);
        CharacterResource::create(['character_id' => $caster->id, 'resource_key' => 'minor_action', 'base_maximum' => 2, 'current_value' => 2]);
        CharacterResource::create(['character_id' => $caster->id, 'resource_key' => 'wounds', 'base_maximum' => 3, 'current_value' => 3]);
        CharacterResource::create(['character_id' => $target->id, 'resource_key' => 'wounds', 'base_maximum' => 3, 'current_value' => 3]);
        $resolve = Attribute::create(['key' => 'resolve', 'name' => 'Resolve']);
        $willpower = Skill::create(['key' => 'willpower', 'name' => 'Willpower', 'attribute_id' => $resolve->id, 'is_attack_skill' => false]);
        CharacterAttribute::create(['character_id' => $target->id, 'attribute_id' => $resolve->id, 'base_step' => 6]);
        CharacterSkill::create(['character_id' => $target->id, 'skill_id' => $willpower->id, 'base_rank' => 0]);
        $order = MagicOrder::create(['name' => 'Circle', 'power_source' => 'Dazgolmic', 'casting_skill_id' => $willpower->id]);
        $spell = Spell::create([
            'name' => 'Slumber of Toron',
            'order_id' => $order->id,
            'tier' => 1,
            'rules_text' => 'Willpower save or fall Prone and Unconscious.',
            'casting_time' => 'Major action',
        ]);
        $encounter = Encounter::create(['campaign_id' => $campaign->id, 'name' => 'Skirmish', 'status' => 'active', 'round_number' => 1]);
        Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $caster->id, 'team' => 'ally']);
        $defender = Combatant::create(['encounter_id' => $encounter->id, 'character_id' => $target->id, 'team' => 'enemy']);

        $result = app(CombatService::class)->sheetAttack($caster, 'spell', null, $spell->id, false, null, null, $target, null, 0);

        $this->assertStringContainsString('Brute makes a Willpower save.', $result['summary']);
        $this->assertTrue(str_contains($result['summary'], 'Success.') || str_contains($result['summary'], 'Failure.'));
        $event = CombatEvent::query()->where('encounter_id', $encounter->id)->where('event_type', 'attack')->first();
        $this->assertNotNull($event);
        $this->assertSame($defender->id, $event->target_id);
        $this->assertSame($result['summary'], $event->payload_json['summary']);
    }
}
