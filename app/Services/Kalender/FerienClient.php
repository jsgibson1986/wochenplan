<?php

namespace App\Services\Kalender;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Schulferien und gesetzliche Feiertage für Bayern über die OpenHolidays
 * API (openholidaysapi.org, frei, ohne Schlüssel).
 */
class FerienClient
{
    public const BASIS_URL = 'https://openholidaysapi.org';

    public const REGION = 'DE-BY';

    /**
     * @param  'ferien'|'feiertage'  $typ
     * @return list<array{uid: string, titel: string, beschreibung: null, ort: null, link: null, beginn: CarbonImmutable, ende: CarbonImmutable, ganztaegig: bool}>
     */
    public function hole(string $typ, \DateTimeInterface $von, \DateTimeInterface $bis): array
    {
        $endpunkt = $typ === 'ferien' ? 'SchoolHolidays' : 'PublicHolidays';

        $antwort = Http::timeout(60)
            ->acceptJson()
            ->get(self::BASIS_URL.'/'.$endpunkt, [
                'countryIsoCode' => 'DE',
                'subdivisionCode' => self::REGION,
                'languageIsoCode' => 'DE',
                'validFrom' => $von->format('Y-m-d'),
                'validTo' => $bis->format('Y-m-d'),
            ]);

        if (! $antwort->successful()) {
            throw new \RuntimeException("Ferien-API antwortet mit HTTP {$antwort->status()}.");
        }

        $tz = new \DateTimeZone(IcsParser::ZEITZONE);
        $termine = [];

        foreach ((array) $antwort->json() as $eintrag) {
            $regionen = array_column($eintrag['subdivisions'] ?? [], 'code');

            // Nur landesweit gültige Tage. Lokale Feiertage wie das
            // Augsburger Friedensfest (DE-BY-AU) bleiben außen vor.
            if (! ($eintrag['nationwide'] ?? false) && ! in_array(self::REGION, $regionen, true)) {
                continue;
            }

            $name = collect($eintrag['name'] ?? [])->firstWhere('language', 'DE')['text']
                ?? ($eintrag['name'][0]['text'] ?? ($typ === 'ferien' ? 'Ferien' : 'Feiertag'));

            $termine[] = [
                'uid' => (string) ($eintrag['id'] ?? md5($name.$eintrag['startDate'])),
                'titel' => $name,
                'beschreibung' => null,
                'ort' => null,
                'link' => null,
                'beginn' => CarbonImmutable::parse($eintrag['startDate'], $tz)->startOfDay(),
                // API-Ende ist inklusiv, intern exklusiv wie in iCal.
                'ende' => CarbonImmutable::parse($eintrag['endDate'], $tz)->startOfDay()->addDay(),
                'ganztaegig' => true,
            ];
        }

        return $termine;
    }
}
