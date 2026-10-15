<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EncounterController;
use App\Http\Controllers\NpcController;
use App\Http\Controllers\RulesController;
use App\Http\Controllers\SceneController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : Inertia::render('Landing'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate']);
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/characters', [CharacterController::class, 'index'])->name('characters.index');
    Route::get('/characters/create', [CharacterController::class, 'create'])->name('characters.create');
    Route::post('/characters', [CharacterController::class, 'store'])->name('characters.store');
        Route::get('/characters/{character}', [CharacterController::class, 'show'])->name('characters.show');
        Route::patch('/characters/{character}', [CharacterController::class, 'update'])->name('characters.update');
        Route::patch('/characters/{character}/portrait', [CharacterController::class, 'setPortrait'])->name('characters.portrait');
        Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');
    Route::post('/characters/{character}/conditions', [CharacterController::class, 'addCondition'])->name('characters.conditions.store');
    Route::delete('/characters/{character}/conditions/{combatantCondition}', [CharacterController::class, 'removeCondition'])->name('characters.conditions.destroy');
    Route::post('/characters/{character}/classes', [CharacterController::class, 'addClass'])->name('characters.classes');
    Route::post('/characters/{character}/rolls', [CharacterController::class, 'roll'])->name('characters.rolls');
    Route::post('/characters/{character}/attacks', [CharacterController::class, 'attack'])->name('characters.attacks');
    Route::post('/characters/{character}/spells', [CharacterController::class, 'addSpell'])->name('characters.spells.store');
    Route::delete('/characters/{character}/spells/{characterSpell}', [CharacterController::class, 'removeSpell'])->name('characters.spells.destroy');
        Route::post('/characters/{character}/techniques', [CharacterController::class, 'addTechnique'])->name('characters.techniques.store');
        Route::delete('/characters/{character}/techniques/{characterTechnique}', [CharacterController::class, 'removeTechnique'])->name('characters.techniques.destroy');
        Route::post('/characters/{character}/spell-techniques', [CharacterController::class, 'addSpellTechnique'])->name('characters.spell-techniques.store');
        Route::delete('/characters/{character}/spell-techniques/{technique}', [CharacterController::class, 'removeSpellTechnique'])->name('characters.spell-techniques.destroy');
        Route::post('/characters/{character}/orders', [CharacterController::class, 'addOrder'])->name('characters.orders.store');
        Route::delete('/characters/{character}/orders/{access}', [CharacterController::class, 'removeOrder'])->name('characters.orders.destroy');
        Route::post('/characters/{character}/companion', [CharacterController::class, 'setCompanion'])->name('characters.companion');
    Route::post('/characters/{character}/inventory', [CharacterController::class, 'addItem'])->name('characters.inventory.store');
    Route::delete('/characters/{character}/inventory/{inventory}', [CharacterController::class, 'removeItem'])->name('characters.inventory.destroy');
    Route::post('/characters/{character}/equipment/{inventory}/equip', [CharacterController::class, 'equip'])->name('characters.equip');
    Route::post('/characters/{character}/luck', [CharacterController::class, 'luck'])->name('characters.luck');

    Route::get('/rules', RulesController::class)->name('rules');

    foreach (['species', 'backgrounds', 'classes', 'specializations', 'items', 'talents', 'features', 'spells'] as $library) {
        Route::get("/{$library}", fn () => app(CatalogController::class)->index($library))->name("{$library}.index");
        Route::post("/{$library}", fn () => app(CatalogController::class)->store(request(), $library))->name("{$library}.store");
        Route::patch("/{$library}/{id}", fn (int $id) => app(CatalogController::class)->update(request(), $library, $id))->name("{$library}.update");
        Route::delete("/{$library}/{id}", fn (int $id) => app(CatalogController::class)->destroy($library, $id))->name("{$library}.destroy");
    }

    Route::middleware('gm')->group(function () {
        Route::get('/npcs', [NpcController::class, 'index'])->name('npcs.index');
        Route::post('/npcs', [NpcController::class, 'store'])->name('npcs.store');
        Route::patch('/npcs/{template}', [NpcController::class, 'update'])->name('npcs.update');
        Route::post('/npcs/{template}/copy', [NpcController::class, 'copy'])->name('npcs.copy');
        Route::post('/npcs/{template}/rolls', [NpcController::class, 'roll'])->name('npcs.rolls');
        Route::patch('/npc-instances/{instance}', [NpcController::class, 'updateInstance'])->name('npcs.instances.update');
        Route::post('/npc-instances/{instance}/conditions', [NpcController::class, 'addCondition'])->name('npcs.instances.conditions.store');
        Route::delete('/npc-instances/{instance}/conditions/{combatantCondition}', [NpcController::class, 'removeCondition'])->name('npcs.instances.conditions.destroy');
        Route::delete('/npc-instances/{instance}', [NpcController::class, 'destroyInstance'])->name('npcs.instances.destroy');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    });

    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::post('/campaigns/{campaign}/members', [CampaignController::class, 'addMember'])->name('campaigns.members');
    Route::post('/campaigns/{campaign}/characters', [CampaignController::class, 'assignCharacter'])->name('campaigns.characters');
    Route::post('/campaigns/{campaign}/join', [CampaignController::class, 'join'])->name('campaigns.join');
        Route::post('/campaigns/{campaign}/attacks', [CampaignController::class, 'attack'])->name('campaigns.attacks');
        Route::post('/campaigns/{campaign}/checks', [CampaignController::class, 'check'])->name('campaigns.checks');
        Route::post('/campaigns/{campaign}/dice', [CampaignController::class, 'dice'])->name('campaigns.dice');

    Route::post('/campaigns/{campaign}/encounters', [EncounterController::class, 'store'])->name('encounters.store');
    Route::post('/campaigns/{campaign}/combat', [EncounterController::class, 'addToCombat'])->name('campaigns.combat');
    Route::delete('/campaigns/{campaign}/combat', [EncounterController::class, 'removeFromCombat'])->name('campaigns.combat.remove');
    Route::post('/encounters/{encounter}/characters', [EncounterController::class, 'addCharacter'])->name('encounters.characters');
    Route::post('/encounters/{encounter}/creatures', [EncounterController::class, 'addCreature'])->name('encounters.creatures');
    Route::post('/encounters/{encounter}/initiative', [EncounterController::class, 'initiative'])->name('encounters.initiative');
    Route::post('/encounters/{encounter}/end-turn', [EncounterController::class, 'endTurn'])->name('encounters.end');
    Route::post('/encounters/{encounter}/clear', [EncounterController::class, 'clear'])->name('encounters.clear');
    Route::post('/combatants/{combatant}/actions', [EncounterController::class, 'spend'])->name('combatants.actions');
    Route::post('/combatants/{combatant}/attacks', [EncounterController::class, 'attack'])->name('combatants.attacks');
    Route::post('/combatants/{combatant}/damage', [EncounterController::class, 'damage'])->name('combatants.damage');
    Route::post('/combatants/{combatant}/conditions', [EncounterController::class, 'condition'])->name('combatants.conditions');

    Route::patch('/scenes/{scene}', [SceneController::class, 'update'])->name('scenes.update');
    Route::post('/scenes/{scene}/paint', [SceneController::class, 'paint'])->name('scenes.paint');
    Route::post('/scenes/{scene}/clear-painted', [SceneController::class, 'clearPainted'])->name('scenes.clear-painted');
    Route::post('/scenes/{scene}/lights', [SceneController::class, 'light'])->name('scenes.lights');
    Route::patch('/tokens/{token}', [SceneController::class, 'moveToken'])->name('tokens.move');
});
