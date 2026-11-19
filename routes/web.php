<?php

use App\Http\Controllers\TerminlisteController;
use App\Services\Kalender\IcsParser;
use App\Services\Kalender\KalenderSync;
use App\Services\Kalender\Woche;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Vom KAS-Cronjob (nur URL-Aufrufe möglich) alle 15 Minuten aufgerufen.
 * Ohne Login erreichbar, deshalb Token-Vergleich mit hash_equals und
 * Drosselung. Falscher Token → 404, um nichts zu verraten.
 */
Route::get('/system/sync', function (Request $request, KalenderSync $sync) {
    $erwartet = (string) config('wochenplan.sync_token');
    $token = (string) $request->query('token', '');

    abort_if($erwartet === '' || ! hash_equals($erwartet, $token), 404);

    @set_time_limit(300);

    $zeilen = [];
    foreach ($sync->alle() as $name => $ergebnis) {
        $zeilen[] = $name.': '.$ergebnis;
    }

    return response(implode("\n", $zeilen) ?: 'Keine aktiven Quellen', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->middleware('throttle:6,1');

/**
 * Druckansicht: eine Woche pro Seite (A4 quer). Übernimmt die gespeicherten
 * Filter des Nutzers (ausgeblendete Quellen, Wochenende) und die Suche.
 */
Route::get('/druck', function (Request $request) {
    $user = $request->user();
    abort_unless($user?->aktiv, 403);

    $von = Woche::ausParameter($request->query('von'));
    $bis = Woche::ausParameter($request->query('bis', $request->query('von')));
    if ($bis < $von) {
        [$von, $bis] = [$bis, $von];
    }

    $max = (int) config('wochenplan.druck_max_wochen', 26);
    $wochen = [];
    for ($montag = $von; $montag <= $bis && count($wochen) < $max; $montag = $montag->addWeek()) {
        $wochen[] = new Woche(
            $montag,
            mitWochenende: (bool) $user->einstellung('wochenende', false),
            ausgeblendeteQuellen: array_map('intval', (array) $user->einstellung('ausgeblendet', [])),
            suche: (string) $request->query('suche', ''),
        );
    }

    return view('kalender.druck', [
        'wochen' => $wochen,
        'von' => Woche::schluessel($von),
        'bis' => Woche::schluessel($bis),
        'suche' => (string) $request->query('suche', ''),
        'stand' => \App\Models\Quelle::where('aktiv', true)->min('letzter_erfolg'),
        'zeitzone' => IcsParser::ZEITZONE,
    ]);
})->middleware(['auth'])->name('druck');

Route::get('/liste/druck', [TerminlisteController::class, 'druck'])->middleware(['auth'])->name('liste.druck');
Route::get('/liste/csv', [TerminlisteController::class, 'csv'])->middleware(['auth'])->name('liste.csv');
