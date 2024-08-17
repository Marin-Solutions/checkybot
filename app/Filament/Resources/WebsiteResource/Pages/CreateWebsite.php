<?php

namespace App\Filament\Resources\WebsiteResource\Pages;


use Filament\Actions;
use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\WebsiteResource;
use GuzzleHttp\Exception\RequestException;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->previousUrl;
    }


    protected  function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $data['created_by'] =$user->id;
        return $data;

    }

    protected function beforeCreate()
    {
        $url=$this->data['url'];
        $urlExistsInDB = Website::whereUrl($url)->count();
        $urlCheckExists = Website::checkWebsiteExists($url);
        $urlResponseCode = Website::checkResponseCode($url);
        $responseStatus = false;

        if( $urlResponseCode['code'] != 200 ) {
            $responseStatus = true;
            if($urlResponseCode['code'] == 60){
                $title = 'URL website, problem with certificate';
                $body = $urlResponseCode['body'];
            }else if($urlResponseCode['body']==1){
                $title ='URL Website Response error';
                $body ='The website response is not 200!';
            }else{
                $title = 'URL website a unknown error';
                $body = 'code errno:'. $urlResponseCode;
                $responseStatus = true;
            }
        }

        if($responseStatus){
            Notification::make()
                ->danger()
                ->title(__($title))
                ->body(__($body))
                ->send();
            $this->halt();
        }

        if ($urlExistsInDB>0) {
            Notification::make()
                ->danger()
                ->title(__('URL Website Exists in database'))
                ->body(__('The new website exists in database, try again'))
                ->send();
        }

        if (!$urlCheckExists) {
            Notification::make()
                ->danger()
                ->title(__('website was not registered'))
                ->body(__('The new website not exists in DNS Lookup'))
                ->send();
        }


        if($urlExistsInDB>0 || !$urlCheckExists ){
            $this->halt();
        }

    }
}
