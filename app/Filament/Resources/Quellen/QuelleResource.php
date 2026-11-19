<?php

namespace App\Filament\Resources\Quellen;

use App\Filament\Resources\Quellen\Pages\CreateQuelle;
use App\Filament\Resources\Quellen\Pages\EditQuelle;
use App\Filament\Resources\Quellen\Pages\ListQuellen;
use App\Models\Quelle;
use App\Services\Kalender\KalenderSync;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuelleResource extends Resource
{
    protected static ?string $model = Quelle::class;

    protected static ?string $slug = 'quellen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static ?string $modelLabel = 'Quelle';

    protected static ?string $pluralModelLabel = 'Quellen';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        $mitUrl = fn (Get $get) => in_array($get('typ'), ['caldav', 'ical'], true);

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(100),
                    Select::make('typ')
                        ->options(Quelle::TYPEN)
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('url')
                        ->label('URL')
                        ->rule('regex:#^(https?|webcals?)://\S+$#i')
                        ->maxLength(2000)
                        ->visible($mitUrl)
                        ->required($mitUrl)
                        ->helperText(fn (Get $get) => $get('typ') === 'caldav'
                            ? 'CalDAV-Adresse des Kalenders, z. B. https://caldav.ssl-brn.de/caldav/1878'
                            : 'Abo-Link (iCal/ics). webcal:// wird automatisch zu https://.')
                        ->columnSpanFull(),
                    TextInput::make('benutzername')
                        ->label('Benutzername')
                        ->maxLength(255)
                        ->autocomplete('off')
                        ->visible($mitUrl)
                        ->required(fn (Get $get) => $get('typ') === 'caldav'),
                    TextInput::make('passwort')
                        ->label('Passwort')
                        ->password()
                        ->autocomplete('new-password')
                        ->visible($mitUrl)
                        // Gespeichertes Passwort wird nie angezeigt; leer
                        // lassen = unverändert.
                        ->formatStateUsing(fn () => null)
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(fn (?Quelle $record) => $record?->getRawOriginal('passwort')
                            ? 'Ist gespeichert. Leer lassen, um es nicht zu ändern.'
                            : 'Wird verschlüsselt gespeichert.'),
                    ColorPicker::make('farbe')
                        ->label('Farbe')
                        ->required()
                        ->regex('/^#[0-9a-fA-F]{6}$/'),
                    TextInput::make('reihenfolge')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                    Toggle::make('aktiv')
                        ->default(true)
                        ->helperText('Inaktive Quellen werden weder abgerufen noch angezeigt.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('reihenfolge')
            ->defaultSort('reihenfolge')
            ->columns([
                ColorColumn::make('farbe')->label(''),
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('typ')->formatStateUsing(fn (string $state) => Quelle::TYPEN[$state] ?? $state),
                IconColumn::make('aktiv')->boolean(),
                TextColumn::make('anzahl_termine')->label('Termine')->numeric(),
                TextColumn::make('letzter_erfolg')
                    ->label('Zuletzt erfolgreich')
                    ->since()
                    ->placeholder('noch nie'),
                TextColumn::make('letzter_fehler')
                    ->label('Letzter Fehler')
                    ->color('danger')
                    ->wrap()
                    ->placeholder('–'),
            ])
            ->recordActions([
                Action::make('abrufen')
                    ->label('Abrufen')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (Quelle $record, KalenderSync $sync) {
                        @set_time_limit(300);
                        $ergebnis = $sync->quelle($record);
                        $fehler = str_starts_with($ergebnis, 'Fehler');

                        Notification::make()
                            ->title($record->name.': '.$ergebnis)
                            ->{$fehler ? 'danger' : 'success'}()
                            ->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuellen::route('/'),
            'create' => CreateQuelle::route('/create'),
            'edit' => EditQuelle::route('/{record}/edit'),
        ];
    }
}
