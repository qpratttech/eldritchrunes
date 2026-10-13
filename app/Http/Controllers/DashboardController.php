<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $user = request()->user();

        return Inertia::render('Dashboard', [
            'counts' => [
                'characters' => Character::query()->where('user_id', $user->id)->count(),
                'campaigns' => Campaign::query()->where('owner_id', $user->id)->count(),
            ],
        ]);
    }
}
