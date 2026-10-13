<?php

namespace App\Http\Middleware;

use App\Domain\Maps\MapBackgrounds;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_gm' => (bool) $user->is_gm,
                ] : null,
            ],
            'can' => [
                'gm' => (bool) $user?->is_gm,
                'campaigns' => (bool) $user,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'roll' => fn () => $request->session()->get('roll'),
            ],
            'logo' => filled(config('filesystems.disks.s3.url'))
                ? rtrim((string) config('filesystems.disks.s3.url'), '/').'/branding/eldritch-runes-logo.webp'
                : null,
            'crest' => filled(config('filesystems.disks.s3.url'))
                ? rtrim((string) config('filesystems.disks.s3.url'), '/').'/branding/eldritch-runes-cover.jpg'
                : '/images/eldritch-runes-cover.jpg',
            'mapBackgrounds' => MapBackgrounds::keys(),
            'art' => collect([
                'armor.webp',
                'coin.webp',
                'combat.webp',
                'crit.webp',
                'damage.webp',
                ...array_values(MapBackgrounds::files()),
                'firelight.webp',
                'focus.webp',
                'init.webp',
                'luck.webp',
                'major-action.webp',
                'minor-action.webp',
                'move.webp',
                'npcs.webp',
                'percentile-tiers.jpg',
                'player-characters.webp',
                'potential.webp',
                'power.webp',
                'rock.webp',
                'sigil.png',
                'soul.webp',
                'stamina.webp',
                'suffering.webp',
                'tree.webp',
                'turn-icon.webp',
                'wounds.webp',
            ])->unique()->mapWithKeys(function (string $file) {
                $base = config('filesystems.disks.s3.url');
                $name = pathinfo($file, PATHINFO_FILENAME);

                return [$name => filled($base) ? rtrim((string) $base, '/').'/art/'.$file : '/images/'.$file];
            })->all(),
        ];
    }
}
