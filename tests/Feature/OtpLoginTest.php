<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Livewire::test() geht nicht durch Filaments Panel-Routing-Middleware,
        // dadurch bleibt Filament::getCurrentPanel() sonst null und
        // ensureUserCanAccessPanel() (ruft User::canAccessPanel($panel) auf,
        // $panel ist nicht nullable) wirft einen TypeError.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_login_seite_zeigt_kein_passwortfeld(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('wire:model="data.password"', false);
    }

    public function test_login_per_email_und_code_ohne_passwort(): void
    {
        // `Notification::fake()`/`Mail::fake()` funktionieren hier nicht:
        // die Login-Seite nutzt `Notifiable` direkt auf sich selbst (keine
        // Eloquent-Instanz, kein `getKey()`), woran Laravels Fakes intern
        // scheitern. Da `MAIL_MAILER=array` in phpunit.xml gesetzt ist,
        // geht ohnehin keine echte Mail raus – der tatsächlich verschickte
        // Code wird stattdessen aus dem `MessageSent`-Event ausgelesen.
        Event::fake([MessageSent::class]);
        $user = User::factory()->create(['email' => 'lehrkraft@example.test']);

        $component = Livewire::test(Login::class)
            ->set('data.email', 'lehrkraft@example.test')
            ->call('sendOtp');

        $gesendeterCode = null;
        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use (&$gesendeterCode) {
            // quoted_printable_decode() entfernt weiche Zeilenumbrüche
            // (`=\n`), die sonst mitten in der Ziffernfolge landen können,
            // je nachdem wo genau der Mailtext umgebrochen wird.
            $body = quoted_printable_decode((string) $event->message->getBody()->toString());
            preg_match('/Anmeldecode lautet:\s*(\d+)/', $body, $matches);
            $gesendeterCode = $matches[1] ?? null;

            return true;
        });

        $this->assertNotNull($gesendeterCode);
        $this->assertGuest();

        $component->set('data.otp', $gesendeterCode)
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);

        // Nicht nur innerhalb desselben Livewire-Requests eingeloggt sein,
        // sondern die Session muss auch einen echten Folge-Request tragen
        // (das ist genau das, was im Browser nach dem Redirect passiert).
        $this->get('/')->assertOk();
    }

    public function test_login_mit_falschem_code_schlaegt_fehl(): void
    {
        Event::fake([MessageSent::class]);
        User::factory()->create(['email' => 'lehrkraft@example.test']);

        Livewire::test(Login::class)
            ->set('data.email', 'lehrkraft@example.test')
            ->call('sendOtp')
            ->set('data.otp', '000000')
            ->call('authenticate')
            ->assertHasErrors(['data.otp']);

        $this->assertGuest();
    }

    public function test_schritt_bleibt_bei_code_eingabe_ueber_folgerequests_hinweg(): void
    {
        // Der eigentliche Root-Cause hinter "nach Code-Eingabe wieder auf
        // der Login-Seite gelandet": Livewire synchronisiert zwischen
        // Requests NUR public Properties (bestätigt in
        // vendor/livewire/livewire/src/Drawer/BaseUtils.php,
        // getPublicProperties() filtert auf isPublic()). Die Paket-Klasse
        // deklariert `protected int $step`, wodurch der Fortschritt auf
        // Schritt 2 bei jedem Folge-Request verloren ging und die Seite
        // wieder das E-Mail-Formular (Schritt 1) zeigte – auch nach einem
        // *korrekten* Code, wenn zwischenzeitlich weitere Interaktionen
        // liefen. `App\Filament\Auth\Login` deklariert `step` deshalb neu
        // als `public`. Dieser Test schlägt fehl, sobald das rückgängig
        // gemacht wird oder ein Update die Sichtbarkeit wieder einfängt.
        Event::fake([MessageSent::class]);
        User::factory()->create(['email' => 'lehrkraft@example.test']);

        $component = Livewire::test(Login::class)
            ->set('data.email', 'lehrkraft@example.test')
            ->call('sendOtp')
            ->assertSet('step', 2);

        $component->set('data.otp', '000000')
            ->call('authenticate')
            ->assertHasErrors(['data.otp'])
            ->assertSet('step', 2);
    }

    public function test_code_eingabe_nutzt_kein_one_time_code_input(): void
    {
        // Live beobachtet (mit Diagnose-Logging bestätigt): Filaments
        // `OneTimeCodeInput` (6-Boxen-Optik) zeigte den eingegebenen Code
        // korrekt an, synchronisierte den Wert aber nicht zuverlässig zu
        // Livewire zurück (`$this->data['otp']` blieb `null` trotz
        // sichtbar befüllter Boxen) – vermutlich ein Bug beim
        // Einfügen/Autofill in der Alpine-Komponente. Ersetzt durch ein
        // einfaches `TextInput`. Dieser Test schlägt fehl, falls jemand
        // versehentlich wieder auf `OneTimeCodeInput` zurückwechselt.
        Event::fake([MessageSent::class]);
        User::factory()->create(['email' => 'lehrkraft@example.test']);

        Livewire::test(Login::class)
            ->set('data.email', 'lehrkraft@example.test')
            ->call('sendOtp')
            ->assertDontSee('fi-one-time-code-input-ctn', false)
            ->assertDontSee('filamentOneTimeCodeInput', false);
    }

    public function test_login_ohne_eingegebenen_code_stuerzt_nicht_ab(): void
    {
        // Live beobachtet: "Undefined array key 'otp'" (roher 500er), wenn
        // `authenticate()` aufgerufen wird, ohne dass `data.otp` jemals
        // gesetzt wurde (z. B. nach Wiederherstellung einer alten Seite
        // aus dem Browser-bfcache). Die überschriebene `verifyCode()` in
        // `App\Filament\Auth\Login` muss das als normalen
        // Validierungsfehler behandeln statt abzustürzen.
        Event::fake([MessageSent::class]);
        User::factory()->create(['email' => 'lehrkraft@example.test']);

        Livewire::test(Login::class)
            ->set('data.email', 'lehrkraft@example.test')
            ->call('sendOtp')
            ->call('authenticate')
            ->assertHasErrors(['data.otp']);

        $this->assertGuest();
    }

    /**
     * Sicherheitsaudit 28.07.2026: die Code-Eingabe (verifyCode()) hatte
     * keine eigene Versuchsbegrenzung - anders als das Anfordern/erneute
     * Senden eines Codes (sendOtp()/resendCode()), die bereits über
     * rateLimiter() begrenzt waren. Ein 6-stelliger Code war dadurch
     * theoretisch ohne Limit erratbar. Nach 5 Fehlversuchen muss der 6.
     * Versuch die Drosselungs-Meldung zeigen statt weiter normal auf
     * "ungültiger Code" zu prüfen.
     */
    public function test_code_eingabe_wird_nach_zu_vielen_fehlversuchen_gedrosselt(): void
    {
        Event::fake([MessageSent::class]);
        User::factory()->create(['email' => 'lehrkraft@example.test']);

        $component = Livewire::test(Login::class)
            ->set('data.email', 'lehrkraft@example.test')
            ->call('sendOtp');

        for ($i = 0; $i < 5; $i++) {
            $component->set('data.otp', '000000')
                ->call('authenticate')
                ->assertHasErrors(['data.otp']);
        }

        $component->set('data.otp', '000000')
            ->call('authenticate')
            ->assertHasErrors(['data.otp'])
            ->assertSee('Zu viele Versuche');

        $this->assertGuest();
    }
}
