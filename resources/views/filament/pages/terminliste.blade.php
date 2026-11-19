<x-filament-panels::page>
    <div class="wp">
        <div class="wp-werkzeuge">
            <div class="wp-filter">
                <label class="wp-feld">Von
                    <input type="date" wire:model.live.debounce.500ms="von" class="wp-suche wp-datum-feld">
                </label>
                <label class="wp-feld">bis
                    <input type="date" wire:model.live.debounce.500ms="bis" class="wp-suche wp-datum-feld">
                </label>
                <label class="wp-schalter">
                    <input type="checkbox" wire:model.live="nurOffen">
                    nur noch nicht eingetragene
                </label>
                <label class="wp-schalter">
                    <input type="checkbox" wire:model.live="mitLaufenden">
                    schon laufende Termine zeigen
                </label>
            </div>
            <div class="wp-legende" role="group" aria-label="Quellen ein- und ausblenden">
                @foreach ($quellen as $q)
                    @php $aus = in_array($q->id, $this->ausgeblendet, true); @endphp
                    <button type="button" wire:click="quelleUmschalten({{ $q->id }})" class="wp-chip {{ $aus ? 'is-aus' : '' }}" style="--farbe: {{ $q->farbe }}" aria-pressed="{{ $aus ? 'false' : 'true' }}">
                        <span class="wp-punkt"></span>{{ $q->name }}
                    </button>
                @endforeach
            </div>
        </div>

        <p class="wp-liste-stand">
            {{ $liste->anzahl }} Termine im Zeitraum, davon <strong>{{ $liste->offen }} noch nicht in Untis eingetragen</strong>.
            Haken setzen, sobald ein Termin übertragen ist. Die Haken sieht das ganze Team.
            @if (! $this->mitLaufenden && $liste->laufende > 0)
                <br>{{ $liste->laufende }} {{ $liste->laufende === 1 ? 'Termin hat' : 'Termine haben' }} schon vor dem Zeitraum begonnen (z. B. lange Projekte) und {{ $liste->laufende === 1 ? 'ist' : 'sind' }} ausgeblendet.
            @endif
        </p>

        <div wire:loading.class="wp-laedt" wire:target="von, bis, nurOffen, mitLaufenden, quelleUmschalten, verschieben">
            @include('kalender.liste-tabelle', ['liste' => $liste, 'interaktiv' => true])
        </div>
    </div>
</x-filament-panels::page>
