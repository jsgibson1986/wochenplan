<x-filament-panels::page>
    <div
        class="wp"
        x-data="{ detail: null }"
        x-on:keydown.window="
            if (detail && $event.key === 'Escape') { detail = null; return; }
            const ziel = $event.target;
            if (detail || ['INPUT', 'TEXTAREA', 'SELECT'].includes(ziel.tagName) || ziel.isContentEditable) return;
            if ($event.key === 'ArrowLeft') $wire.blaettern(-1);
            if ($event.key === 'ArrowRight') $wire.blaettern(1);
        "
    >
        <div class="wp-werkzeuge">
            <div class="wp-legende" role="group" aria-label="Quellen ein- und ausblenden">
                @foreach ($quellen as $q)
                    @php $aus = in_array($q->id, $this->ausgeblendet, true); @endphp
                    <button
                        type="button"
                        wire:click="quelleUmschalten({{ $q->id }})"
                        class="wp-chip {{ $aus ? 'is-aus' : '' }}"
                        style="--farbe: {{ $q->farbe }}"
                        aria-pressed="{{ $aus ? 'false' : 'true' }}"
                        title="{{ $q->letzter_fehler ? 'Letzter Abruf fehlgeschlagen: '.$q->letzter_fehler : ($aus ? 'Einblenden' : 'Ausblenden') }}"
                    >
                        <span class="wp-punkt"></span>
                        {{ $q->name }}
                        @if ($q->letzter_fehler)
                            <span class="wp-chip-warnung" aria-label="Abruf fehlgeschlagen">!</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="wp-filter">
                <input
                    type="search"
                    wire:model.live.debounce.400ms="suche"
                    placeholder="Termine durchsuchen …"
                    aria-label="Termine durchsuchen"
                    class="wp-suche"
                >
                <label class="wp-schalter">
                    <input type="checkbox" wire:model.live="wochenende">
                    Wochenende
                </label>
            </div>
        </div>

        @if (auth()->user()->ist_admin)
            @foreach ($quellen->filter(fn ($q) => $q->letzter_fehler) as $q)
                <div class="wp-meldung">
                    <strong>{{ $q->name }}:</strong> {{ $q->letzter_fehler }}
                    <a href="{{ \App\Filament\Resources\Quellen\QuelleResource::getUrl('edit', ['record' => $q]) }}">Quelle bearbeiten</a>
                </div>
            @endforeach
        @endif

        <div wire:loading.class="wp-laedt" wire:target="blaettern, heute, quelleUmschalten, wochenende, suche, woche">
            @include('kalender.raster', ['w' => $w, 'interaktiv' => true])
        </div>

        <div class="wp-modal" x-show="detail" x-cloak x-transition.opacity x-on:click.self="detail = null">
            <div class="wp-modal-box" role="dialog" aria-modal="true" aria-labelledby="wp-modal-titel">
                <div class="wp-modal-quelle" x-bind:style="'--farbe:' + (detail?.farbe ?? '#888')">
                    <span class="wp-punkt"></span><span x-text="detail?.quelle"></span>
                </div>
                <h2 id="wp-modal-titel" class="wp-modal-titel" x-text="detail?.titel"></h2>
                <p class="wp-modal-zeit" x-text="detail?.zeitraum"></p>
                <p class="wp-modal-ort" x-show="detail?.ort"><span>Ort:</span> <span x-text="detail?.ort"></span></p>
                <div class="wp-modal-text" x-show="detail?.beschreibung" x-text="detail?.beschreibung"></div>
                <div class="wp-modal-aktionen">
                    <a x-show="detail?.link" x-bind:href="detail?.link" target="_blank" rel="noopener noreferrer" class="wp-knopf">Link öffnen</a>
                    <button type="button" class="wp-knopf wp-knopf-primaer" x-on:click="detail = null">Schließen</button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
