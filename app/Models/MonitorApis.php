<?php

namespace App\Models;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MonitorApis extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'url',
        'data_path',
        'created_by'
    ];

    public static function testApi( array $data ): array
    {
        $url          = $data[ 'url' ];
        $responseData = [];

        try {
            $request = Http::get($url);
            $responseArray = $request->json();

            app('debugbar')->log([
                Arr::has($responseArray, '0.title'),
                Arr::get($responseArray, '0.title')
            ]);

            $responseData[ 'code' ] = $request->ok() ? 200 : 0;
            $responseData[ 'body' ] = $request->json();
            return $responseData;

        } catch ( RequestException $exception ) {

            $handlerContext         = $exception->getHandlerContext();
            $responseData[ 'code' ] = $handlerContext[ 'errno' ];
            $responseData[ 'body' ] = $handlerContext[ 'error' ];
            return $responseData;

        }
    }
}
