<?php

namespace Tests\Feature;

use App\Models\CreatureAction;
use App\Models\CreatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NpcPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_npc_page_lists_attributes_skills_attacks_and_features(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $template = CreatureTemplate::create([
            'name' => 'Direwolf',
            'category' => 'companion',
            'description' => 'A sure-footed hunter.',
            'base_wounds' => 4,
            'armor_save_percent' => 0,
            'major_move_ft' => 30,
            'minor_move_ft' => 15,
            'stats_json' => ['attack_tier' => 10, 'endurance' => 10, 'reflexes' => 9, 'willpower' => 7, 'other' => 8, 'stamina' => 1, 'power' => 2],
            'traits_json' => [
                'skills' => [['name' => 'Survival', 'tip' => 'Survival checks to track by scent are Enhanced.']],
                'features' => [],
            ],
        ]);
        CreatureAction::create([
            'creature_template_id' => $template->id,
            'name' => 'Bite',
            'action_cost' => 'major',
            'attack_tier' => 10,
            'range_ft' => 5,
            'resolution_type' => 'attack',
            'effects_json' => ['damage' => '1 Physical', 'tip' => 'Its bite deals 1 Physical damage.'],
        ]);

        $this->actingAs($gm)->get('/npcs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('templates.0.attributes.0.name', 'Attack 10')
            ->where('templates.0.attributes.0.percent', 59)
            ->where('templates.0.skills.0.name', 'Survival')
            ->where('templates.0.attacks.0.name', 'Bite')
            ->where('templates.0.stamina', 1)
            ->where('templates.0.power', 2)
        );
    }

    public function test_an_npc_attack_roll_reports_the_result(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $template = CreatureTemplate::create([
            'name' => 'Direwolf',
            'category' => 'companion',
            'base_wounds' => 4,
            'stats_json' => ['attack_tier' => 10, 'other' => 8],
            'traits_json' => [
                'skills' => [['name' => 'Survival', 'tip' => 'Survival checks to track by scent are Enhanced.']],
            ],
        ]);
        CreatureAction::create([
            'creature_template_id' => $template->id,
            'name' => 'Bite',
            'action_cost' => 'major',
            'attack_tier' => 10,
            'range_ft' => 5,
            'resolution_type' => 'attack',
            'effects_json' => ['damage' => '1 Physical', 'tip' => 'Its bite deals 1 Physical damage.'],
        ]);

        $this->actingAs($gm)->post("/npcs/{$template->id}/rolls", [
            'type' => 'attack',
            'name' => 'Bite',
        ])->assertRedirect()->assertSessionHas('roll');

        $roll = session('roll');
        $this->assertSame($template->id, $roll['npc_id']);
        $this->assertSame('Bite', $roll['attack']);
        $this->assertArrayHasKey('success', $roll);
    }

    public function test_an_npc_endurance_check_can_be_rolled(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $template = CreatureTemplate::create([
            'name' => 'Bear',
            'category' => 'companion',
            'base_wounds' => 5,
            'stats_json' => ['endurance' => 11, 'other' => 8],
        ]);

        $this->actingAs($gm)->post("/npcs/{$template->id}/rolls", [
            'type' => 'check',
            'name' => 'Endurance',
            'circumstance' => 2,
        ])->assertRedirect()->assertSessionHas('roll');

        $this->assertSame('Endurance', session('roll')['skill']);
        $this->assertSame(77, session('roll')['percent']);
        $this->assertSame('Greater Enhancement', session('roll')['modifier']);
    }

    public function test_an_npc_sheet_stores_suffering_from_zero_to_two(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);
        $template = CreatureTemplate::create([
            'name' => 'Skeleton',
            'category' => 'npc',
            'base_wounds' => 2,
            'stats_json' => ['endurance' => 8],
        ]);

        $this->actingAs($gm)->get('/npcs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('templates', fn ($rows) => collect($rows)->firstWhere('name', 'Skeleton')['suffering'] === 0)
        );

        $this->actingAs($gm)->patch("/npcs/{$template->id}", ['suffering' => 2])->assertRedirect();
        $this->assertSame(2, $template->fresh()->stats_json['suffering']);
        $this->actingAs($gm)->patch("/npcs/{$template->id}", ['suffering' => 9])->assertSessionHasErrors('suffering');
    }

    public function test_a_new_creature_keeps_the_same_fields_as_the_tome(): void
    {
        $gm = User::factory()->create(['is_gm' => true]);

        $this->actingAs($gm)->post('/npcs', [
            'name' => 'Marsh Lurker',
            'category' => 'npc',
            'description' => 'It waits under the reeds.',
            'behavior' => 'Strikes the nearest creature, then withdraws into the water.',
            'base_wounds' => 4,
            'armor_save_percent' => 10,
            'armor_note' => 'slick hide',
            'major_move_ft' => 20,
            'minor_move_ft' => 10,
            'stamina' => 1,
            'power' => 0,
            'focus' => 0,
            'initiative' => 1,
            'melee' => 9,
            'ranged' => null,
            'attack_tier' => 9,
            'endurance' => 9,
            'reflexes' => 8,
            'willpower' => 6,
            'other' => 6,
            'skills' => [['name' => 'Stealth', 'tip' => 'Stealth checks in water are Enhanced.']],
            'features' => [['name' => 'Amphibious', 'tip' => 'It breathes water and air.']],
            'attacks' => [[
                'name' => 'Bite',
                'tier' => 9,
                'range_ft' => 5,
                'damage' => '1 Physical',
                'resolution' => 'attack',
                'cost' => 'major',
                'tip' => 'Its bite deals 1 Physical damage.',
                'heavy' => false,
                'two_handed' => false,
            ]],
        ])->assertRedirect();

        $template = CreatureTemplate::query()->where('name', 'Marsh Lurker')->first();
        $this->assertNotNull($template);
        $this->assertSame(4, $template->base_wounds);
        $this->assertSame(10, $template->armor_save_percent);
        $this->assertSame(9, $template->stats_json['melee']);
        $this->assertArrayNotHasKey('ranged', $template->stats_json);
        $this->assertSame('slick hide', $template->stats_json['armor_note']);
        $this->assertSame('Stealth', $template->traits_json['skills'][0]['name']);
        $this->assertSame('Amphibious', $template->traits_json['features'][0]['name']);
        $this->assertSame('Bite', $template->actions()->value('name'));
        $this->assertSame(9, $template->actions()->value('attack_tier'));
    }
}
