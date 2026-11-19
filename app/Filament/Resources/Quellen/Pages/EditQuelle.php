<?php

namespace App\Filament\Resources\Quellen\Pages;

use App\Filament\Resources\Quellen\QuelleResource;
use App\Services\Kalender\KalenderSync;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditQuelle extends EditRecord
{
    protected static string $resource = QuelleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pruefen')
                ->label('Verbindung prüfen und abrufen')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function (KalenderSync $sync) {
                    @set_time_limit(300);
                    $ergebnis = $sync->quelle($this->record);
                    $fehler = str_starts_with($ergebnis, 'Fehler');

                    Notification::make()
                        ->title($fehler ? 'Abruf fehlgeschlagen' : 'Abruf erfolgreich')
                        ->body($ergebnis)
                        ->{$fehler ? 'danger' : 'success'}()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
