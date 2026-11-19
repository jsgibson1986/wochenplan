<?php

namespace App\Filament\Pages;

use App\Models\Quelle;
use App\Services\Kalender\IcsParser;
use App\Services\Kalender\KalenderSync;
use App\Services\Kalender\Woche;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class Wochenansicht extends Page
{
    protected string $view = 'filament.pages.wochenansicht';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Wochenansicht';

    protected static ?string $title = 'Wochenansicht';

    protected static ?int $navigationSort = -2;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'woche')]
    public ?string $woche = null;

    #[Url(as: 'suche', except: '')]
    public string $suche = '';

    public bool $wochenende = false;

    /** @var list<int> */
    public array $ausgeblendet = [];

    private ?Woche $cache = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->wochenende = (bool) $user->einstellung('wochenende', false);
        $this->ausgeblendet = array_values(array_map('intval', (array) $user->einstellung('ausgeblendet', [])));
        $this->woche = Woche::schluessel(Woche::ausParameter($this->woche));
    }

    public function woche(): Woche
    {
        return $this->cache ??= new Woche(
            Woche::ausParameter($this->woche),
            mitWochenende: $this->wochenende,
            ausgeblendeteQuellen: $this->ausgeblendet,
            suche: $this->suche,
        );
    }

    public function getHeading(): string|Htmlable
    {
        return $this->woche()->titel();
    }

    public function getSubheading(): ?string
    {
        $stand = Quelle::where('aktiv', true)->max('letzter_erfolg');

        return $stand
            ? 'Stand der Daten: '.CarbonImmutable::parse($stand, IcsParser::ZEITZONE)->locale('de')->diffForHumans()
            : 'Noch keine Daten abgerufen.';
    }

    public function getTitle(): string|Htmlable
    {
        return 'KW '.$this->woche()->kw();
    }

    public function blaettern(int $wochen): void
    {
        $this->woche = Woche::schluessel(Woche::ausParameter($this->woche)->addWeeks($wochen));
        $this->cache = null;
    }

    public function heute(): void
    {
        $this->woche = Woche::schluessel(CarbonImmutable::now(IcsParser::ZEITZONE));
        $this->cache = null;
    }

    public function quelleUmschalten(int $id): void
    {
        $this->ausgeblendet = in_array($id, $this->ausgeblendet, true)
            ? array_values(array_diff($this->ausgeblendet, [$id]))
            : [...$this->ausgeblendet, $id];

        auth()->user()->setzeEinstellung('ausgeblendet', $this->ausgeblendet);
        $this->cache = null;
    }

    public function updatedWochenende(): void
    {
        auth()->user()->setzeEinstellung('wochenende', $this->wochenende);
        $this->cache = null;
    }

    public function updatedSuche(): void
    {
        $this->cache = null;
    }

    public function updatedWoche(): void
    {
        $this->woche = Woche::schluessel(Woche::ausParameter($this->woche));
        $this->cache = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('zurueck')
                ->label('Vorige Woche')
                ->hiddenLabel()
                ->tooltip('Vorige Woche (←)')
                ->icon(Heroicon::ChevronLeft)
                ->color('gray')
                ->action(fn () => $this->blaettern(-1)),
            Action::make('heute')
                ->label('Heute')
                ->color('gray')
                ->action(fn () => $this->heute()),
            Action::make('vor')
                ->label('Nächste Woche')
                ->hiddenLabel()
                ->tooltip('Nächste Woche (→)')
                ->icon(Heroicon::ChevronRight)
                ->color('gray')
                ->action(fn () => $this->blaettern(1)),
            Action::make('springen')
                ->label('Datum')
                ->icon(Heroicon::OutlinedCalendar)
                ->color('gray')
                ->modalHeading('Zu einer Woche springen')
                ->modalSubmitActionLabel('Anzeigen')
                ->modalWidth(Width::Small)
                ->schema([
                    DatePicker::make('datum')
                        ->label('Datum')
                        ->displayFormat('d.m.Y')
                        ->default(fn () => $this->woche()->montag->format('Y-m-d'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->woche = Woche::schluessel(Woche::ausParameter((string) $data['datum']));
                    $this->cache = null;
                }),
            Action::make('drucken')
                ->label('Drucken')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('druck', array_filter([
                    'von' => $this->woche()->schluesselDieserWoche(),
                    'bis' => $this->woche()->schluesselDieserWoche(),
                    'suche' => $this->suche,
                ])), shouldOpenInNewTab: true),
            Action::make('aktualisieren')
                ->label('Jetzt aktualisieren')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => (bool) auth()->user()?->ist_admin)
                ->action(function (KalenderSync $sync) {
                    @set_time_limit(300);
                    $ergebnisse = $sync->alle();
                    $fehler = array_filter($ergebnisse, fn ($e) => str_starts_with($e, 'Fehler'));

                    Notification::make()
                        ->title($fehler ? 'Aktualisiert, aber mit Fehlern' : 'Kalender aktualisiert')
                        ->body(collect($ergebnisse)->map(fn ($e, $n) => "{$n}: {$e}")->implode("\n"))
                        ->{$fehler ? 'warning' : 'success'}()
                        ->send();

                    $this->cache = null;
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'w' => $this->woche(),
            'quellen' => $this->woche()->quellen(),
        ];
    }
}
