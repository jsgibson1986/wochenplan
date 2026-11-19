<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Terminliste as TerminlisteSeite;
use App\Services\Kalender\Terminliste;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Terminliste für Untis: Druck (A4 hoch) und CSV (Excel: Semikolon,
 * UTF-8 mit BOM). Zeitraum und Filter wie auf der Seite „Liste für Untis“.
 */
class TerminlisteController
{
    public function druck(Request $request)
    {
        return view('kalender.liste-druck', ['liste' => $this->liste($request)]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $liste = $this->liste($request);
        $dateiname = 'termine-untis-'.$liste->von->format('Y-m-d').'-bis-'.$liste->bis->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($liste) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel Umlaute richtig liest
            $zeile = fn (array $werte) => fputcsv($out, $werte, ';', '"', '');

            $zeile(['KW', 'Datum', 'Wochentag', 'Zeit', 'Termin', 'Klassen', 'Ort', 'Quelle', 'Ferien/Feiertag', 'In Untis eingetragen']);
            foreach ($liste->wochen as $woche) {
                foreach ($woche['tage'] as $tag) {
                    $frei = implode(', ', array_column($tag['markierungen'], 'titel'));
                    foreach ($tag['eintraege'] as $e) {
                        $zeile([
                            $woche['kw'],
                            $tag['datum']->format('d.m.Y'),
                            $tag['datum']->locale('de')->isoFormat('dddd'),
                            $e['zeit'],
                            $e['titel'],
                            implode(', ', $e['klassen']),
                            $e['ort'],
                            $e['quelle'],
                            $frei,
                            $e['erledigt'] ? 'ja' : 'nein',
                        ]);
                    }
                }
            }
            fclose($out);
        }, $dateiname, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function liste(Request $request): Terminliste
    {
        abort_unless($request->user()?->aktiv, 403);
        [$von, $bis] = TerminlisteSeite::zeitraum($request->query('von'), $request->query('bis'));

        return new Terminliste(
            $von,
            $bis,
            array_map('intval', (array) $request->user()->einstellung('liste_ausgeblendet', [])),
            (bool) $request->query('offen'),
            (bool) $request->query('laufende'),
        );
    }
}
