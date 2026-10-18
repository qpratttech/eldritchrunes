<?php

namespace Tests\Feature;

use App\Console\Commands\Admin;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class AdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('COLUMNS=200');
        $_ENV['COLUMNS'] = '200';
        $_SERVER['COLUMNS'] = '200';
    }

    public function test_the_admin_menu_lists_users_with_creation_and_activity(): void
    {
        $created = Carbon::parse('2026-03-01 09:30:00');
        $seen = Carbon::parse('2026-03-02 14:05:00');
        $sera = User::factory()->create([
            'name' => 'Sera',
            'email' => 'sera@example.com',
            'is_gm' => true,
            'created_at' => $created,
        ]);
        User::factory()->create(['name' => 'Warden', 'email' => 'warden@example.com'])->delete();
        DB::table('sessions')->insert([
            'id' => 'sera-session',
            'user_id' => $sera->id,
            'payload' => '',
            'last_activity' => $seen->getTimestamp(),
        ]);

        $this->artisan('admin')
            ->expectsChoice('Admin', 'users', [
                'users' => 'List all users',
                'suspend' => 'Suspend or restore a user',
                'stats' => 'Site statistics',
                'exit' => 'Exit',
            ])
            ->expectsChoice('Admin', 'exit', [
                'users' => 'List all users',
                'suspend' => 'Suspend or restore a user',
                'stats' => 'Site statistics',
                'exit' => 'Exit',
            ])
            ->expectsOutputToContain('Sera')
            ->assertSuccessful();

        $rows = collect($this->rows('userRows'))->keyBy(fn (array $row) => $row[0]);
        $this->assertSame([
            'Sera',
            'sera@example.com',
            'Game master',
            'Active',
            $created->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            $seen->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ], $rows['Sera']);
        $this->assertSame('Suspended', $rows['Warden'][3]);
        $this->assertSame('Never', $rows['Warden'][5]);
    }

    public function test_the_admin_menu_can_suspend_and_restore_a_user(): void
    {
        $sera = User::factory()->create(['name' => 'Sera', 'email' => 'sera@example.com']);
        DB::table('sessions')->insert([
            'id' => 'sera-session',
            'user_id' => $sera->id,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->artisan('admin')
            ->expectsChoice('Admin', 'suspend', $this->menu())
            ->expectsSearch('User', (string) $sera->id, 'Sera', [
                $sera->id => 'Sera · sera@example.com',
            ])
            ->expectsConfirmation('Suspend Sera? They will be signed out.', 'yes')
            ->expectsChoice('Admin', 'exit', $this->menu())
            ->expectsOutputToContain('Sera is suspended.')
            ->assertSuccessful();

        $this->assertSoftDeleted($sera);
        $this->assertDatabaseMissing('sessions', ['user_id' => $sera->id]);
        $this->post('/login', [
            'email' => 'sera@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->artisan('admin')
            ->expectsChoice('Admin', 'suspend', $this->menu())
            ->expectsSearch('User', (string) $sera->id, 'Sera', [
                $sera->id => 'Sera · sera@example.com · suspended',
            ])
            ->expectsConfirmation('Restore Sera?', 'yes')
            ->expectsChoice('Admin', 'exit', $this->menu())
            ->expectsOutputToContain('Sera can sign in again.')
            ->assertSuccessful();

        $this->assertNotSoftDeleted($sera);
        $this->post('/login', [
            'email' => 'sera@example.com',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_the_admin_menu_shows_site_statistics(): void
    {
        User::factory()->create();
        User::factory()->create()->delete();
        Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Sera']);
        Campaign::create(['owner_id' => User::factory()->create(['is_gm' => true])->id, 'name' => 'Ash Road', 'is_public' => true]);
        Campaign::create(['owner_id' => User::factory()->create(['is_gm' => true])->id, 'name' => 'Closed Hall', 'is_public' => false]);

        $this->artisan('admin')
            ->expectsChoice('Admin', 'stats', $this->menu())
            ->expectsChoice('Admin', 'exit', $this->menu())
            ->expectsOutputToContain('Users')
            ->assertSuccessful();

        $this->assertSame([
            ['Users', '5'],
            ['Active accounts', '4'],
            ['Suspended accounts', '1'],
            ['Active in the last day', '0'],
            ['Characters', '1'],
            ['Campaigns', '2'],
            ['Public campaigns', '1'],
        ], $this->rows('statisticRows'));
    }

    /**
     * @return list<array<int, string>>
     */
    private function rows(string $method): array
    {
        $command = $this->app->make(Admin::class);
        $rows = (new ReflectionMethod($command, $method))->invoke($command);

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function menu(): array
    {
        return [
            'users' => 'List all users',
            'suspend' => 'Suspend or restore a user',
            'stats' => 'Site statistics',
            'exit' => 'Exit',
        ];
    }
}
