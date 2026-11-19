<?php

namespace App\Filament\Resources\Benutzer;

use App\Filament\Resources\Benutzer\Pages\CreateUser;
use App\Filament\Resources\Benutzer\Pages\EditUser;
use App\Filament\Resources\Benutzer\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'benutzer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        $istSelbst = fn (?User $record) => $record?->is(auth()->user()) ?? false;

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->description('Die Anmeldung läuft ohne Passwort: Beim Login wird ein Code an die E-Mail-Adresse geschickt.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('E-Mail-Adresse')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (string $state) => mb_strtolower(trim($state))),
                    Toggle::make('aktiv')
                        ->default(true)
                        ->disabled($istSelbst)
                        ->helperText('Inaktive Benutzer können sich nicht anmelden.'),
                    Toggle::make('ist_admin')
                        ->label('Admin')
                        ->disabled($istSelbst)
                        ->helperText('Darf Quellen und Benutzer verwalten.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                IconColumn::make('ist_admin')->label('Admin')->boolean(),
                IconColumn::make('aktiv')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
