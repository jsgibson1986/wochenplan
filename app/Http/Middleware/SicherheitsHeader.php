<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsaudit 28.07.2026 ("gäbe es noch weitere
 * Sicherheitsvorkehrungen?"): Laravel setzt standardmäßig keinen einzigen
 * dieser Header, live per curl gegen /login bestätigt.
 *
 * Content-Security-Policy (ergänzt nach expliziter Nachfrage "sollte man
 * eine CSP angehen?") ist bewusst die ENTSPANNTE Variante, nicht die
 * strikte Nonce-basierte: die App nutzt an mehreren Stellen
 * Inline-Scripts/-Styles, die ohne erheblichen Zusatzaufwand nicht auf
 * Nonces umstellbar sind - Filaments eigenes Theme-`<style>`-Tag (Paket-
 * Code, nicht ohne Vendor-Patch anpassbar), Alpine.js' `x-data`/
 * `x-on:click`-Auswertung per `new Function(...)` (braucht
 * `'unsafe-eval'`), die Tour-Spotlight-Inline-`<script>`-Blöcke. Die
 * entspannte CSP erlaubt deshalb weiterhin `unsafe-inline`/`unsafe-eval`,
 * schränkt aber `default-src`/`object-src`/`base-uri`/`form-action`/
 * `frame-ancestors` auf die eigene Origin ein - verhindert dadurch zwar
 * nicht das Ausführen von eingeschleustem Inline-Script bei einer
 * (aktuell nicht bekannten) künftigen XSS-Lücke, wohl aber das Nachladen
 * von Schadcode und den Abfluss von Daten an eine fremde Domain.
 *
 * `img-src` erlaubt zusätzlich https://ui-avatars.com: Filaments
 * Standard-Avatar-Provider (kein eigener konfiguriert, siehe
 * AdminPanelProvider) lädt Avatar-Bilder von dort - ohne diesen Eintrag
 * blockiert die CSP das Nutzer-Avatar-Bild im Backend (live als
 * Regression nach dem CSP-Rollout gemeldet und hierüber behoben). Eine
 * sauberere, datensparsamere Lösung wäre ein selbstgehosteter
 * Avatar-Provider (keine Namensinitialen an einen Drittanbieter senden),
 * war für diesen rein kosmetischen Fund aber nicht verhältnismäßig.
 *
 * WICHTIG: Diese Middleware läuft nur für Requests, die Laravel über
 * index.php erreichen. Statische Dateien (public/build/, public/
 * bewertungs-app/) werden laut public/.htaccess bei existierender Datei
 * (`RewriteCond %{REQUEST_FILENAME} !-f`) direkt von Apache ausgeliefert,
 * OHNE dass diese Middleware je läuft - deshalb gibt es zusätzlich einen
 * eigenen `mod_headers`-Block in public/.htaccess mit denselben Werten
 * (Nachfrage "gelten diese Einstellungen auch für die PWA?", live
 * bestätigt: ohne diese Ergänzung nein).
 */
class SicherheitsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https://ui-avatars.com",
            "font-src 'self' data:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        return $response;
    }
}
