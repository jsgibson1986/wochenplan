<?php

namespace App\Filament\Pages;

use App\Models\UntisVermerk;
use App\Services\Kalender\IcsParser;
use App\Services\Kalender\Terminliste as Liste;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class Terminliste extends Page
{
    protected string $view = 'filament.pages.terminliste';

    protected static ?string $slug = 'liste';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Liste für Untis';

    protected static ?string $title = 'Terminliste für Untis';

    protected static ?int $navigationSort = -1;

    protected Width|string|null $maxContentWidth = Width::ScreenTwoExtraLarge;

    public const MAX_TAGE = 190;

    #[Url(as: 'von')]
    public ?string $von = null;

    #[Url(as: 'bis')]
    public ?string $bis = null;

    #[Url(as: 'offen', except: false)]
    public bool $nurOffen = false;

    #[Url(as: 'laufende', except: false)]
    public bool $mitLaufenden = false;

    /** @var list<int> */
    public array $ausgeblendet = [];

    private ?Liste $cache = null;

    /** Prüft und begrenzt den Zeitraum (Standard: diese und die nächsten 3 Wochen). */
    public static function zeitraum(?string $von, ?string $bis): array
    {
        $tz = IcsParser::ZEITZONE;
        $lies = function (?string $wert) use ($tz): ?CarbonImmutable {
            if (! $wert || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $wert, $tz)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $anfang = $lies($von) ?? CarbonImmutable::now($tz)->startOfWeek();
        $ende = $lies($bis) ?? $anfang->startOfWeek()->addWeeks(3)->endOfWeek()->startOfDay();
        if ($ende < $anfang) {
            [$anfang, $ende] = [$ende, $anfang];
        }
        if ($anfang->diffInDays($ende) > self::MAX_TAGE) {
            $ende = $anfang->addDays(self::MAX_TAGE);
        }

        return [$anfang, $ende];
    }

    public function mount(): void
    {
        [$anfang, $ende] = self::zeitraum($this->von, $this->bis);
        $this->von = $anfang->format('Y-m-d');
        $this->bis = $ende->format('Y-m-d');
        $this->ausgeblendet = array_values(array_map('intval', (array) auth()->user()->einstellung('liste_ausgeblendet', [])));
    }

    public function liste(): Liste
    {
        [$anfang, $ende] = self::zeitraum($this->von, $this->bis);

        return $this->cache ??= new Liste($anfang, $ende, $this->ausgeblendet, $this->nurOffen, $this->mitLaufenden);
    }

    public function updated(): void
    {
        $this->cache = null;
    }

    public function verschieben(int $wochen): void
    {
        [$anfang, $ende] = self::zeitraum($this->von, $this->bis);
        $this->von = $anfang->addWeeks($wochen)->format('Y-m-d');
        $this->bis = $ende->addWeeks($wochen)->format('Y-m-d');
        $this->cache = null;
    }

    public function umschalten(string $schluessel, string $titel = ''): void
    {
        if (! preg_match('/^[0-9a-f]{40}$/', $schluessel)) {
            return;
        }

        $vermerk = UntisVermerk::where('schluessel', $schluessel)->first();
        if ($vermerk) {
            $vermerk->delete();
        } else {
            UntisVermerk::create([
                'schluessel' => $schluessel,
                'titel' => mb_substr($titel, 0, 255),
                'user_id' => auth()->id(),
            ]);
        }
        $this->cache = null;
    }

    public function quelleUmschalten(int $id): void
    {
        $this->ausgeblendet = in_array($id, $this->ausgeblendet, true)
            ? array_values(array_diff($this->ausgeblendet, [$id]))
            : [...$this->ausgeblendet, $id];

        auth()->user()->setzeEinstellung('liste_ausgeblendet', $this->ausgeblendet);
        $this->cache = null;
    }

    private function parameter(): array
    {
        return array_filter([
            'von' => $this->von,
            'bis' => $this->bis,
            'offen' => $this->nurOffen ? 1 : null,
            'laufende' => $this->mitLaufenden ? 1 : null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('frueher')
                ->label('4 Wochen zurück')
                ->hiddenLabel()
                ->tooltip('4 Wochen zurück')
                ->icon(Heroicon::ChevronLeft)
                ->color('gray')
                ->action(fn () => $this->verschieben(-4)),
            Action::make('spaeter')
                ->label('4 Wochen weiter')
                ->hiddenLabel()
                ->tooltip('4 Wochen weiter')
                ->icon(Heroicon::ChevronRight)
                ->color('gray')
                ->action(fn () => $this->verschieben(4)),
            Action::make('drucken')
                ->label('Drucken')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('liste.druck', $this->parameter()), shouldOpenInNewTab: true),
            Action::make('csv')
                ->label('Excel/CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn () => route('liste.csv', $this->parameter())),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'liste' => $this->liste(),
            'quellen' => $this->liste()->quellen(),
        ];
    }
}
