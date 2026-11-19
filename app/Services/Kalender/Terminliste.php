<?php

namespace App\Services\Kalender;

use App\Models\Quelle;
use App\Models\Termin;
use App\Models\UntisVermerk;
use Carbon\CarbonImmutable;

/**
 * Chronologische Terminliste zum Übertragen in Untis: nach Kalenderwoche
 * und Tag gruppiert, mehrtägige Termine einmal am ersten Tag (mit „bis …“),
 * Ferien/Feiertage als Tageshinweis, erkannte Klassen, Haken „eingetragen“.
 */
class Terminliste
{
    public readonly CarbonImmutable $von;

    public readonly CarbonImmutable $bis;

    /** @var list<array{kw: int, titel: string, tage: list<array>}> */
    public array $wochen = [];

    public int $anzahl = 0;

    public int $offen = 0;

    /** Termine, die vor dem Zeitraum begonnen haben (ausgeblendet, wenn nicht $mitLaufenden). */
    public int $laufende = 0;

    /**
     * @param  list<int>  $ausgeblendeteQuellen
     */
    public function __construct(
        CarbonImmutable $von,
        CarbonImmutable $bis,
        public readonly array $ausgeblendeteQuellen = [],
        public readonly bool $nurOffen = false,
        public readonly bool $mitLaufenden = false,
    ) {
        $tz = IcsParser::ZEITZONE;
        $this->von = $von->setTimezone($tz)->startOfDay();
        $this->bis = $bis->setTimezone($tz)->startOfDay();
        $this->baue();
    }

    public static function schluessel(Termin $termin): string
    {
        return sha1($termin->quelle_id.'|'.($termin->uid ?: $termin->titel).'|'.$termin->beginn->format('Y-m-d H:i'));
    }

    /**
     * Klassen und Jahrgangsstufen aus dem Text erkennen, z. B.
     * „7e Medienkompetenztraining“, „8cII Vortrag“, „(10a, 10b)“,
     * „Vollversammlung 5. - 7. Jgst.“.
     *
     * @return list<string>
     */
    public static function klassen(string $text): array
    {
        $gefunden = [];

        if (preg_match_all('/(?<![\p{L}\d.])((?:10|[5-9])[a-h](?:III|II|I)?)(?![\p{L}\d])/u', $text, $m)) {
            array_push($gefunden, ...$m[1]);
        }

        $spanne = '(\d{1,2})\.?\s*(?:[-–]|bis)\s*(\d{1,2})';
        if (
            preg_match_all('/'.$spanne.'\.\s*(?:Jgst|Jahrgangsstufe|Klassen)/u', $text, $j, PREG_SET_ORDER)
            || preg_match_all('/(?:Jgst\.?|Jahrgangsstufen?|Klassen)\s+'.$spanne.'(?!\d)/u', $text, $j, PREG_SET_ORDER)
        ) {
            foreach ($j as $treffer) {
                $gefunden[] = 'Jgst. '.$treffer[1].'–'.$treffer[2];
            }
        } elseif (preg_match_all('/(?<!\d)(\d{1,2})\.\s*(?:Jgst|Jahrgangsstufe|Klassen)/u', $text, $j)) {
            foreach ($j[1] as $stufe) {
                $gefunden[] = 'Jgst. '.$stufe;
            }
        }

        return array_values(array_unique($gefunden));
    }

    /** @return \Illuminate\Support\Collection<int, Quelle> */
    public function quellen()
    {
        return Quelle::where('aktiv', true)->orderBy('reihenfolge')->orderBy('name')->get();
    }

