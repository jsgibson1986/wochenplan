<?php

namespace Tests\Feature;

use App\Filament\Pages\Terminliste as TerminlisteSeite;
use App\Models\Quelle;
use App\Models\Termin;
use App\Models\User;
use App\Models\UntisVermerk;
use App\Services\Kalender\Terminliste;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TerminlisteTest extends TestCase
{
    use RefreshDatabase;

    private Quelle $schulmanager;

    private Quelle $ferien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schulmanager = Quelle::create(['name' => 'Schulmanager', 'typ' => 'ical', 'farbe' => '#2563eb']);
        $this->ferien = Quelle::create(['name' => 'Ferien', 'typ' => 'ferien', 'farbe' => '#16a34a']);
    }

    private function termin(string $titel, string $beginn, string $ende, bool $ganz = false, ?string $ort = null, ?Quelle $q = null): Termin
    {
        return Termin::create(['quelle_id' => ($q ?? $this->schulmanager)->id, 'uid' => md5($titel.$beginn), 'titel' => $titel, 'beginn' => $beginn, 'ende' => $ende, 'ganztaegig' => $ganz, 'ort' => $ort]);
    }

    private function liste(string $von = '2026-11-16', string $bis = '2026-11-29', bool $nurOffen = false): Terminliste
    {
        $tz = 'Europe/Berlin';

        return new Terminliste(CarbonImmutable::parse($von, $tz), CarbonImmutable::parse($bis, $tz), [], $nurOffen);
    }

    public function test_klassen_werden_erkannt(): void
    {
        $this->assertSame(['7e'], Terminliste::klassen('7e Medienkompetenztraining'));
        $this->assertSame(['8cII'], Terminliste::klassen('8cII Vortrag "Konsumrausch"'));
        $this->assertSame(['10a', '10b'], Terminliste::klassen('Erklärvideo: Fotosynthese (10a, 10b)'));
        $this->assertSame(['Jgst. 5–7'], Terminliste::klassen('9:45 Uhr Weihnachts-Vollversammlung 5. - 7. Jgst.'));
        $this->assertSame([], Terminliste::klassen('1. Schulforumsitzung am 3.12.'));
        $this->assertSame([], Terminliste::klassen('VV Deutsch (in 6) und Geschichte (in 8)'));
        $this->assertSame(['Jgst. 5–10'], Terminliste::klassen('1. Elternsprechtag für die Jahrgangsstufen 5 - 10'));
        $this->assertSame(['Jgst. 10'], Terminliste::klassen('Vortrag Verkehrswacht 10. Klassen'));
    }

    public function test_laufende_termine_und_doppelte_feiertage_werden_ausgeblendet(): void
    {
        $this->termin('Ethik-Portfolio (6a)', '2026-10-01 00:00', '2027-06-26 00:00', ganz: true);
        $this->termin('Buß- und Bettag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true, q: $this->ferien);
        $this->termin('Buß- und Bettag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true);
        $this->termin('Konferenz', '2026-11-19 14:00', '2026-11-19 15:00');

        $l = $this->liste();
        $this->assertSame(1, $l->laufende);
        $this->assertSame(1, $l->anzahl);
        $alle = collect($l->wochen)->pluck('tage')->flatten(1)->pluck('eintraege')->flatten(1)->pluck('titel')->all();
        $this->assertSame(['Konferenz'], $alle);

        $tz = 'Europe/Berlin';
        $mit = new Terminliste(CarbonImmutable::parse('2026-11-16', $tz), CarbonImmutable::parse('2026-11-29', $tz), [], false, true);
        $this->assertSame('Ethik-Portfolio (6a)', $mit->wochen[0]['tage'][0]['eintraege'][0]['titel']);
        $this->assertTrue($mit->wochen[0]['tage'][0]['eintraege'][0]['vorher']);
    }

    public function test_liste_gruppiert_nach_woche_und_tag_mit_ferienhinweis(): void
    {
        $this->termin('7e Medienkompetenztraining', '2026-11-16 08:45', '2026-11-16 09:30', ort: 'Klassenzimmer');
        $this->termin('Ruhewoche', '2026-11-23 00:00', '2026-11-28 00:00', ganz: true);
        $this->termin('Buß- und Bettag', '2026-11-18 00:00', '2026-11-19 00:00', ganz: true, q: $this->ferien);

        $l = $this->liste();

        $this->assertCount(2, $l->wochen);
        $this->assertSame(47, $l->wochen[0]['kw']);
        $this->assertCount(5, $l->wochen[0]['tage']); // Wochenende ohne Einträge fällt weg

        $montag = $l->wochen[0]['tage'][0];
        $this->assertSame('08:45–09:30', $montag['eintraege'][0]['zeit']);
        $this->assertSame(['7e'], $montag['eintraege'][0]['klassen']);
        $this->assertSame('Klassenzimmer', $montag['eintraege'][0]['ort']);

        $this->assertSame('Buß- und Bettag', $l->wochen[0]['tage'][2]['markierungen'][0]['titel']);
        $this->assertSame('ganztägig bis Fr 27.11.', $l->wochen[1]['tage'][0]['eintraege'][0]['zeit']);
        $this->assertSame(2, $l->anzahl);
    }

    public function test_haken_bleiben_nach_neuem_abruf_erhalten_und_filter_nur_offene(): void
    {
        $t = $this->termin('Elternabend 5a', '2026-11-17 18:00', '2026-11-17 20:00');
        $this->termin('Konferenz', '2026-11-19 14:00', '2026-11-19 15:00');

        UntisVermerk::create(['schluessel' => Terminliste::schluessel($t), 'titel' => $t->titel]);

        // Neuer Abruf: Termin wird mit neuer ID geschrieben, Schlüssel bleibt gleich
        $t->delete();
        $this->termin('Elternabend 5a', '2026-11-17 18:00', '2026-11-17 20:00');

        $l = $this->liste();
        $this->assertTrue($l->wochen[0]['tage'][1]['eintraege'][0]['erledigt']);
        $this->assertSame(1, $l->offen);

        $offen = $this->liste(nurOffen: true);
        $this->assertSame([], $offen->wochen[0]['tage'][1]['eintraege']);
        $this->assertSame('Konferenz', $offen->wochen[0]['tage'][3]['eintraege'][0]['titel']);
    }

    public function test_haken_setzen_und_entfernen_auf_der_seite(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $t = $this->termin('Wandertag 6b', '2026-11-20 00:00', '2026-11-21 00:00', ganz: true);
        $schluessel = Terminliste::schluessel($t);

        $seite = Livewire::actingAs($user)
            ->withQueryParams(['von' => '2026-11-16', 'bis' => '2026-11-22'])
            ->test(TerminlisteSeite::class)
            ->assertSee('Wandertag 6b')
            ->assertSee('6b')
            ->call('umschalten', $schluessel, 'Wandertag 6b');

        $this->assertSame($user->id, UntisVermerk::where('schluessel', $schluessel)->value('user_id'));

        $seite->call('umschalten', $schluessel, 'Wandertag 6b');
        $this->assertSame(0, UntisVermerk::count());

        $seite->call('umschalten', 'kein-gueltiger-schluessel');
        $this->assertSame(0, UntisVermerk::count());
    }

    public function test_druck_und_csv(): void
    {
        $this->termin('7e Medienkompetenztraining', '2026-11-16 08:45', '2026-11-16 09:30', ort: 'Klassenzimmer');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/liste/druck?von=2026-11-16&bis=2026-11-20')
            ->assertOk()
            ->assertSee('7e Medienkompetenztraining')
            ->assertSee('☐');

        $csv = $this->actingAs($user)->get('/liste/csv?von=2026-11-16&bis=2026-11-20');
        $csv->assertOk();
        $inhalt = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".'KW;Datum;Wochentag;Zeit;Termin', $inhalt);
        $this->assertStringContainsString('47;16.11.2026;Montag;08:45–09:30;"7e Medienkompetenztraining";7e;Klassenzimmer;Schulmanager;;nein', $inhalt);

        $this->get('/liste/csv')->assertOk(); // eingeloggt bleibt gültig
        auth()->logout();
        $this->get('/liste/csv')->assertRedirect('/login');
    }

    public function test_zeitraum_wird_begrenzt_und_vertauscht(): void
    {
        [$von, $bis] = TerminlisteSeite::zeitraum('2026-12-31', '2026-11-01');
        $this->assertSame(['2026-11-01', '2026-12-31'], [$von->format('Y-m-d'), $bis->format('Y-m-d')]);

        [$von, $bis] = TerminlisteSeite::zeitraum('2026-01-01', '2030-01-01');
        $this->assertSame(TerminlisteSeite::MAX_TAGE, (int) $von->diffInDays($bis));

        [$von] = TerminlisteSeite::zeitraum('quatsch', null);
        $this->assertTrue($von->isMonday());
    }
}
