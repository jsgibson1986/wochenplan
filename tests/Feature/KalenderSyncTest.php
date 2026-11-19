<?php

namespace Tests\Feature;

use App\Models\Quelle;
use App\Models\Termin;
use App\Services\Kalender\KalenderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KalenderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    private function ics(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n".implode('', $events)."END:VCALENDAR\r\n";
    }

    private function event(string $uid, string $titel, string $start): string
    {
        return "BEGIN:VEVENT\r\nUID:{$uid}\r\nSUMMARY:{$titel}\r\nDTSTART:{$start}\r\nDTEND:{$start}\r\nEND:VEVENT\r\n";
    }

    public function test_caldav_report_wird_gesendet_und_multistatus_gelesen(): void
    {
        $multistatus = '<?xml version="1.0" encoding="UTF-8"?>
<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:response><D:href>/caldav/1878/a.ics</D:href><D:propstat><D:prop>
    <C:calendar-data>'.htmlspecialchars($this->ics($this->event('a', 'Fortbildung Mathe', '20261124T080000Z'))).'</C:calendar-data>
  </D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>
  <D:response><D:href>/caldav/1878/b.ics</D:href><D:propstat><D:prop>
    <C:calendar-data><![CDATA['.$this->ics($this->event('b', 'Fortbildung Deutsch', '20261125T080000Z')).']]></C:calendar-data>
  </D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>
</D:multistatus>';

        Http::fake(['caldav.example.test/*' => Http::response($multistatus, 207)]);

        $quelle = Quelle::create([
            'name' => 'Fortbildungen', 'typ' => 'caldav', 'url' => 'https://caldav.example.test/caldav/1878',
            'benutzername' => 'lehrer', 'passwort' => 'geheim', 'farbe' => '#7c3aed',
        ]);

        $ergebnis = app(KalenderSync::class)->quelle($quelle);

        $this->assertSame('2 Termine', $ergebnis);
        $this->assertEqualsCanonicalizing(['Fortbildung Mathe', 'Fortbildung Deutsch'], Termin::pluck('titel')->all());

        Http::assertSent(fn (Request $r) => $r->method() === 'REPORT'
            && $r->header('Depth') === ['1']
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('lehrer:geheim'))
            && str_contains($r->body(), 'time-range'));

        $quelle->refresh();
        $this->assertNull($quelle->letzter_fehler);
        $this->assertSame(2, $quelle->anzahl_termine);
        $this->assertNotSame('geheim', $quelle->getRawOriginal('passwort'));
    }

    public function test_fehler_laesst_alte_termine_stehen_und_wird_vermerkt(): void
    {
        $quelle = Quelle::create(['name' => 'Schulmanager', 'typ' => 'ical', 'url' => 'webcal://ical.example.test/k', 'farbe' => '#2563eb']);

        Http::fakeSequence('ical.example.test/*')
            ->push($this->ics($this->event('x', 'Elternabend', '20261126T170000Z')))
            ->push('Serverfehler', 500);

        $this->assertSame('1 Termine', app(KalenderSync::class)->quelle($quelle));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://ical.example.test/'));

        $ergebnis = app(KalenderSync::class)->quelle($quelle);

        $this->assertStringStartsWith('Fehler', $ergebnis);
        $this->assertSame(['Elternabend'], Termin::pluck('titel')->all());
        $this->assertStringContainsString('500', $quelle->refresh()->letzter_fehler);
    }

    public function test_caldav_anmeldefehler_wird_verstaendlich_gemeldet(): void
    {
        Http::fake(['caldav.example.test/*' => Http::response('', 401)]);
        $quelle = Quelle::create(['name' => 'F', 'typ' => 'caldav', 'url' => 'https://caldav.example.test/caldav/1', 'benutzername' => 'x', 'passwort' => 'y']);

        $this->assertStringContainsString('Benutzername und Passwort', app(KalenderSync::class)->quelle($quelle));
    }

    public function test_ferien_und_feiertage_nur_landesweit_bayern(): void
    {
        Http::fake(['openholidaysapi.org/PublicHolidays*' => Http::response([
            ['id' => '1', 'startDate' => '2026-12-25', 'endDate' => '2026-12-25', 'nationwide' => true, 'name' => [['language' => 'DE', 'text' => '1. Weihnachtsfeiertag']]],
            ['id' => '2', 'startDate' => '2027-01-06', 'endDate' => '2027-01-06', 'nationwide' => false, 'subdivisions' => [['code' => 'DE-BY']], 'name' => [['language' => 'DE', 'text' => 'Heilige Drei Könige']]],
            ['id' => '3', 'startDate' => '2027-08-08', 'endDate' => '2027-08-08', 'nationwide' => false, 'subdivisions' => [['code' => 'DE-BY-AU']], 'name' => [['language' => 'DE', 'text' => 'Friedensfest']]],
        ])]);

        $quelle = Quelle::create(['name' => 'Feiertage', 'typ' => 'feiertage', 'farbe' => '#dc2626']);
        app(KalenderSync::class)->quelle($quelle);

        $this->assertEqualsCanonicalizing(['1. Weihnachtsfeiertag', 'Heilige Drei Könige'], Termin::pluck('titel')->all());
        $tag = Termin::where('titel', 'Heilige Drei Könige')->first();
        $this->assertTrue($tag->ganztaegig);
        $this->assertSame('2027-01-07 00:00', $tag->ende->format('Y-m-d H:i'));
    }
}
