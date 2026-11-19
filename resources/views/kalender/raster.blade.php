{{--
    Wochenraster für Bildschirm und Druck.
    $w: App\Services\Kalender\Woche, $interaktiv: Klick öffnet Details (Alpine `detail`).
--}}
@php
    $element = $interaktiv ? 'button' : 'div';
    $zeilen = count($w->balkenZeilen);
    $alleBalken = collect($w->balkenZeilen)->flatten(1);
    $freiNamen = collect($w->tage)->pluck('markierungen')->flatten(1)->pluck('titel')->unique()->implode(', ');
@endphp

<div class="wp-kalender {{ $w->unterrichtsfrei ? 'is-unterrichtsfrei' : '' }}">
    @if ($w->unterrichtsfrei)
        <div class="wp-hinweis-frei">Unterrichtsfreie Woche: {{ $freiNamen }}</div>
    @endif

    {{-- Spaltenansicht (Desktop, Tablet, Druck) --}}
    <div class="wp-raster" style="--tage: {{ $w->anzahlTage }}; grid-template-rows: auto repeat({{ $zeilen }}, auto) 1fr;">
        @foreach ($w->tage as $i => $tag)
            @php $farbe = $tag['markierungen'][0]['farbe'] ?? null; @endphp
            <div
                class="wp-spalte {{ $farbe ? 'is-frei' : '' }} {{ $tag['heute'] ? 'is-heute' : '' }} {{ $tag['datum']->isWeekend() ? 'is-wochenende' : '' }}"
                style="grid-column: {{ $i + 1 }}; grid-row: 1 / -1; {{ $farbe ? '--farbe: '.$farbe.';' : '' }}"
            ></div>
            <div class="wp-kopf" style="grid-column: {{ $i + 1 }}; grid-row: 1;">
                <div class="wp-wochentag">{{ $tag['datum']->locale('de')->isoFormat('dddd') }}</div>
                <div class="wp-datum">{{ $tag['datum']->isoFormat('D.M.') }}@if ($tag['heute'])<span class="wp-heute">heute</span>@endif</div>
                @foreach ($tag['markierungen'] as $m)
                    <div class="wp-markierung" style="--farbe: {{ $m['farbe'] }}">{{ $m['titel'] }}</div>
                @endforeach
            </div>
        @endforeach

        @foreach ($w->balkenZeilen as $z => $zeile)
            @foreach ($zeile as $b)
                <{{ $element }}
                    @if ($interaktiv) type="button" x-on:click="detail = @js($b)" @endif
                    class="wp-balken {{ $b['vorher'] ? 'is-vorher' : '' }} {{ $b['nachher'] ? 'is-nachher' : '' }}"
                    style="grid-column: {{ $b['start'] + 1 }} / span {{ $b['spanne'] }}; grid-row: {{ $z + 2 }}; --farbe: {{ $b['farbe'] }};"
                    title="{{ $b['titel'] }} · {{ $b['zeitraum'] }}"
                >
                    @if ($b['vorher'])<span class="wp-pfeil" aria-hidden="true">◂</span>@endif
                    <span class="wp-titel">{{ $b['titel'] }}</span>
                    @if (! $b['ganztaegig'])<span class="wp-zeit">{{ $b['uhrzeit'] }}</span>@endif
                    @if ($b['nachher'])<span class="wp-pfeil" aria-hidden="true">▸</span>@endif
                </{{ $element }}>
            @endforeach
        @endforeach

        @foreach ($w->tage as $i => $tag)
            <div class="wp-tag" style="grid-column: {{ $i + 1 }}; grid-row: {{ $zeilen + 2 }};">
                @foreach ($tag['termine'] as $t)
                    <{{ $element }}
                        @if ($interaktiv) type="button" x-on:click="detail = @js($t)" @endif
                        class="wp-termin"
                        style="--farbe: {{ $t['farbe'] }};"
                        title="{{ $t['titel'] }}"
                    >
                        <span class="wp-zeit">{{ $t['uhrzeit'] }}</span>
                        <span class="wp-titel">{{ $t['titel'] }}</span>
                        @if ($t['ort'])<span class="wp-ort">{{ $t['ort'] }}</span>@endif
                    </{{ $element }}>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Listenansicht (Handy) --}}
    <div class="wp-liste">
        @foreach ($w->tage as $i => $tag)
            @php
                $ganztags = $alleBalken->filter(fn ($b) => $b['start'] <= $i && $i < $b['start'] + $b['spanne']);
                $leer = $ganztags->isEmpty() && $tag['termine'] === [] && $tag['markierungen'] === [];
                $farbe = $tag['markierungen'][0]['farbe'] ?? null;
            @endphp
            <section class="wp-listentag {{ $tag['heute'] ? 'is-heute' : '' }} {{ $farbe ? 'is-frei' : '' }}" @if ($farbe) style="--farbe: {{ $farbe }}" @endif>
                <h3 class="wp-listentag-kopf">
                    {{ $tag['datum']->locale('de')->isoFormat('dddd, D. MMMM') }}
                    @if ($tag['heute'])<span class="wp-heute">heute</span>@endif
                </h3>
                @foreach ($tag['markierungen'] as $m)
                    <div class="wp-markierung" style="--farbe: {{ $m['farbe'] }}">{{ $m['titel'] }}</div>
                @endforeach
                @foreach ($ganztags as $b)
                    <{{ $element }} @if ($interaktiv) type="button" x-on:click="detail = @js($b)" @endif class="wp-termin is-ganztags" style="--farbe: {{ $b['farbe'] }};">
                        <span class="wp-zeit">{{ $b['ganztaegig'] ? 'ganztägig' : $b['uhrzeit'] }}</span>
                        <span class="wp-titel">{{ $b['titel'] }}</span>
                    </{{ $element }}>
                @endforeach
                @foreach ($tag['termine'] as $t)
                    <{{ $element }} @if ($interaktiv) type="button" x-on:click="detail = @js($t)" @endif class="wp-termin" style="--farbe: {{ $t['farbe'] }};">
                        <span class="wp-zeit">{{ $t['uhrzeit'] }}</span>
                        <span class="wp-titel">{{ $t['titel'] }}</span>
                        @if ($t['ort'])<span class="wp-ort">{{ $t['ort'] }}</span>@endif
                    </{{ $element }}>
                @endforeach
                @if ($leer)
                    <p class="wp-leer">Keine Termine</p>
                @endif
            </section>
        @endforeach
    </div>
</div>
