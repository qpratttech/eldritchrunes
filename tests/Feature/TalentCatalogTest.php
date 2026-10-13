<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TalentCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_talents_and_active_tactics_follow_the_catalog(): void
    {
        $this->seed(RulesetSeeder::class);

        $this->assertSame(29, Feature::query()->where('category', 'talent')->count());
        $this->assertSame(22, Feature::query()->where('category', 'tactic')->where('activation_type', 'active')->count());

        $veteran = Feature::query()->where('name', 'Veteran')->firstOrFail();
        $this->assertSame(['Combat Training'], $veteran->prerequisites_json);
        $this->assertStringStartsWith('Requirements: Combat Training.', $veteran->rules_text);

        $brace = Feature::query()->where('name', 'Brace')->firstOrFail();
        $this->assertSame('active', $brace->activation_type);
        $this->assertStringContainsString('Brace Save', $brace->rules_text);
        $this->assertLessThan(strpos($brace->rules_text, 'Brace Save'), strpos($brace->rules_text, 'Cost:'));
        $this->assertLessThan(strpos($brace->rules_text, 'Brace Save'), strpos($brace->rules_text, 'Timing:'));
        $this->assertSame('1 Stamina and 1 minor action', $brace->effects_json['cost']);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/talents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Talents and Tactics')
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Moonsight' && $row['detail'] === 'Talent' && $row['requirements'] === 'None' && $row['flavor'] === 'You have learned to trust the faintest light.')
                && collect($rows)->contains(fn ($row) => $row['name'] === 'Brace' && $row['detail'] === 'Tactic' && $row['requirements'] === 'Veteran and a wielded shield' && $row['cost'] === '1 Stamina and 1 minor action' && $row['timing'] === 'On your turn.' && str_contains($row['description'], 'Brace Save') && ! str_contains($row['description'], 'Requirements:') && ! str_contains($row['description'], 'Cost:')))
        );
        $this->actingAs($user)->get('/features')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->doesntContain(fn ($row) => $row['name'] === 'Brace' || $row['name'] === 'Moonsight'))
        );
    }
}
