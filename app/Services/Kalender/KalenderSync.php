<?php

namespace App\Services\Kalender;

use App\Models\Quelle;
use App\Models\Termin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class KalenderSync
{
    public function __construct(
        private readonly IcsParser $parser,
        private readonly CalDavClient $calDav,
        private readonly FerienClient $ferien,
    ) {}

    /**
     * Zeitfenster der gespeicherten Termine: 3 Monate zurück, 15 Monate
     * voraus (reicht für die Planung des nächsten Schuljahres).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function zeitfenster(): array
    {
        $heute = CarbonImmutable::now(IcsParser::ZEITZONE)->startOfDay();

        return [$heute->subMonths(3)->startOfWeek(), $heute->addMonths(15)->endOfWeek()->addSecond()];
    }

    /**
     * @return array<string, string> Name der Quelle => Ergebnis
     */
    public function alle(): array
    {
        $ergebnisse = [];
        foreach (Quelle::where('aktiv', true)->orderBy('reihenfolge')->get() as $quelle) {
            $ergebnisse[$quelle->name] = $this->quelle($quelle);
        }

        return $ergebnisse;
    }

    /**
     * Holt eine Quelle neu. Alte Termine werden nur ersetzt, wenn der Abruf
     * vollständig geklappt hat; sonst bleiben sie stehen und der Fehler
     * wird an der Quelle vermerkt.
     */
    public function quelle(Quelle $quelle): string
    {
        [$von, $bis] = $this->zeitfenster();

        try {
            $termine = $this->hole($quelle, $von, $bis);
        } catch (\Throwable $e) {
            $meldung = Str::limit($e->getMessage(), 500);
            $quelle->forceFill(['letzter_abruf' => now(), 'letzter_fehler' => $meldung])->save();

            return 'Fehler: '.$meldung;
        }

        $jetzt = now();
        $zeilen = array_map(fn (array $t) => [
            'quelle_id' => $quelle->id,
            'uid' => $t['uid'],
            'titel' => $t['titel'],
            'beschreibung' => $t['beschreibung'],
            'ort' => $t['ort'],
            'link' => $t['link'],
            'beginn' => $t['beginn']->format('Y-m-d H:i:s'),
            'ende' => $t['ende']->format('Y-m-d H:i:s'),
            'ganztaegig' => $t['ganztaegig'],
            'created_at' => $jetzt,
            'updated_at' => $jetzt,
        ], $termine);

        DB::transaction(function () use ($quelle, $zeilen, $jetzt) {
            Termin::where('quelle_id', $quelle->id)->delete();
            foreach (array_chunk($zeilen, 200) as $block) {
                Termin::insert($block);
            }
            $quelle->forceFill([
                'letzter_abruf' => $jetzt,
                'letzter_erfolg' => $jetzt,
                'letzter_fehler' => null,
                'anzahl_termine' => count($zeilen),
            ])->save();
        });

        return count($zeilen).' Termine';
    }

    private function hole(Quelle $quelle, CarbonImmutable $von, CarbonImmutable $bis): array
    {
        return match ($quelle->typ) {
            'caldav' => $this->parser->parseMehrere($this->calDav->holeObjekte($quelle, $von, $bis), $von, $bis),
            'ical' => $this->parser->parse($this->holeIcal($quelle), $von, $bis),
            'ferien', 'feiertage' => $this->ferien->hole($quelle->typ, $von, $bis),
            default => throw new \RuntimeException("Unbekannter Quellentyp „{$quelle->typ}“."),
        };
    }

    private function holeIcal(Quelle $quelle): string
    {
        $url = preg_replace('#^webcals?://#i', 'https://', trim((string) $quelle->url));
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Keine gültige iCal-URL hinterlegt.');
        }

        $anfrage = Http::timeout(60)->withUserAgent('Wochenplan-Kalender/1.0');
        if (filled($quelle->benutzername)) {
            $anfrage = $anfrage->withBasicAuth((string) $quelle->benutzername, (string) $quelle->passwort);
        }

        $antwort = $anfrage->get($url);
        if (! $antwort->successful()) {
            throw new \RuntimeException("iCal-Abo antwortet mit HTTP {$antwort->status()}.");
        }
        if (! str_contains($antwort->body(), 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('Unter der URL liegen keine iCal-Daten.');
        }

        return $antwort->body();
    }
}
