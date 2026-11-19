<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'ist_admin', 'aktiv', 'einstellungen'];

    protected $hidden = ['password', 'remember_token'];

    /** Wie die DB-Standardwerte, damit sie auch vor dem Neuladen gelten. */
    protected $attributes = [
        'ist_admin' => false,
        'aktiv' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ist_admin' => 'boolean',
            'aktiv' => 'boolean',
            'einstellungen' => 'array',
        ];
    }

    /**
     * Ohne FilamentUser lässt Filaments Authenticate-Middleware in
     * Produktion niemanden ins Panel (Stolperstein aus der
     * Prüfungsformate-App).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->aktiv;
    }

    public function einstellung(string $schluessel, mixed $standard = null): mixed
    {
        return data_get($this->einstellungen ?? [], $schluessel, $standard);
    }

    public function setzeEinstellung(string $schluessel, mixed $wert): void
    {
        $einstellungen = $this->einstellungen ?? [];
        data_set($einstellungen, $schluessel, $wert);
        $this->einstellungen = $einstellungen;
        $this->save();
    }
}
