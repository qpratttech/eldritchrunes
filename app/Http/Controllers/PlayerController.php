<?php

namespace App\Http\Controllers;

use App\Domain\Combat\CombatService;
use App\Events\TableUpdated;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Combatant;
use App\Models\SceneToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class PlayerController extends Controller
{
    public function show()
    {
        $user = request()->user();
        $confirming = $user->two_factor_secret && ! $user->two_factor_confirmed_at;
        $enabled = $user->hasEnabledTwoFactorAuthentication();

        return Inertia::render('Player/Show', [
            'twoFactor' => [
                'enabled' => $enabled,
                'confirming' => (bool) $confirming,
                'qr' => $confirming ? $user->twoFactorQrCodeSvg() : null,
                'secret' => $confirming ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
                'recoveryCodes' => $enabled ? $user->recoveryCodes() : [],
            ],
            'passkeys' => $user->passkeys()->latest()->get()->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'last_used' => $passkey->last_used_at?->diffForHumans(),
            ]),
            'description' => $user->description ?? '',
        ]);
    }

    public function publicPage(User $user)
    {
        return Inertia::render('Player/Public', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_gm' => (bool) $user->is_gm,
                'description' => $user->description,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'current_password' => ['required_with:password', 'nullable', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);
        $passwordChanged = filled($data['password'] ?? null);
        $emailChanged = $data['email'] !== $user->email;
        $description = array_key_exists('description', $data) ? trim((string) $data['description']) : null;
        $description = $description === '' ? null : $description;
        $descriptionChanged = array_key_exists('description', $data) && $description !== $user->description;
        $updates = ['email' => $data['email']];
        if (array_key_exists('description', $data)) {
            $updates['description'] = $description;
        }
        if ($passwordChanged) {
            $updates['password'] = $data['password'];
        }
        $user->update($updates);

        $success = match (true) {
            $passwordChanged && ($emailChanged || $descriptionChanged) => 'Account saved.',
            $passwordChanged => 'Password saved.',
            $emailChanged && $descriptionChanged => 'Account saved.',
            $descriptionChanged => 'Description saved.',
            default => 'Email saved.',
        };

        return back()->with('success', $success);
    }

    public function becomeGameMaster(Request $request)
    {
        abort_if($request->user()->is_gm, 403);
        $request->user()->update(['is_gm' => true]);

        return back()->with('success', 'You are a game master now.');
    }

    public function resignGameMaster(Request $request)
    {
        abort_unless($request->user()->is_gm, 403);
        $request->user()->update(['is_gm' => false]);

        return back()->with('success', 'You are a player now.');
    }

    public function destroy(Request $request, CombatService $combat)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);
        $user = $request->user();
        $characters = Character::query()->where('user_id', $user->id)->get();
        $campaignIds = $characters->pluck('campaign_id')->filter()->map(fn ($id) => (int) $id)->unique();
        foreach ($characters as $character) {
            $combatants = Combatant::query()->where('character_id', $character->id)->with('encounter')->get();
            foreach ($combatants as $combatant) {
                if ($combatant->encounter) {
                    $combat->withdraw($combatant->encounter, $combatant);
                } else {
                    SceneToken::query()->where('combatant_id', $combatant->id)->delete();
                    $combatant->delete();
                }
            }
        }
        $ownedIds = Campaign::query()->where('owner_id', $user->id)->pluck('id');
        Campaign::query()->where('owner_id', $user->id)->get()->each(function (Campaign $campaign) {
            Character::query()->where('campaign_id', $campaign->id)->update(['campaign_id' => null]);
            $campaign->creatures()->delete();
            $campaign->delete();
        });
        $campaignIds->reject(fn (int $id) => $ownedIds->contains($id))->each(function (int $id) {
            event(new TableUpdated($id));
        });
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
