<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quelle extends Model
{
    public const TYPEN = [
        'caldav' => 'CalDAV-Kalender (mit Login)',
        'ical' => 'iCal-Abo (URL)',
        'ferien' => 'Schulferien Bayern (automatisch)',
        'feiertage' => 'Feiertage Bayern (automatisch)',
    ];

    protected $table = 'quellen';

    protected $fillable = [
        'name', 'typ', 'url', 'benutzername', 'passwort', 'farbe', 'aktiv', 'reihenfolge',
        'letzter_abruf', 'letzter_erfolg', 'letzter_fehler', 'anzahl_termine',
    ];

    protected $hidden = ['passwort'];

    protected function casts(): array
    {
        return [
            'passwort' => 'encrypted',
            'aktiv' => 'boolean',
            'letzter_abruf' => 'datetime',
            'letzter_erfolg' => 'datetime',
        ];
    }

    public function termine(): HasMany
    {
        return $this->hasMany(Termin::class);
    }

    /** Ferien/Feiertage färben ganze Tage, statt als Termin zu erscheinen. */
    public function istTagesmarkierung(): bool
    {
        return in_array($this->typ, ['ferien', 'feiertage'], true);
    }
}
