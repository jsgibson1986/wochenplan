<?php

namespace Tests\Feature;

use App\Models\Quelle;
use App\Models\Termin;
use App\Services\Kalender\Woche;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WocheTest extends TestCase
{
    use RefreshDatabase;

    private Quelle $kalender;

    private Quelle $ferien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kalender = Quelle::create(['name' => 'Schulmanager', 'typ' => 'ical', 'farbe' => '#2563eb']);
        $this->ferien = Quelle::create(['name' => 'Ferien', 'typ' => 'ferien', 'farbe' => '#16a34a']);
    }

    private function termin(Quelle $q, string $titel, string $beginn, string $ende, bool $ganz = false, ?string $ort = null): void
    {
        Termin::create(['quelle_id' => $q->id, 'titel' => $titel, 'beginn' => $beginn, 'ende' => $ende, 'ganztaegig' => $ganz, 'ort' => $ort]);
    }

    private function woche(string $tag, bool $wochenende = false, array $aus = [], string $suche = ''): Woche
    {
        return new Woche(CarbonImmutable::parse($tag, 'Europe/Berlin'), $wochenende, $aus, $suche);
    }

    public function test_wochenschluessel_und_titel(): void
    {
        $this->assertSame('2026-W47', Woche::schluessel(Woche::ausParameter('2026-W47')));
        $this->assertSame('2026-11-16', Woche::ausParameter('2026-11-19')->format('Y-m-d'));
        $this->assertSame('2027-W01', Woche::schluessel(Woche::ausParameter('2027-01-06')));
        $this->assertSame('KW 47 · 16. – 20. November 2026', $this->woche('2026-11-18')->titel());
    }

    public function test_termine_mit_uhrzeit_landen_am_richtigen_tag_sortiert(): void
    {
        $this->termin($this->kalender, 'Konferenz', '2026-11-18 14:00', '2026-11-18 15:30', ort: 'Aula');
        $this->termin($this->kalender, 'Frühaufsicht', '2026-11-18 07:30', '2026-11-18 07:50');
        $this->termin($this->kalender, 'Nächste Woche', '2026-11-23 08:00', '2026-11-23 09:00');

        $w = $this->woche('2026-11-16');

        $this->assertSame(['Frühaufsicht', 'Konferenz'], array_column($w->tage[2]['termine'], 'titel'));
        $this->assertSame('14:00–15:30', $w->tage[2]['termine'][1]['uhrzeit']);
        $this->assertSame([], $w->balkenZeilen);
    }

    public function test_mehrtaegiger_balken_wird_am_wochenrand_abgeschnitten(): void
    {
        // Freitag bis Dienstag (Ende exklusiv Mittwoch)
        $this->termin($this->kalender, 'Fortbildung', '2026-11-20 00:00', '2026-11-25 00:00', ganz: true);
        $this->termin($this->kalender, 'Wandertag', '2026-11-17 00:00', '2026-11-18 00:00', ganz: true);

        $w = $this->woche('2026-11-16');
        $balken = collect($w->balkenZeilen)->flatten(1)->keyBy('titel');

        $this->assertSame([4, 1, false, true], [$balken['Fortbildung']['start'], $balken['Fortbildung']['spanne'], $balken['Fortbildung']['vorher'], $balken['Fortbildung']['nachher']]);
        $this->assertSame([1, 1], [$balken['Wandertag']['start'], $balken['Wandertag']['spanne']]);
        // Beide passen in eine Zeile
        $this->assertCount(1, $w->balkenZeilen);

        $folgewoche = $this->woche('2026-11-23');
        $b = $folgewoche->balkenZeilen[0][0];
        $this->assertSame([0, 2, true, false], [$b['start'], $b['spanne'], $b['vorher'], $b['nachher']]);
    }

    public function test_ueberlappende_balken_bekommen_eigene_zeilen(): void
    {
        $this->termin($this->kalender, 'A', '2026-11-16 00:00', '2026-11-19 00:00', ganz: true);
        $this->termin($this->kalender, 'B', '2026-11-18 00:00', '2026-11-20 00:00', ganz: true);

        $this->assertCount(2, $this->woche('2026-11-16')->balkenZeilen);
    }

    public function test_termin_nur_am_wochenende_erscheint_nur_mit_wochenende(): void
    {
        $this->termin($this->kalender, 'Tag der offenen Tür', '2026-11-21 00:00', '2026-11-22 00:00', ganz: true);

        $this->assertSame([], $this->woche('2026-11-16')->balkenZeilen);
        $mit = $this->woche('2026-11-16', wochenende: true);
        $this->assertSame(7, $mit->anzahlTage);
        $this->assertSame(5, $mit->balkenZeilen[0][0]['start']);
    }

    public function test_ferien_markieren_tage_und_ganze_woche(): void
    {
        $this->termin($this->ferien, 'Herbstferien', '2026-11-02 00:00', '2026-11-07 00:00', ganz: true);

        $w = $this->woche('2026-11-02');
        $this->assertTrue($w->unterrichtsfrei);
        $this->assertSame('Herbstferien', $w->tage[0]['markierungen'][0]['titel']);
        $this->assertSame([], $w->balkenZeilen);

        $teil = $this->woche('2026-11-16');
        $this->assertFalse($teil->unterrichtsfrei);

        $aus = $this->woche('2026-11-02', aus: [$this->ferien->id]);
        $this->assertFalse($aus->unterrichtsfrei);
    }

    public function test_gleichnamige_ferien_aus_dem_schulmanager_erscheinen_nicht_doppelt(): void
    {
        $this->termin($this->ferien, 'Buß- und Bettag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true);
        $this->termin($this->kalender, 'Buß- und Bettag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true);
        $this->termin($this->kalender, 'Projekttag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true);

        $titel = collect($this->woche('2026-11-16')->balkenZeilen)->flatten(1)->pluck('titel')->all();
        $this->assertSame(['Projekttag'], $titel);

        // Ist die Ferienquelle ausgeblendet, fehlt die Markierung, also bleibt der Balken.
        $ohneFerien = collect($this->woche('2026-11-16', aus: [$this->ferien->id])->balkenZeilen)->flatten(1)->pluck('titel')->all();
        $this->assertEqualsCanonicalizing(['Buß- und Bettag', 'Projekttag'], $ohneFerien);
    }

    public function test_ausblenden_und_suche(): void
    {
        $this->termin($this->kalender, 'Konferenz', '2026-11-18 14:00', '2026-11-18 15:00', ort: 'Aula');
        $this->termin($this->kalender, 'Elternabend', '2026-11-18 18:00', '2026-11-18 20:00');
        $this->termin($this->ferien, 'Buß- und Bettag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true);

        $gesucht = $this->woche('2026-11-16', suche: 'aula');
        $this->assertSame(['Konferenz'], array_column($gesucht->tage[2]['termine'], 'titel'));
        // Ferien/Feiertage bleiben bei der Suche als Kontext sichtbar
        $this->assertCount(1, $gesucht->tage[2]['markierungen']);

        $ohne = $this->woche('2026-11-16', aus: [$this->kalender->id]);
        $this->assertSame([], $ohne->tage[2]['termine']);
    }

    public function test_woche_der_zeitumstellung(): void
    {
        // 25.10.2026: Umstellung auf Winterzeit
        $this->termin($this->kalender, 'Sonntag', '2026-10-25 10:00', '2026-10-25 11:00');
        $this->termin($this->kalender, 'Freitag', '2026-10-23 10:00', '2026-10-23 11:00');

        $w = $this->woche('2026-10-21', wochenende: true);
        $this->assertSame('Freitag', $w->tage[4]['termine'][0]['titel']);
        $this->assertSame('Sonntag', $w->tage[6]['termine'][0]['titel']);
    }
}
