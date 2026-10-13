<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ProductionErrorPageTest extends TestCase
{
    public function test_a_live_server_error_hides_the_exception_behind_the_logo(): void
    {
        config([
            'app.debug' => false,
            'filesystems.disks.s3.url' => 'https://assets.example.test',
        ]);
        Route::get('/testing-boom', function () {
            throw new RuntimeException('the database password is visible');
        });

        $this->get('/testing-boom')
            ->assertStatus(500)
            ->assertSee('Something has gone wrong.')
            ->assertSee('https://assets.example.test/branding/eldritch-runes-crest.jpg')
            ->assertDontSee('the database password is visible')
            ->assertDontSee('RuntimeException');
    }

    public function test_development_still_shows_the_laravel_error(): void
    {
        config(['app.debug' => true]);
        Route::get('/testing-boom', function () {
            throw new RuntimeException('the database password is visible');
        });

        $this->get('/testing-boom')
            ->assertStatus(500)
            ->assertSee('the database password is visible');
    }
}
