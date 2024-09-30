<?php

    namespace App\Models;

    use App\Enums\WebhookHttpMethod;
    use GuzzleHttp\Exception\RequestException;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Http;

    class NotificationChannels extends Model
    {
        use HasFactory;

        protected $fillable = [
            'method',
            'url',
            'description',
            'request_body',
            'created_by',
        ];

        protected $casts = [
            'request_body' => 'array'
        ];

        public static function testWebhook( array $data ): array
        {
            $messageTest  = "Hello, I'm from " . url('/');
            $method       = $data[ 'method' ];
            $url          = $data[ 'url' ];
            $responseData = [];
            $requestBody  = $data[ 'request_body' ];

            try {
                if ( str_contains($url, '{message}') ) {
                    $url = str_replace('{message}', $messageTest, $url);
                }
                if ( str_contains($url, '{description}') ) {
                    $url = str_replace('{description}', $data[ 'description' ] ?? '', $url);
                }

                if ( $method === WebhookHttpMethod::POST->value && count($data[ 'request_body' ]) ) {
                    foreach ( $requestBody as $key => $value ) {
                        if ( $value === '{message}' ) {
                            $requestBody[ $key ] = $messageTest;
                        }
                        if ( $value === '{description}' ) {
                            $requestBody[ $key ] = $data[ 'description' ] ?? '';
                        }
                    }
                }

                $webhookCallback = match ( $method ) {
                    WebhookHttpMethod::GET->value => Http::{$method}($url),
                    default => Http::{$method}($url, $requestBody)
                };

                app('debugbar')->log($webhookCallback->json());

                $responseData[ 'code' ] = $webhookCallback->ok() ? 200 : 0;
                $responseData[ 'body' ] = $webhookCallback->json();
                return $responseData;

            } catch ( RequestException $exception ) {

                $handlerContext         = $exception->getHandlerContext();
                $responseData[ 'code' ] = $handlerContext[ 'errno' ];
                $responseData[ 'body' ] = $handlerContext[ 'error' ];
                return $responseData;

            }
        }
    }
