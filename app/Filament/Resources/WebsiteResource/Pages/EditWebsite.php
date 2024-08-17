<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use Filament\Actions;
use App\Models\Website;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\WebsiteResource;

class EditWebsite extends EditRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function beforeSave()
    {
        $url=$this->data['url'];
        $id=$this->data['id'];
        $urlExistsInDB = Website::whereUrl($url)->where('id','!=',$id)->count();
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
