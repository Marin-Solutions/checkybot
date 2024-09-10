<?php

namespace App\Filament\Resources\ServerResource\Pages;

use Filament\Actions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Resources\ServerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServer extends CreateRecord
{
    protected static string $resource = ServerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['token'] = Str::random(40);
        $data['created_by'] = Auth::user()->id;
        $server = static::getModel()::create($data);

        return $server;
    }
}
