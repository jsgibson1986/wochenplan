<?php

namespace App\Services\Kalender;

use App\Models\Quelle;
use App\Models\Termin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Bereitet eine Kalenderwoche für Wochenansicht und Druck auf:
 * Tagesspalten mit Ferien-/Feiertagsmarkierung, Balken für ganz- und
 * mehrtägige Termine (in Zeilen gepackt) und Uhrzeit-Termine je Tag.
 */
class Woche
{
    public readonly CarbonImmutable $montag;

    public readonly int $anzahlTage;

    /** @var list<array{datum: CarbonImmutable, heute: bool, markierungen: list<array>, termine: list<array>}> */
    public array $tage = [];

    /** @var list<list<array>> Zeilen mit Balken */
    public array $balkenZeilen = [];

    /** Alle Wochentage Mo–Fr sind Ferien oder Feiertag. */
    public bool $unterrichtsfrei = false;

    /**
     * @param  list<int>  $ausgeblendeteQuellen
     */
    public function __construct(
        CarbonImmutable $tagInDerWoche,
        public readonly bool $mitWochenende = false,
        public readonly array $ausgeblendeteQuellen = [],
        public readonly string $suche = '',
    ) {
        $this->montag = $tagInDerWoche->setTimezone(IcsParser::ZEITZONE)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $this->anzahlTage = $mitWochenende ? 7 : 5;
        $this->baue();
    }

    /** Akzeptiert „2026-W47“ oder ein Datum; sonst aktuelle Woche. */
    public static function ausParameter(?string $wert): CarbonImmutable
    {
        $tz = IcsParser::ZEITZONE;
        if ($wert && preg_match('/^(\d{4})-W(\d{1,2})$/', $wert, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 53) {
            return CarbonImmutable::now($tz)->setISODate((int) $m[1], (int) $m[2])->startOfWeek();
        }
        if ($wert && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $wert, $tz)->startOfWeek();
            } catch (\Throwable) {
                // ungültiges Datum → aktuelle Woche
            }
        }

