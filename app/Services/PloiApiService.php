<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PloiApiService
{
    public static function verifyKey(string $key): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->get('https://ploi.io/api/user');

            if ($response->status() === 200) {
                return [
                    'is_verified' => true,
                    'error_message' => 'The API key verification was successful.',
                ];
            } else {
                return [
                    'is_verified' => false,
                    'error_message' => 'API verification failed: '.$response->body(),
                ];
            }
        } catch (\Exception $e) {
            return [
                'is_verified' => false,
                'error_message' => 'API verification failed: '.$e->getMessage(),
            ];
        }
    }
}
