@php
    $ausgeblendet = $wochen[0]->ausgeblendeteQuellen ?? [];
    $legende = \App\Models\Quelle::where('aktiv', true)->whereNotIn('id', $ausgeblendet)->orderBy('reihenfolge')->orderBy('name')->get();
    $standText = $stand ? \Carbon\CarbonImmutable::parse($stand, $zeitzone)->format('d.m.Y H:i') : 'unbekannt';
    $jetzt = now($zeitzone)->format('d.m.Y H:i');
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wochenplan {{ count($wochen) === 1 ? $wochen[0]->titel() : $von.' bis '.$bis }}</title>
    <link rel="icon" href="{{ asset('images/logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/wochenplan.css') }}?v={{ @filemtime(public_path('css/wochenplan.css')) }}">
</head>
<body class="wp-druckseite">
    <form class="wp-druck-steuerung" method="get" action="{{ route('druck') }}">
        <a href="{{ url('/').'?woche='.$von }}" class="wp-knopf">← Zurück</a>
        <label>Von <input type="week" name="von" value="{{ $von }}" required></label>
        <label>bis <input type="week" name="bis" value="{{ $bis }}" required></label>
        @if ($suche !== '')
            <input type="hidden" name="suche" value="{{ $suche }}">
        @endif
        <button type="submit" class="wp-knopf">Anzeigen</button>
        <button type="button" class="wp-knopf wp-knopf-primaer" onclick="window.print()">Drucken</button>
        <span class="wp-druck-tipp">
            Eine Woche pro Seite, A4 quer. Ausgeblendete Quellen und die Wochenend-Einstellung werden aus der Wochenansicht übernommen{{ $suche !== '' ? ', Suche: „'.$suche.'“' : '' }}.
        </span>
    </form>

    @foreach ($wochen as $w)
        <section class="wp-druck-woche">
            <header class="wp-druck-kopf">
                <h1>{{ $w->titel() }}</h1>
                <div class="wp-legende">
                    @foreach ($legende as $q)
                        <span class="wp-chip" style="--farbe: {{ $q->farbe }}"><span class="wp-punkt"></span>{{ $q->name }}</span>
                    @endforeach
                </div>
            </header>
            @include('kalender.raster', ['w' => $w, 'interaktiv' => false])
            <footer class="wp-druck-fuss">Datenstand: {{ $standText }} · gedruckt am {{ $jetzt }}</footer>
        </section>
    @endforeach
</body>
</html>
