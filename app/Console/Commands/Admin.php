<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

#[Signature('admin')]
#[Description('Open the site admin menu')]
class Admin extends Command
{
    /**
     * @var array<string, string>
     */
    private const MENU = [
        'users' => 'List all users',
        'suspend' => 'Suspend or restore a user',
        'stats' => 'Site statistics',
        'exit' => 'Exit',
    ];

    public function handle(): int
    {
        while (true) {
            $choice = select('Admin', self::MENU);

            if ($choice === 'exit') {
                return self::SUCCESS;
            }

            match ($choice) {
                'users' => $this->listUsers(),
                'suspend' => $this->suspendOrRestore(),
                'stats' => $this->statistics(),
                default => null,
            };
        }
    }

    private function listUsers(): void
    {
        $rows = $this->userRows();
        if ($rows === []) {
            info('No users yet.');

            return;
        }

        table(
            ['Name', 'Email', 'Role', 'Status', 'Created', 'Last activity'],
            $rows,
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function userRows(): array
    {
        $users = User::withTrashed()->orderByDesc('created_at')->orderBy('name')->get();
        $activity = $this->lastActivity($users->pluck('id')->all());

        return $users->map(fn (User $user) => [
            $user->name,
            $user->email,
            $user->is_gm ? 'Game master' : 'Player',
            $user->trashed() ? 'Suspended' : 'Active',
            $this->timestamp($user->created_at),
            $this->timestamp($activity[$user->id] ?? null),
        ])->all();
    }

    private function suspendOrRestore(): void
    {
        if (User::withTrashed()->doesntExist()) {
            info('No users yet.');

            return;
        }

        $userId = search(
            label: 'User',
            options: function (string $value) {
                return User::withTrashed()
                    ->when($value !== '', function ($query) use ($value) {
                        $query->where(function ($query) use ($value) {
                            $query->where('name', 'like', "%{$value}%")
                                ->orWhere('email', 'like', "%{$value}%");
                        });
                    })
                    ->orderBy('name')
                    ->limit(15)
                    ->get()
                    ->mapWithKeys(fn (User $user) => [
                        $user->id => $user->name.' · '.$user->email.($user->trashed() ? ' · suspended' : ''),
                    ])
                    ->all();
            },
            hint: 'Name or email',
        );

        $user = User::withTrashed()->find($userId);
        if (! $user) {
            info('That user could not be found.');

            return;
        }

        if ($user->trashed()) {
            if (! confirm("Restore {$user->name}?")) {
                return;
            }
            $user->restore();
            info("{$user->name} can sign in again.");

            return;
        }

        if (! confirm("Suspend {$user->name}? They will be signed out.")) {
            return;
        }
        $user->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        info("{$user->name} is suspended.");
    }

    private function statistics(): void
    {
        table(['Statistic', 'Count'], $this->statisticRows());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function statisticRows(): array
    {
        $since = now()->subDay()->getTimestamp();
        $activeToday = (int) DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $since)
            ->selectRaw('count(distinct user_id) as aggregate')
            ->value('aggregate');

        return [
            ['Users', (string) User::withTrashed()->count()],
            ['Active accounts', (string) User::count()],
            ['Suspended accounts', (string) User::onlyTrashed()->count()],
            ['Active in the last day', (string) $activeToday],
            ['Characters', (string) Character::count()],
            ['Campaigns', (string) Campaign::count()],
            ['Public campaigns', (string) Campaign::query()->where('is_public', true)->count()],
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, Carbon>
     */
    private function lastActivity(array $userIds): array
    {
        return DB::table('sessions')
            ->selectRaw('user_id, max(last_activity) as last_activity')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->pluck('last_activity', 'user_id')
            ->map(fn ($timestamp) => Carbon::createFromTimestamp((int) $timestamp))
            ->all();
    }

    private function timestamp(mixed $time): string
    {
        if (! $time instanceof Carbon) {
            return 'Never';
        }

        return $time->timezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }
}
