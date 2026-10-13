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
}
