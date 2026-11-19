@php
    $jetzt = now(\App\Services\Kalender\IcsParser::ZEITZONE)->format('d.m.Y H:i');
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terminliste für Untis {{ $liste->von->format('d.m.') }}–{{ $liste->bis->format('d.m.Y') }}</title>
    <link rel="icon" href="{{ asset('images/logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/wochenplan.css') }}?v={{ @filemtime(public_path('css/wochenplan.css')) }}">
</head>
<body class="wp-druckseite wp-druck-liste">
    <div class="wp-druck-steuerung">
        <a href="{{ url('/liste').'?'.http_build_query(request()->only('von', 'bis', 'offen', 'laufende')) }}" class="wp-knopf">← Zurück</a>
        <button type="button" class="wp-knopf wp-knopf-primaer" onclick="window.print()">Drucken</button>
        <span class="wp-druck-tipp">A4 hoch. Die Kästchen zum Abhaken auf Papier; ☑ = in der App schon als eingetragen markiert.</span>
    </div>
    <div class="wp-druck-blatt">
        <header class="wp-druck-kopf">
            <h1>Termine für Untis: {{ $liste->von->locale('de')->isoFormat('D. MMM') }} – {{ $liste->bis->locale('de')->isoFormat('D. MMM YYYY') }}{{ $liste->nurOffen ? ' (nur offene)' : '' }}</h1>
        </header>
        @include('kalender.liste-tabelle', ['liste' => $liste, 'interaktiv' => false])
        <footer class="wp-druck-fuss">{{ $liste->anzahl }} Termine, {{ $liste->offen }} offen · gedruckt am {{ $jetzt }}</footer>
    </div>
</body>
</html>
