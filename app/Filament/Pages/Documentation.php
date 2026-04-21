<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Pages\Page;

class Documentation extends Page
{
    protected string $view = 'filament.pages.documentation';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';

    protected static \UnitEnum|string|null $navigationGroup = 'Developer';

    protected static ?string $navigationLabel = 'Documentation';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Developer documentation';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['Super Admin', 'Admin']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/') ?: rtrim(url('/'), '/');

        return [
            'baseUrl' => $baseUrl,
            'apiKeysUrl' => ApiKeyResource::getUrl('index'),
            'swaggerUrl' => url('/api/documentation'),
            'mcpEndpoint' => $baseUrl.'/api/v1/mcp',
            'restBaseUrl' => $baseUrl.'/api/v1/control',
            'mcpConfig' => json_encode([
                'mcpServers' => [
                    'checkybot' => [
                        'type' => 'streamable-http',
                        'url' => $baseUrl.'/api/v1/mcp',
                        'headers' => [
                            'Authorization' => 'Bearer ${CHECKYBOT_API_KEY}',
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'curlExample' => "curl {$baseUrl}/api/v1/control/me \\\n  -H 'Authorization: Bearer '\$CHECKYBOT_API_KEY",
        ];
    }
}
