<?php

namespace App\Services\Kalender;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Settings;

/**
 * Wandelt iCal-Daten in einzelne, bereits aufgelöste Termin-Vorkommen um
 * (Wiederholungen per RRULE/RDATE/EXDATE, geänderte Einzeltermine per
 * RECURRENCE-ID, Zeitzonen). Ergebnis in lokaler Zeit Europe/Berlin;
 * ganztägige Termine haben wie in iCal ein exklusives Ende.
 */
class IcsParser
{
    public const ZEITZONE = 'Europe/Berlin';

    /**
     * @return list<array{uid: ?string, titel: string, beschreibung: ?string, ort: ?string, link: ?string, beginn: CarbonImmutable, ende: CarbonImmutable, ganztaegig: bool}>
     */
    public function parse(string $ics, \DateTimeInterface $von, \DateTimeInterface $bis): array
    {
        // Standard 3500 reicht bei täglichen Serien, die vor vielen Jahren
        // begonnen haben, nicht (fastForward zählt jedes Vorkommen mit).
        Settings::$maxRecurrences = 50000;

        $tz = new DateTimeZone(self::ZEITZONE);

        $kalender = Reader::read($ics, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        if (! $kalender instanceof VCalendar) {
            throw new \RuntimeException('Keine gültigen iCal-Daten (VCALENDAR fehlt).');
        }

        // expand() verlangt bei Serien eine UID.
        foreach ($kalender->select('VEVENT') as $ereignis) {
            if (trim((string) ($ereignis->UID ?? '')) === '') {
                $ereignis->UID = 'ohne-uid-'.md5($ereignis->serialize());
            }
        }

        $aufgeloest = $kalender->expand($von, $bis, $tz);

        $termine = [];
        foreach ($aufgeloest->select('VEVENT') as $ereignis) {
            if (strtoupper((string) ($ereignis->STATUS ?? '')) === 'CANCELLED') {
                continue;
            }
            if (! isset($ereignis->DTSTART) || ! $ereignis->DTSTART instanceof DateTimeProperty) {
                continue;
            }

            $ganztaegig = ! $ereignis->DTSTART->hasTime();
            $beginn = $this->zeitpunkt($ereignis->DTSTART, $tz);

            if (isset($ereignis->DTEND) && $ereignis->DTEND instanceof DateTimeProperty) {
                $ende = $this->zeitpunkt($ereignis->DTEND, $tz);
            } elseif (isset($ereignis->DURATION)) {
                $ende = $beginn->add($ereignis->DURATION->getDateInterval());
            } else {
                $ende = $ganztaegig ? $beginn->addDay() : $beginn;
            }

            if ($ganztaegig) {
                $beginn = $beginn->startOfDay();
                $ende = $ende->startOfDay();
                if ($ende <= $beginn) {
                    $ende = $beginn->addDay();
                }
            } elseif ($ende < $beginn) {
                $ende = $beginn;
            }

            $termine[] = [
                'uid' => $this->text($ereignis->UID ?? null, 255),
                'titel' => $this->text($ereignis->SUMMARY ?? null, 255) ?? '(ohne Titel)',
                'beschreibung' => $this->text($ereignis->DESCRIPTION ?? null, 5000),
                'ort' => $this->text($ereignis->LOCATION ?? null, 255),
                'link' => $this->link($ereignis->URL ?? null),
                'beginn' => $beginn,
                'ende' => $ende,
                'ganztaegig' => $ganztaegig,
            ];
        }

        return $termine;
    }

    /**
     * Mehrere iCal-Objekte (z. B. CalDAV: ein VCALENDAR pro Termin). Ein
     * einzelnes kaputtes Objekt bricht nicht den ganzen Abruf ab.
     *
     * @param  iterable<string>  $objekte
     */
    public function parseMehrere(iterable $objekte, \DateTimeInterface $von, \DateTimeInterface $bis): array
    {
        $termine = [];
        $fehler = 0;
        foreach ($objekte as $ics) {
            try {
                array_push($termine, ...$this->parse($ics, $von, $bis));
            } catch (\Throwable $e) {
                $fehler++;
                Log::warning('iCal-Objekt übersprungen: '.$e->getMessage());
            }
        }

        if ($fehler > 0 && $termine === []) {
            throw new \RuntimeException("Keines der {$fehler} iCal-Objekte konnte gelesen werden.");
        }

        return $termine;
    }

    private function zeitpunkt(DateTimeProperty $eigenschaft, DateTimeZone $tz): CarbonImmutable
    {
        return CarbonImmutable::instance($eigenschaft->getDateTime($tz))->setTimezone($tz);
    }

    private function text(mixed $wert, int $max): ?string
    {
        if ($wert === null) {
            return null;
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $wert));

        return $text === '' ? null : Str::limit($text, $max, '…');
    }

    private function link(mixed $wert): ?string
    {
        $url = trim((string) ($wert ?? ''));

        return preg_match('#^https?://#i', $url) ? Str::limit($url, 2000, '') : null;
    }
}
