<?php

namespace App\Filament\Resources\Benutzer\Pages;

use App\Filament\Resources\Benutzer\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Login läuft passwortlos per Code; die Spalte ist aber Pflicht.
        $data['password'] = Str::random(64);

        return $data;
    }
}