        return CarbonImmutable::now($tz)->startOfWeek();
    }

    public static function schluessel(CarbonImmutable $tag): string
    {
        return $tag->isoFormat('GGGG-[W]WW');
    }

    public function kw(): int
    {
        return $this->montag->isoWeek();
    }

    public function schluesselDieserWoche(): string
    {
        return self::schluessel($this->montag);
    }

    public function letzterTag(): CarbonImmutable
    {
        return $this->montag->addDays($this->anzahlTage - 1);
    }

    public function titel(): string
    {
        $von = $this->montag;
        $bis = $this->letzterTag();
        $format = $von->month === $bis->month ? 'D.' : 'D. MMMM';
        if ($von->year !== $bis->year) {
            $format = 'D. MMMM YYYY';
        }

        return 'KW '.$this->kw().' · '.$von->locale('de')->isoFormat($format).' – '.$bis->locale('de')->isoFormat('D. MMMM YYYY');
    }

    /** @return Collection<int, Quelle> */
    public function quellen(): Collection
    {
        return Quelle::where('aktiv', true)->orderBy('reihenfolge')->orderBy('name')->get();
    }

    private function baue(): void
    {
        $von = $this->montag;
        $bis = $this->montag->addDays($this->anzahlTage);

        $quellen = $this->quellen()->keyBy('id');

        $termine = Termin::query()
            ->whereIn('quelle_id', $quellen->keys())
            ->imZeitraum($von, $bis)
            ->orderBy('beginn')
            ->orderBy('titel')
            ->get();

        foreach (range(0, $this->anzahlTage - 1) as $i) {
            $datum = $von->addDays($i);
            $this->tage[] = [
                'datum' => $datum,
                'heute' => $datum->isToday(),
                'markierungen' => [],
                'termine' => [],
            ];
        }

        $balken = [];

        foreach ($termine as $termin) {
            $quelle = $quellen->get($termin->quelle_id);
            $beginn = CarbonImmutable::instance($termin->beginn);
            $ende = CarbonImmutable::instance($termin->ende);

            // Ferien und Feiertage markieren ganze Tage. Ausblenden wirkt,
            // die Stichwortsuche nicht (sie bleiben als Kontext sichtbar).
            if ($quelle->istTagesmarkierung()) {
                if (in_array($quelle->id, $this->ausgeblendeteQuellen, true)) {
                    continue;
                }
                foreach ($this->tage as $index => $tag) {
                    if ($tag['datum'] >= $beginn->startOfDay() && $tag['datum'] < $ende) {
                        $this->tage[$index]['markierungen'][] = [
                            'titel' => $termin->titel,
                            'typ' => $quelle->typ,
                            'farbe' => $quelle->farbe,
                        ];
                    }
                }

                continue;
            }

            if (in_array($quelle->id, $this->ausgeblendeteQuellen, true) || ! $this->passtZurSuche($termin)) {
                continue;
            }

            $daten = $this->darstellung($termin, $quelle, $beginn, $ende);

            // Letzter belegter Tag: bei exklusivem Ende Mitternacht ist das
            // der Vortag.
            $letzterTag = $ende > $beginn ? $ende->subSecond()->startOfDay() : $beginn->startOfDay();
            $mehrtaegig = ! $beginn->startOfDay()->equalTo($letzterTag);

            if ($termin->ganztaegig || $mehrtaegig) {
                $startIndex = max(0, $this->tagIndex($beginn));
                $endIndex = min($this->anzahlTage - 1, $this->tagIndex($letzterTag));
                if ($endIndex < 0 || $startIndex > $this->anzahlTage - 1 || $endIndex < $startIndex) {
                    continue; // nur am ausgeblendeten Wochenende
                }
                $balken[] = $daten + [
                    'start' => $startIndex,
                    'spanne' => $endIndex - $startIndex + 1,
                    'vorher' => $beginn->startOfDay() < $von,
                    'nachher' => $letzterTag > $von->addDays($this->anzahlTage - 1),
                ];

                continue;
            }

            $index = $this->tagIndex($beginn);
            if ($index >= 0 && $index < $this->anzahlTage) {
                $this->tage[$index]['termine'][] = $daten;
            }
        }

        $this->balkenZeilen = $this->packeBalken($this->ohneDoppelteMarkierungen($balken));

        $wochentage = array_slice($this->tage, 0, 5);
        $this->unterrichtsfrei = collect($wochentage)->every(fn ($t) => $t['markierungen'] !== []);
    }

    /**
     * Der Schulmanager trägt Ferien und Feiertage oft selbst als
     * ganztägige Termine ein. Ein Balken, dessen sichtbare Tage alle schon
     * eine gleichnamige Ferien-/Feiertagsmarkierung tragen, wäre doppelt.
     *
     * @param  list<array>  $balken
     * @return list<array>
     */
    private function ohneDoppelteMarkierungen(array $balken): array
    {
        $normal = fn (string $s) => mb_strtolower(trim($s));

        return array_values(array_filter($balken, function (array $b) use ($normal) {
            if (! $b['ganztaegig']) {
                return true;
            }
            for ($i = $b['start']; $i < $b['start'] + $b['spanne']; $i++) {
                $titel = array_map(fn ($m) => $normal($m['titel']), $this->tage[$i]['markierungen']);
                if (! in_array($normal($b['titel']), $titel, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Spaltenindex eines Tages relativ zum Montag (Kalendertage, damit die
     * Zeitumstellung mit 23- bzw. 25-Stunden-Tagen nichts verschiebt).
     */
    private function tagIndex(CarbonImmutable $zeitpunkt): int
    {
        return (int) round(
            (strtotime($zeitpunkt->format('Y-m-d').' 00:00:00 UTC') - strtotime($this->montag->format('Y-m-d').' 00:00:00 UTC')) / 86400
        );
    }

    private function passtZurSuche(Termin $termin): bool
    {
        $suche = trim($this->suche);
        if ($suche === '') {
            return true;
        }

        return Str::contains(
            $termin->titel.' '.$termin->beschreibung.' '.$termin->ort,
            $suche,
            ignoreCase: true,
        );
    }

    private function darstellung(Termin $termin, Quelle $quelle, CarbonImmutable $beginn, CarbonImmutable $ende): array
    {
        return [
            'id' => $termin->id,
            'titel' => $termin->titel,
            'ort' => $termin->ort,
            'beschreibung' => $termin->beschreibung,
            'link' => $termin->link,
            'quelle' => $quelle->name,
            'farbe' => $quelle->farbe,
            'ganztaegig' => $termin->ganztaegig,
            'uhrzeit' => $termin->ganztaegig ? null : $this->uhrzeit($beginn, $ende),
            'zeitraum' => $this->zeitraum($beginn, $ende, $termin->ganztaegig),
        ];
    }

    private function uhrzeit(CarbonImmutable $beginn, CarbonImmutable $ende): string
    {
        if ($ende->equalTo($beginn)) {
            return $beginn->format('H:i');
        }

        return $beginn->format('H:i').'–'.$ende->format('H:i');
    }

    public function zeitraum(CarbonImmutable $beginn, CarbonImmutable $ende, bool $ganztaegig): string
    {
        $d = fn (CarbonImmutable $c) => $c->locale('de')->isoFormat('dd, D. MMM YYYY');

        if ($ganztaegig) {
            $letzter = $ende->subDay();

            return $letzter->equalTo($beginn) ? $d($beginn).' (ganztägig)' : $d($beginn).' – '.$d($letzter);
        }

        if ($beginn->isSameDay($ende)) {
            return $d($beginn).', '.$this->uhrzeit($beginn, $ende).' Uhr';
        }

        return $d($beginn).', '.$beginn->format('H:i').' Uhr – '.$d($ende).', '.$ende->format('H:i').' Uhr';
    }

    /**
     * Verteilt Balken so auf Zeilen, dass sie sich nicht überlappen
     * (längere zuerst, damit die Zeilen ruhig wirken).
     *
     * @param  list<array>  $balken
     * @return list<list<array>>
     */
    private function packeBalken(array $balken): array
    {
        usort($balken, fn ($a, $b) => [$a['start'], -$a['spanne'], $a['titel']] <=> [$b['start'], -$b['spanne'], $b['titel']]);

        $zeilen = [];
        $belegtBis = [];
        foreach ($balken as $b) {
            $ziel = null;
            foreach ($belegtBis as $i => $frei) {
                if ($frei <= $b['start']) {
                    $ziel = $i;
                    break;
                }
            }
            if ($ziel === null) {
                $ziel = count($zeilen);
                $zeilen[$ziel] = [];
            }
            $zeilen[$ziel][] = $b;
            $belegtBis[$ziel] = $b['start'] + $b['spanne'];
        }

        return $zeilen;
    }
}
