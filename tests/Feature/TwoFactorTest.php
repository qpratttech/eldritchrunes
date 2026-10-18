<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_without_two_factor_signs_in_with_a_password(): void
    {
        $player = User::factory()->create(['email' => 'sera@example.com']);

        $this->post('/login', [
            'email' => 'sera@example.com',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($player);
    }

    public function test_confirmed_two_factor_authentication_is_required_at_sign_in(): void
    {
        $player = User::factory()->create(['email' => 'sera@example.com']);
        app(EnableTwoFactorAuthentication::class)($player);
        $player->refresh();
        $secret = Fortify::currentEncrypter()->decrypt($player->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($player, $code);
        $recovery = $player->fresh()->recoveryCodes()[0];

        $this->actingAs($player)->get('/player')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.enabled', true)
            ->where('twoFactor.confirming', false)
            ->where('twoFactor.recoveryCodes', fn ($codes) => collect($codes)->contains($recovery))
            ->where('passkeys', [])
        );

        $this->post('/logout');
        auth()->logout();

        $this->post('/login', [
            'email' => 'sera@example.com',
            'password' => 'password',
        ])->assertRedirect('/two-factor-challenge');

        $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorChallenge', false)
        );

        $this->post('/two-factor-challenge', [
            'code' => '000000',
        ])->assertRedirect('/two-factor-challenge')->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', [
            'recovery_code' => $recovery,
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($player);
        $this->assertNotContains($recovery, $player->fresh()->recoveryCodes());
    }

    public function test_a_player_confirms_two_factor_authentication_from_the_player_page(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)->post('/user/two-factor-authentication')->assertRedirect();
        $player->refresh();
        $this->assertNotNull($player->two_factor_secret);
        $this->assertNull($player->two_factor_confirmed_at);

        $this->actingAs($player)->get('/player')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.confirming', true)
            ->where('twoFactor.qr', fn ($svg) => is_string($svg) && str_contains($svg, '<svg'))
            ->where('twoFactor.secret', fn ($secret) => filled($secret))
        );

        $secret = Fortify::currentEncrypter()->decrypt($player->two_factor_secret);
        $this->actingAs($player)->post('/user/confirmed-two-factor-authentication', [
            'code' => '000000',
        ])->assertSessionHasErrors(['code'], null, 'confirmTwoFactorAuthentication');

        $this->actingAs($player)->post('/user/confirmed-two-factor-authentication', [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertRedirect();
        $this->assertNotNull($player->fresh()->two_factor_confirmed_at);

        $this->actingAs($player)->delete('/user/two-factor-authentication')->assertRedirect();
        $this->assertNull($player->fresh()->two_factor_secret);
        $this->assertNull($player->fresh()->two_factor_confirmed_at);
    }

    public function test_passkey_options_are_available_for_sign_in_and_registration(): void
    {
        $player = User::factory()->create();

        $this->getJson('/passkeys/login/options')->assertOk()->assertJsonStructure(['options']);
        $this->get('/user/passkeys/options')->assertRedirect('/login');
        $this->actingAs($player)->getJson('/user/passkeys/options')->assertOk()->assertJsonStructure(['options']);
    }
}
