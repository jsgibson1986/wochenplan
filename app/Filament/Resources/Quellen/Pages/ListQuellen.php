<?php

namespace App\Filament\Resources\Quellen\Pages;

use App\Filament\Resources\Quellen\QuelleResource;
use App\Services\Kalender\KalenderSync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListQuellen extends ListRecords
{
    protected static string $resource = QuelleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Der Sync-Token soll die App nicht über Chat/Mail verlassen:
            // Admins kopieren die Adresse hier direkt in den KAS-Cronjob.
            Action::make('cronjob')
                ->label('Cronjob-Adresse')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->modalHeading('Adresse für den automatischen Abruf')
                ->modalDescription('Diese Adresse im KAS-Cronjob eintragen (alle 15 Minuten). Sie ist geheim: Wer sie kennt, kann einen Abruf auslösen. Bitte nicht weitergeben.')
                ->modalContent(fn () => new HtmlString(
                    '<div style="padding:0.75rem;border-radius:0.5rem;background:rgba(128,128,128,0.12);font-family:ui-monospace,monospace;font-size:0.85rem;word-break:break-all;user-select:all">'
                    .e(url('/system/sync').'?token='.config('wochenplan.sync_token'))
                    .'</div><p style="margin-top:0.5rem;font-size:0.85rem;opacity:0.75">Tipp: Einmal in das Feld klicken markiert die ganze Adresse, dann Strg+C.</p>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Schließen'),
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
