<?php

namespace App\Services\Kalender;

use App\Models\Quelle;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Liest Termine aus einem CalDAV-Kalender (z. B. Open-Xchange unter
 * caldav.ssl-brn.de) per REPORT calendar-query mit Zeitfenster.
 */
class CalDavClient
{
    /**
     * @return list<string> ein iCal-Text (VCALENDAR) pro Kalenderobjekt
     */
    public function holeObjekte(Quelle $quelle, \DateTimeInterface $von, \DateTimeInterface $bis): array
    {
        $url = trim((string) $quelle->url);
        if ($url === '') {
            throw new \RuntimeException('Keine CalDAV-URL hinterlegt.');
        }

        $antwort = $this->report($quelle, $url, $von, $bis);

        // Collections enden üblicherweise auf "/", manche Server sind da
        // streng. Einmal mit bzw. ohne Schrägstrich nachversuchen.
        if (in_array($antwort->status(), [301, 302, 404, 405], true)) {
            $alternativ = str_ends_with($url, '/') ? rtrim($url, '/') : $url.'/';
            $zweite = $this->report($quelle, $alternativ, $von, $bis);
            if ($zweite->status() === 207) {
                $antwort = $zweite;
            }
        }

        if ($antwort->status() === 401 || $antwort->status() === 403) {
            throw new \RuntimeException("Anmeldung abgelehnt (HTTP {$antwort->status()}). Benutzername und Passwort prüfen.");
        }

        if ($antwort->status() !== 207) {
            // Server ohne REPORT-Unterstützung: kompletten Kalender per GET.
            $export = $this->anfrage($quelle)->get($url);
            if ($export->successful() && str_contains($export->body(), 'BEGIN:VCALENDAR')) {
                return [$export->body()];
            }

            throw new \RuntimeException("CalDAV-Server antwortet mit HTTP {$antwort->status()}.");
        }

        return $this->kalenderdatenAus($antwort->body());
    }

    /**
     * @return list<string>
     */
    public function kalenderdatenAus(string $multistatusXml): array
    {
        $dom = new \DOMDocument;
        $vorher = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($multistatusXml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($vorher);

        if (! $ok) {
            throw new \RuntimeException('CalDAV-Antwort ist kein gültiges XML.');
        }

        $objekte = [];
        foreach ($dom->getElementsByTagNameNS('urn:ietf:params:xml:ns:caldav', 'calendar-data') as $knoten) {
            $ics = trim($knoten->textContent);
            if ($ics !== '') {
                $objekte[] = $ics;
            }
        }

        return $objekte;
    }

    private function report(Quelle $quelle, string $url, \DateTimeInterface $von, \DateTimeInterface $bis): Response
    {
        $start = $this->utc($von);
        $ende = $this->utc($bis);

        $xml = <<<XML
            <?xml version="1.0" encoding="utf-8" ?>
            <C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
              <D:prop>
                <D:getetag/>
                <C:calendar-data/>
              </D:prop>
              <C:filter>
                <C:comp-filter name="VCALENDAR">
                  <C:comp-filter name="VEVENT">
                    <C:time-range start="{$start}" end="{$ende}"/>
                  </C:comp-filter>
                </C:comp-filter>
              </C:filter>
            </C:calendar-query>
            XML;

        return $this->anfrage($quelle)
            ->withHeaders(['Depth' => '1'])
            ->withBody($xml, 'application/xml; charset=utf-8')
            ->send('REPORT', $url);
    }

    private function anfrage(Quelle $quelle)
    {
        $anfrage = Http::timeout(120)->withoutRedirecting()->withUserAgent('Wochenplan-Kalender/1.0');

        if (filled($quelle->benutzername)) {
            $anfrage = $anfrage->withBasicAuth((string) $quelle->benutzername, (string) $quelle->passwort);
        }

        return $anfrage;
    }

    private function utc(\DateTimeInterface $zeit): string
    {
        return (new \DateTimeImmutable('@'.$zeit->getTimestamp()))->format('Ymd\THis\Z');
    }
}
