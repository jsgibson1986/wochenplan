<?php

namespace App\Filament\Auth;

use Afsakar\FilamentOtpLogin\Filament\Pages\Login as BaseOtpLogin;
use Afsakar\FilamentOtpLogin\Models\OtpCode;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends BaseOtpLogin
{
    /**
     * Der Haupt-Bug: Livewire 3 synchronisiert nur PUBLIC Properties
     * zwischen Requests (bestätigt in vendor/livewire/livewire/src/Drawer/
     * BaseUtils.php, filtert auf `$property->isPublic()`). Die Paket-Klasse
     * deklariert `protected int $step = 1;` – dadurch ging der Fortschritt
     * auf Schritt 2 (Code-Eingabe) bei jedem neuen Request wieder verloren.
     * Fix: Property in der Unterklasse als `public` neu deklarieren (PHP
     * erlaubt das Erweitern der Sichtbarkeit).
     */
    public int $step = 1;

    /**
     * Überschreibt die Paket-Methode, die bei fehlendem/verlorenem Code
     * (`$this->data['otp']` nicht gesetzt) mit einem rohen 500er
     * ("Undefined array key 'otp'") abstürzt statt einer normalen
     * Validierungsfehlermeldung.
     *
     * Sicherheitsaudit 28.07.2026: das Paket begrenzt zwar, wie oft ein
     * Code angefordert/erneut gesendet werden kann (`sendOtp()`/
     * `resendCode()` rufen `rateLimiter()` auf), aber NICHT, wie oft ein
     * Code geraten werden darf - ein 6-stelliger Code (1 Mio.
     * Kombinationen) war innerhalb seiner 5-Minuten-Gültigkeit ohne jede
     * Versuchsbegrenzung erratbar. Eigenes, von Sende-Versuchen
     * unabhängiges Kontingent (`method: 'verifyCode'` sorgt für einen
     * eigenen Rate-Limit-Schlüssel statt den von sendOtp()/resendCode()
     * geteilten).
     */
    public function verifyCode(): void
    {
        try {
            $this->rateLimit(5, 60, method: 'verifyCode');
        } catch (TooManyRequestsException) {
            throw ValidationException::withMessages([
                'data.otp' => __('filament-otp-login::translations.validation.too_many_attempts'),
            ]);
        }

        $eingegebenerCode = $this->data['otp'] ?? null;

        $code = OtpCode::query()
            ->where($this->plugin()->getIdentifierColumn(), $this->identifier())
            ->first();

        if (blank($eingegebenerCode) || (! $code) || (! Hash::check($eingegebenerCode, $code->code))) {
            throw ValidationException::withMessages([
                'data.otp' => __('filament-otp-login::translations.validation.invalid_code'),
            ]);
        } elseif (! $code->isValid()) {
            throw ValidationException::withMessages([
                'data.otp' => __('filament-otp-login::translations.validation.expired_code'),
            ]);
        } else {
            $this->dispatch('codeVerified');
            $code->delete();
        }
    }

    /**
     * Live beobachtet (Diagnose-Logging bestätigte es): Filaments
     * `OneTimeCodeInput` (die 6-Boxen-Optik) zeigt die eingegebenen Ziffern
     * zwar korrekt an, synct den kombinierten Wert aber in der Praxis
     * nicht zuverlässig zurück zu Livewire (`$this->data['otp']` blieb
     * `null`, obwohl alle 6 Boxen sichtbar befüllt waren – vermutlich ein
     * Bug in der Alpine-Komponente beim Einfügen/Autofill des Codes aus
     * der E-Mail). Statt das clientseitige JS eines Fremdpakets zu
     * debuggen, wird hier bewusst ein einfaches, robustes `TextInput`
     * verwendet – funktioniert über den normalen Filament-Formular-Zustand
     * garantiert zuverlässig, auf Kosten der 6-Boxen-Optik.
     */
    protected function getOtpCodeFormComponent(): Component
    {
        return TextInput::make('otp')
            ->label(__('filament-otp-login::translations.otp_code'))
            ->numeric()
            ->minLength($this->plugin()->getOtpCodeLength())
            ->maxLength($this->plugin()->getOtpCodeLength())
            ->extraInputAttributes([
                'inputmode' => 'numeric',
                'autocomplete' => 'one-time-code',
            ])
            ->hint(new HtmlString(
                '<button type="button" wire:click="goBack()" class="focus:outline-none font-bold focus:underline hover:text-primary-400 text-primary-600 text-sm">'
                .__('filament-otp-login::translations.view.go_back')
                .'</button>'
            ))
            ->autofocus()
            ->required();
    }
}
