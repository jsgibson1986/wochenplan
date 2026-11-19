<?php

namespace App\Console\Commands;

use App\Models\Quelle;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Legt die Standardquellen und (optional) einen Admin an. Mehrfach
 * ausführbar: Vorhandenes wird nicht überschrieben.
 */
class EinrichtenBefehl extends Command
{
    protected $signature = 'wochenplan:einrichten
        {--admin= : E-Mail-Adresse des Admins}
        {--name= : Name des Admins}';

    protected $description = 'Standardquellen und Admin-Zugang anlegen';

    public function handle(): int
    {
        $standard = [
            ['name' => 'Fortbildungen', 'typ' => 'caldav', 'url' => 'https://caldav.ssl-brn.de/caldav/1878', 'farbe' => '#7c3aed', 'aktiv' => false],
            ['name' => 'Schulmanager', 'typ' => 'ical', 'url' => null, 'farbe' => '#2563eb', 'aktiv' => false],
            ['name' => 'Prüfungstermine', 'typ' => 'ical', 'url' => null, 'farbe' => '#ea580c', 'aktiv' => false],
            ['name' => 'Ferien', 'typ' => 'ferien', 'url' => null, 'farbe' => '#16a34a', 'aktiv' => true],
            ['name' => 'Feiertage', 'typ' => 'feiertage', 'url' => null, 'farbe' => '#dc2626', 'aktiv' => true],
        ];

        foreach ($standard as $i => $daten) {
            $quelle = Quelle::firstOrCreate(['name' => $daten['name']], $daten + ['reihenfolge' => $i + 1]);
            $this->line(($quelle->wasRecentlyCreated ? 'angelegt: ' : 'vorhanden: ').$quelle->name);
        }

        if ($email = $this->option('admin')) {
            $user = User::firstOrNew(['email' => Str::lower(trim($email))]);
            $user->name = $this->option('name') ?: ($user->name ?: Str::before($email, '@'));
            $user->ist_admin = true;
            $user->aktiv = true;
            if (! $user->exists) {
                // Login läuft passwortlos per Code; das Feld ist Pflicht.
                $user->password = Str::random(64);
            }
            $user->save();
            $this->line('Admin: '.$user->email);
        }

        return self::SUCCESS;
    }
}