    private function baue(): void
    {
        $quellen = $this->quellen()->keyBy('id');
        $ende = $this->bis->addDay();

        $termine = Termin::query()
            ->whereIn('quelle_id', $quellen->keys())
            ->imZeitraum($this->von, $ende)
            ->orderBy('beginn')
            ->orderBy('titel')
            ->get();

        $vermerke = UntisVermerk::with('user')->get()->keyBy('schluessel');

        // Tage vorbereiten
        $tage = [];
        for ($tag = $this->von; $tag < $ende; $tag = $tag->addDay()) {
            $tage[$tag->format('Y-m-d')] = ['datum' => $tag, 'markierungen' => [], 'eintraege' => []];
        }

        foreach ($termine as $termin) {
            $quelle = $quellen->get($termin->quelle_id);
            $beginn = CarbonImmutable::instance($termin->beginn);
            $terminEnde = CarbonImmutable::instance($termin->ende);
            $letzterTag = $terminEnde > $beginn ? $terminEnde->subSecond()->startOfDay() : $beginn->startOfDay();

            if ($quelle->istTagesmarkierung()) {
                if (in_array($quelle->id, $this->ausgeblendeteQuellen, true)) {
                    continue;
                }
                for ($tag = max($beginn->startOfDay(), $this->von); $tag <= $letzterTag && $tag < $ende; $tag = $tag->addDay()) {
                    $tage[$tag->format('Y-m-d')]['markierungen'][] = ['titel' => $termin->titel, 'farbe' => $quelle->farbe];
                }

                continue;
            }

            if (in_array($quelle->id, $this->ausgeblendeteQuellen, true)) {
                continue;
            }

            if ($beginn->startOfDay() < $this->von) {
                $this->laufende++;
                if (! $this->mitLaufenden) {
                    continue;
                }
            }

            $schluessel = self::schluessel($termin);
            $vermerk = $vermerke->get($schluessel);

            $anzeigeTag = max($beginn->startOfDay(), $this->von)->format('Y-m-d');
            $tage[$anzeigeTag]['eintraege'][] = [
                'ganztaegig' => $termin->ganztaegig,
                'schluessel' => $schluessel,
                'titel' => $termin->titel,
                'zeit' => $this->zeit($beginn, $terminEnde, $letzterTag, $termin->ganztaegig),
                'klassen' => self::klassen($termin->titel.' '.$termin->beschreibung),
                'ort' => $termin->ort,
                'beschreibung' => $termin->beschreibung,
                'quelle' => $quelle->name,
                'farbe' => $quelle->farbe,
                'erledigt' => (bool) $vermerk,
                'erledigtVon' => $vermerk?->user?->name,
                'erledigtAm' => $vermerk?->created_at?->setTimezone(IcsParser::ZEITZONE)->format('d.m.Y H:i'),
                'vorher' => $beginn->startOfDay() < $this->von,
            ];
        }

        // Gleichnamige Ganztagstermine (z. B. „Buß- und Bettag“ aus dem
        // Schulmanager) stehen schon als Ferien-/Feiertagshinweis da.
        // Danach zählen und ggf. auf offene Termine filtern.
        $normal = fn (string $s) => mb_strtolower(trim($s));
        foreach ($tage as $datum => $tag) {
            $frei = array_map(fn ($m) => $normal($m['titel']), $tag['markierungen']);
            $behalten = [];
            foreach ($tag['eintraege'] as $e) {
                if ($e['ganztaegig'] && in_array($normal($e['titel']), $frei, true)) {
                    continue;
                }
                $this->anzahl++;
                if (! $e['erledigt']) {
                    $this->offen++;
                }
                if ($this->nurOffen && $e['erledigt']) {
                    continue;
                }
                $behalten[] = $e;
            }
            $tage[$datum]['eintraege'] = $behalten;
        }

        // Nach Wochen gruppieren; Wochenenden nur, wenn dort etwas steht.
        $wochen = [];
        foreach ($tage as $tag) {
            if ($tag['datum']->isWeekend() && $tag['eintraege'] === [] && $tag['markierungen'] === []) {
                continue;
            }
            $kw = $tag['datum']->isoFormat('GGGG-WW');
            $wochen[$kw] ??= [
                'kw' => $tag['datum']->isoWeek(),
                'titel' => 'KW '.$tag['datum']->isoWeek().' · '.$tag['datum']->startOfWeek()->locale('de')->isoFormat('D. MMM').' – '.$tag['datum']->startOfWeek()->addDays(4)->locale('de')->isoFormat('D. MMM YYYY'),
                'tage' => [],
            ];
            $wochen[$kw]['tage'][] = $tag;
        }

        $this->wochen = array_values($wochen);
    }

    private function zeit(CarbonImmutable $beginn, CarbonImmutable $ende, CarbonImmutable $letzterTag, bool $ganztaegig): string
    {
        $tag = fn (CarbonImmutable $c) => $c->locale('de')->isoFormat('dd D.M.');
        $mehrtaegig = ! $beginn->startOfDay()->equalTo($letzterTag);

        if ($ganztaegig) {
            return $mehrtaegig ? 'ganztägig bis '.$tag($letzterTag) : 'ganztägig';
        }

        if ($mehrtaegig) {
            return $beginn->format('H:i').' bis '.$tag($ende).' '.$ende->format('H:i');
        }

        return $ende->equalTo($beginn) ? $beginn->format('H:i') : $beginn->format('H:i').'–'.$ende->format('H:i');
    }
}
