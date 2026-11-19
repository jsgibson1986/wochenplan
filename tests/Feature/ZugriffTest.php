<?php

namespace Tests\Feature;

use App\Filament\Pages\Wochenansicht;
use App\Models\Quelle;
use App\Models\Termin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ZugriffTest extends TestCase
{
    use RefreshDatabase;

    private function team(): User
    {
        return User::factory()->create(['ist_admin' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['ist_admin' => true]);
    }

    public function test_gaeste_werden_zum_login_geschickt(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/druck')->assertRedirect('/login');
        $this->get('/quellen')->assertRedirect('/login');
    }

    public function test_team_sieht_wochenansicht_aber_keine_verwaltung(): void
    {
        $quelle = Quelle::create(['name' => 'Schulmanager', 'typ' => 'ical', 'farbe' => '#2563eb', 'passwort' => 'streng-geheim']);
        Termin::create(['quelle_id' => $quelle->id, 'titel' => 'Elternsprechtag', 'beginn' => now()->startOfWeek()->setTime(15, 0), 'ende' => now()->startOfWeek()->setTime(18, 0)]);

        $this->actingAs($this->team())
            ->get('/')
            ->assertOk()
            ->assertSee('KW '.now()->isoWeek())
            ->assertSee('Elternsprechtag')
            ->assertDontSee('streng-geheim');

        $this->actingAs($this->team())->get('/quellen')->assertForbidden();
        $this->actingAs($this->team())->get('/benutzer')->assertForbidden();
    }

    public function test_admin_verwaltet_quellen_ohne_dass_passwoerter_angezeigt_werden(): void
    {
        $quelle = Quelle::create(['name' => 'Fortbildungen', 'typ' => 'caldav', 'url' => 'https://caldav.example.test/1', 'benutzername' => 'lehrer', 'passwort' => 'streng-geheim', 'farbe' => '#7c3aed']);

        $this->actingAs($this->admin())->get('/quellen')->assertOk()->assertSee('Fortbildungen');
        $this->actingAs($this->admin())
            ->get('/quellen/'.$quelle->id.'/edit')
            ->assertOk()
            ->assertDontSee('streng-geheim');
        $this->actingAs($this->admin())->get('/benutzer')->assertOk();
    }

    public function test_inaktive_benutzer_kommen_nicht_rein(): void
    {
        $this->actingAs(User::factory()->create(['aktiv' => false]))->get('/')->assertForbidden();
        $this->actingAs(User::factory()->create(['aktiv' => false]))->get('/druck')->assertForbidden();
    }

    public function test_druckansicht_mehrere_wochen(): void
    {
        $kalender = Quelle::create(['name' => 'Schulmanager', 'typ' => 'ical', 'farbe' => '#2563eb']);
        $ferien = Quelle::create(['name' => 'Feiertage', 'typ' => 'feiertage', 'farbe' => '#dc2626']);
        Termin::create(['quelle_id' => $kalender->id, 'titel' => 'Skilager 7. Jgst.', 'beginn' => '2026-11-26 00:00', 'ende' => '2026-12-02 00:00', 'ganztaegig' => true]);
        Termin::create(['quelle_id' => $ferien->id, 'titel' => 'Testfeiertag', 'beginn' => '2026-11-18 00:00', 'ende' => '2026-11-19 00:00', 'ganztaegig' => true]);

        $this->actingAs($this->team())
            ->get('/?woche=2026-W48')
            ->assertOk()
            ->assertSee('Skilager 7. Jgst.')
            ->assertSee('is-nachher', false);

        $this->actingAs($this->team())
            ->get('/druck?von=2026-W47&bis=2026-W49')
            ->assertOk()
            ->assertSee('KW 47')
            ->assertSee('KW 48')
            ->assertSee('KW 49')
            ->assertDontSee('KW 50')
            ->assertSee('Testfeiertag')
            ->assertSee('Skilager 7. Jgst.');
    }

    public function test_wochenansicht_blaettern_und_einstellungen_merken(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = $this->team();
        $quelle = Quelle::create(['name' => 'Schulmanager', 'typ' => 'ical', 'farbe' => '#2563eb']);

        Livewire::actingAs($user)
            ->withQueryParams(['woche' => '2026-W47'])
            ->test(Wochenansicht::class)
            ->assertSet('woche', '2026-W47')
            ->call('blaettern', 1)
            ->assertSet('woche', '2026-W48')
            ->call('blaettern', -2)
            ->assertSet('woche', '2026-W46')
            ->call('quelleUmschalten', $quelle->id)
            ->set('wochenende', true);

        $user->refresh();
        $this->assertSame([$quelle->id], $user->einstellung('ausgeblendet'));
        $this->assertTrue($user->einstellung('wochenende'));
    }

    public function test_sync_route_nur_mit_richtigem_token(): void
    {
        config(['wochenplan.sync_token' => 'richtig']);
        Http::fake(['openholidaysapi.org/*' => Http::response([])]);
        Quelle::create(['name' => 'Ferien', 'typ' => 'ferien', 'farbe' => '#16a34a']);

        $this->get('/system/sync')->assertNotFound();
        $this->get('/system/sync?token=falsch')->assertNotFound();
        $this->get('/system/sync?token=richtig')->assertOk()->assertSee('Ferien: 0 Termine');

        config(['wochenplan.sync_token' => '']);
        $this->get('/system/sync?token=')->assertNotFound();
    }
}
