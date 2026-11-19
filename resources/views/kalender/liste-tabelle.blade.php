{{--
    Terminliste für Untis (Bildschirm und Druck).
    $liste: App\Services\Kalender\Terminliste, $interaktiv: Haken anklickbar.
--}}
@forelse ($liste->wochen as $woche)
    <section class="wp-lw">
        <h2 class="wp-lw-titel">{{ $woche['titel'] }}</h2>
        <table class="wp-lt">
            <thead>
                <tr>
                    <th class="wp-lt-haken" title="In Untis eingetragen">✓</th>
                    <th class="wp-lt-zeit">Zeit</th>
                    <th>Termin</th>
                    <th class="wp-lt-klassen">Klassen</th>
                    <th class="wp-lt-ort">Ort</th>
                    <th class="wp-lt-quelle">Quelle</th>
                </tr>
            </thead>
            @foreach ($woche['tage'] as $tag)
                <tbody class="wp-lt-tag {{ $tag['markierungen'] ? 'is-frei' : '' }}">
                    <tr class="wp-lt-tagkopf">
                        <th colspan="6">
                            {{ $tag['datum']->locale('de')->isoFormat('dddd, D. MMMM') }}
                            @foreach ($tag['markierungen'] as $m)
                                <span class="wp-markierung" style="--farbe: {{ $m['farbe'] }}">{{ $m['titel'] }}</span>
                            @endforeach
                        </th>
                    </tr>
                    @forelse ($tag['eintraege'] as $e)
                        <tr class="wp-lt-zeile {{ $e['erledigt'] ? 'is-erledigt' : '' }}" style="--farbe: {{ $e['farbe'] }}" wire:key="e-{{ $e['schluessel'] }}">
                            <td class="wp-lt-haken">
                                @if ($interaktiv)
                                    <input
                                        type="checkbox"
                                        @checked($e['erledigt'])
                                        wire:click="umschalten('{{ $e['schluessel'] }}', @js($e['titel']))"
                                        aria-label="In Untis eingetragen: {{ $e['titel'] }}"
                                        title="{{ $e['erledigt'] ? 'Eingetragen'.($e['erledigtVon'] ? ' von '.$e['erledigtVon'] : '').($e['erledigtAm'] ? ' am '.$e['erledigtAm'] : '') : 'Als in Untis eingetragen markieren' }}"
                                    >
                                @else
                                    <span class="wp-kaestchen">{{ $e['erledigt'] ? '☑' : '☐' }}</span>
                                @endif
                            </td>
                            <td class="wp-lt-zeit">{{ $e['zeit'] }}</td>
                            <td class="wp-lt-termin">
                                <span class="wp-lt-titel">@if ($e['vorher'])<span title="Beginnt vor dem gewählten Zeitraum">◂ </span>@endif{{ $e['titel'] }}</span>
                                @if ($e['beschreibung'])
                                    <span class="wp-lt-beschreibung">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $e['beschreibung']), 160) }}</span>
                                @endif
                            </td>
                            <td class="wp-lt-klassen">{{ implode(', ', $e['klassen']) }}</td>
                            <td class="wp-lt-ort">{{ $e['ort'] }}</td>
                            <td class="wp-lt-quelle"><span class="wp-punkt"></span>{{ $e['quelle'] }}</td>
                        </tr>
                    @empty
                        <tr class="wp-lt-leer"><td></td><td colspan="5">{{ $tag['markierungen'] ? 'unterrichtsfrei' : 'keine Termine' }}</td></tr>
                    @endforelse
                </tbody>
            @endforeach
        </table>
    </section>
@empty
    <p class="wp-leer">Keine Tage im gewählten Zeitraum.</p>
@endforelse
