<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RulesetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogFeatureListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RulesetSeeder::class);
    }

    public function test_backgrounds_and_classes_list_features_with_their_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/backgrounds')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', function ($rows) {
                $acolyte = collect($rows)->firstWhere('name', 'Acolyte');

                $bonuses = collect($acolyte['bonuses'] ?? []);

                return $acolyte
                    && $bonuses->contains(fn ($bonus) => $bonus['name'] === 'Intellect +1' && str_contains($bonus['rules'], '1 step'))
                    && $bonuses->contains(fn ($bonus) => $bonus['name'] === 'Faith +1' && str_contains($bonus['rules'], 'Faith'))
                    && collect($acolyte['features'])->contains(fn ($feature) => $feature['name'] === 'Magus' && str_contains($feature['rules'], 'Soul'));
            })
        );

        $this->actingAs($user)->get('/classes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows', function ($rows) {
                $adept = collect($rows)->firstWhere('name', 'Adept');
                $first = collect($adept['features'] ?? [])->firstWhere('level', 1);
                $unarmed = collect($adept['bonuses'] ?? [])->firstWhere('name', 'Unarmed +2');

                $heretic = collect($rows)->firstWhere('name', 'Heretic');

                return $first && $first['name'] === 'Esoteric Way' && filled($first['rules'])
                    && $unarmed && str_contains($unarmed['rules'], '2 ranks')
                    && ($heretic['detail'] ?? null) === 'Spellcaster'
                    && str_contains($heretic['detail_rules'] ?? '', 'Sorcerous Potential')
                    && str_contains($heretic['detail_rules'] ?? '', 'Spellcaster Advancement')
                    && collect($heretic['features'])->contains(fn ($feature) => $feature['name'] === 'Spellcaster Advancement' && str_contains($feature['rules'], 'Soul slots'));
            })
        );
    }
}
