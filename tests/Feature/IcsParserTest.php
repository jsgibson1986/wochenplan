<?php

namespace Tests\Feature;

use App\Services\Kalender\IcsParser;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class IcsParserTest extends TestCase
{
    private function parse(string $events): array
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\n".$this->vtimezone().$events."END:VCALENDAR\r\n";
        $tz = 'Europe/Berlin';

        return (new IcsParser)->parse(
            $ics,
            CarbonImmutable::parse('2026-09-01', $tz),
            CarbonImmutable::parse('2027-03-01', $tz),
        );
    }

    private function vtimezone(): string
    {
        return implode("\r\n", [
            'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE',
        ])."\r\n";
    }

    public function test_serie_ueber_zeitumstellung_behaelt_lokale_uhrzeit_und_beachtet_exdate(): void
    {
        $termine = $this->parse(implode("\r\n", [
            'BEGIN:VEVENT',
            'UID:serie-1',
            'SUMMARY:Konferenz',
            'DTSTART;TZID=Europe/Berlin:20261019T140000',
            'DTEND;TZID=Europe/Berlin:20261019T153000',
            'RRULE:FREQ=WEEKLY;COUNT=4',
            'EXDATE;TZID=Europe/Berlin:20261102T140000',
            'END:VEVENT',
        ])."\r\n");

        $this->assertCount(3, $termine);
        $this->assertSame(
            ['2026-10-19 14:00', '2026-10-26 14:00', '2026-11-09 14:00'],
            array_map(fn ($t) => $t['beginn']->format('Y-m-d H:i'), $termine),
        );
        $this->assertSame('15:30', $termine[1]['ende']->format('H:i'));
        $this->assertFalse($termine[0]['ganztaegig']);
        $this->assertSame('Europe/Berlin', $termine[0]['beginn']->getTimezone()->getName());
    }

    public function test_geaenderter_einzeltermin_einer_serie_wird_verschoben(): void
    {
        $termine = $this->parse(implode("\r\n", [
            'BEGIN:VEVENT', 'UID:serie-2', 'SUMMARY:Teamsitzung',
            'DTSTART;TZID=Europe/Berlin:20261005T130000', 'DTEND;TZID=Europe/Berlin:20261005T140000',
            'RRULE:FREQ=WEEKLY;COUNT=2', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:serie-2', 'SUMMARY:Teamsitzung (verlegt)',
            'RECURRENCE-ID;TZID=Europe/Berlin:20261012T130000',
            'DTSTART;TZID=Europe/Berlin:20261013T090000', 'DTEND;TZID=Europe/Berlin:20261013T100000',
            'END:VEVENT',
        ])."\r\n");

        $this->assertCount(2, $termine);
        $verlegt = collect($termine)->firstWhere('titel', 'Teamsitzung (verlegt)');
        $this->assertSame('2026-10-13 09:00', $verlegt['beginn']->format('Y-m-d H:i'));
    }

    public function test_ganztaegiger_termin_hat_exklusives_ende(): void
    {
        $termine = $this->parse(implode("\r\n", [
            'BEGIN:VEVENT', 'UID:ganz-1', 'SUMMARY:Wandertag',
            'DTSTART;VALUE=DATE:20261123', 'DTEND;VALUE=DATE:20261124', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:ganz-2', 'SUMMARY:Ohne Ende',
            'DTSTART;VALUE=DATE:20261125', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:ganz-3', 'SUMMARY:Fortbildung',
            'DTSTART;VALUE=DATE:20261127', 'DTEND;VALUE=DATE:20261201', 'END:VEVENT',
        ])."\r\n");

        $nachTitel = collect($termine)->keyBy('titel');

        $this->assertTrue($nachTitel['Wandertag']['ganztaegig']);
        $this->assertSame('2026-11-23 00:00', $nachTitel['Wandertag']['beginn']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-24 00:00', $nachTitel['Wandertag']['ende']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-26', $nachTitel['Ohne Ende']['ende']->format('Y-m-d'));
        $this->assertSame('2026-12-01', $nachTitel['Fortbildung']['ende']->format('Y-m-d'));
    }

    public function test_termin_ohne_uid_wird_gelesen_abgesagter_nicht(): void
    {
        $termine = $this->parse(implode("\r\n", [
            'BEGIN:VEVENT', 'SUMMARY:Ohne UID', 'DTSTART:20261110T080000Z', 'DTEND:20261110T090000Z', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:abgesagt', 'SUMMARY:Fällt aus', 'STATUS:CANCELLED',
            'DTSTART:20261111T080000Z', 'DTEND:20261111T090000Z', 'END:VEVENT',
        ])."\r\n");

        $this->assertCount(1, $termine);
        $this->assertSame('Ohne UID', $termine[0]['titel']);
        // 08:00 UTC = 09:00 MEZ
        $this->assertSame('09:00', $termine[0]['beginn']->format('H:i'));
    }

    public function test_termine_ausserhalb_des_zeitfensters_fallen_weg_und_links_werden_geprueft(): void
    {
        $termine = $this->parse(implode("\r\n", [
            'BEGIN:VEVENT', 'UID:alt', 'SUMMARY:Alt', 'DTSTART:20250110T080000Z', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:link', 'SUMMARY:Mit Link', 'DTSTART:20261110T080000Z',
            'URL:javascript:alert(1)', 'LOCATION:Aula', 'DESCRIPTION:Zeile 1\nZeile 2', 'END:VEVENT',
        ])."\r\n");

        $this->assertCount(1, $termine);
        $this->assertNull($termine[0]['link']);
        $this->assertSame('Aula', $termine[0]['ort']);
        $this->assertSame("Zeile 1\nZeile 2", $termine[0]['beschreibung']);
    }
}
