<?php

namespace App\Models;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function assertions(): HasMany
    {
        return $this->hasMany(MonitorApiAssertion::class, 'monitor_api_id')
            ->orderBy('sort_order');
    }

    public static function testApi(array $data): array
    {
        $url = $data['url'];
        $responseData = [
            'code' => 0,
            'body' => null,
            'assertions' => []
        ];

        try {
            $request = Http::get($url);
            $responseData['code'] = $request->ok() ? 200 : 0;
            $responseData['body'] = $request->json();

            // If this is a new API being tested (not saved yet)
            if (isset($data['data_path'])) {
                $value = Arr::get($responseData['body'], $data['data_path']);
                $responseData['assertions'][] = [
                    'path' => $data['data_path'],
                    'passed' => isset($value),
                    'message' => isset($value)
                        ? "Value exists at path"
                        : "Value does not exist at path"
                ];
            }
            // If this is an existing API with assertions
            elseif (isset($data['id'])) {
                $api = self::with('assertions')->find($data['id']);
                if ($api) {
                    foreach ($api->assertions as $assertion) {
                        if (!$assertion->is_active) continue;

                        $value = Arr::get($responseData['body'], $assertion->data_path);
                        $validationResult = $assertion->validateResponse($value);

                        $responseData['assertions'][] = [
                            'path' => $assertion->data_path,
                            'type' => $assertion->assertion_type,
                            'passed' => $validationResult['passed'],
                            'message' => $validationResult['message']
                        ];
                    }
                }
            }

            return $responseData;
        } catch (RequestException $exception) {
            $handlerContext = $exception->getHandlerContext();
            $responseData['code'] = $handlerContext['errno'];
            $responseData['body'] = $handlerContext['error'];
            return $responseData;
        }
    }
}
