<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rules_page_renders_markdown_as_html(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/rules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('sections')
            ->has('entries')
            ->where('sections', function ($sections) {
                $playing = collect($sections)->firstWhere('id', '1-playing-the-game');
                $contents = collect($sections)->firstWhere('id', 'contents');

                return $playing
                    && $playing['title'] === '1. Playing the Game'
                    && str_contains($playing['html'], 'id="1-playing-the-game-the-core-check"')
                    && str_contains($playing['html'], '<table>')
                    && ! str_contains($playing['html'], '###')
                    && $contents
                    && str_contains($contents['html'], 'href="#1-playing-the-game"');
            })
            ->where('entries', function ($entries) {
                $core = collect($entries)->firstWhere('title', 'The Core Check');

                return $core && str_contains(strtolower($core['text']), 'percentile');
            })
        );
    }
}
