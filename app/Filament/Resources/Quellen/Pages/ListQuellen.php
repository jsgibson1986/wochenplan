<?php

namespace App\Filament\Resources\Quellen\Pages;

use App\Filament\Resources\Quellen\QuelleResource;
use App\Services\Kalender\KalenderSync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListQuellen extends ListRecords
{
    protected static string $resource = QuelleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('alleAbrufen')
                ->label('Alle abrufen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (KalenderSync $sync) {
                    @set_time_limit(300);
                    $ergebnisse = $sync->alle();

                    Notification::make()
                        ->title('Abruf abgeschlossen')
                        ->body(collect($ergebnisse)->map(fn ($e, $n) => "{$n}: {$e}")->implode("\n"))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
