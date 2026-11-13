<?php

use App\Http\Controllers\CardController;
use App\Http\Controllers\UniverseCardController;
use App\Http\Controllers\UniverseChronologyController;
use App\Http\Controllers\UniverseNotesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::get('/workspace/universes/{universe}/card-templates', [UniverseCardController::class, 'templates']);
    Route::get('/workspace/cards/{card}', [CardController::class, 'show']);

    Route::get('/workspace/novels/{novel}/character-roles', [UniverseCardController::class, 'characterRoles']);
    Route::get('/workspace/novels/{novel}/cards/{card}/context', [UniverseCardController::class, 'cardContext']);
    Route::put('/workspace/novels/{novel}/cards/{card}/role', [UniverseCardController::class, 'updateCharacterRole']);

    Route::post('/workspace/universes/{universe}/places/containment', [UniverseCardController::class, 'storeContainment']);
    Route::get('/workspace/universes/{universe}/places/{card}/hierarchy', [UniverseCardController::class, 'placeHierarchy']);

    Route::post('/workspace/universes/{universe}/objects/{card}/custody', [UniverseCardController::class, 'storeCustody']);
    Route::get('/workspace/universes/{universe}/objects/{card}/custody', [UniverseCardController::class, 'custodyAt']);
    Route::post('/workspace/universes/{universe}/resources/{card}/origins', [UniverseCardController::class, 'storeOrigin']);
    Route::get('/workspace/universes/{universe}/resources/{card}/origins', [UniverseCardController::class, 'origins']);

    Route::get('/workspace/universes/{universe}/glossary-terms', [UniverseCardController::class, 'glossary']);
    Route::post('/workspace/universes/{universe}/glossary-terms', [UniverseCardController::class, 'storeGlossaryTerm']);

    Route::get('/workspace/universes/{universe}/events', [UniverseChronologyController::class, 'events']);
    Route::post('/workspace/universes/{universe}/events', [UniverseChronologyController::class, 'storeEvent']);
    Route::post('/workspace/universes/{universe}/event-relations', [UniverseChronologyController::class, 'storeRelation']);
    Route::get('/workspace/universes/{universe}/events/{event}/compare', [UniverseChronologyController::class, 'compareEvent']);

    Route::get('/workspace/universes/{universe}/calendars', [UniverseChronologyController::class, 'calendars']);
    Route::post('/workspace/universes/{universe}/calendars', [UniverseChronologyController::class, 'storeCalendar']);
    Route::post('/workspace/universes/{universe}/calendar-conversions', [UniverseChronologyController::class, 'storeConversion']);
    Route::post('/workspace/universes/{universe}/calendar-conversions/resolve', [UniverseChronologyController::class, 'resolveConversion']);

    Route::post('/workspace/universes/{universe}/cards/{card}/story-changes', [UniverseChronologyController::class, 'storeStoryChange']);
    Route::get('/workspace/universes/{universe}/cards/{card}/story-time', [UniverseChronologyController::class, 'storyTime']);
    Route::post('/workspace/scenes/{scene}/story-context', [UniverseChronologyController::class, 'storeStoryContext']);
    Route::get('/workspace/scenes/{scene}/story-context', [UniverseChronologyController::class, 'showStoryContext']);

    Route::get('/workspace/universes/{universe}/continuity-warnings', [UniverseChronologyController::class, 'warnings']);
    Route::post('/workspace/universes/{universe}/continuity-warnings/dismiss', [UniverseChronologyController::class, 'dismissWarning']);

    Route::get('/workspace/notes', [UniverseNotesController::class, 'personalNotes']);
    Route::get('/workspace/universes/{universe}/notes', [UniverseNotesController::class, 'universeNotes']);
    Route::post('/workspace/universes/{universe}/notes', [UniverseNotesController::class, 'storeNote']);
    Route::patch('/workspace/notes/{note}', [UniverseNotesController::class, 'updateNote']);
    Route::patch('/workspace/notes/{note}/pending', [UniverseNotesController::class, 'setPending']);
    Route::get('/workspace/novels/{novel}/pendings', [UniverseNotesController::class, 'novelPendings']);

    Route::get('/workspace/universes/{universe}/bibliography', [UniverseNotesController::class, 'bibliography']);
    Route::post('/workspace/universes/{universe}/bibliography', [UniverseNotesController::class, 'storeBibliography']);
    Route::patch('/workspace/bibliography/{entry}/selected', [UniverseNotesController::class, 'selectBibliography']);
    Route::post('/workspace/bibliography/{entry}/notes/{note}', [UniverseNotesController::class, 'attachBibliography']);
    Route::get('/workspace/novels/{novel}/bibliography', [UniverseNotesController::class, 'novelBibliography']);
});
