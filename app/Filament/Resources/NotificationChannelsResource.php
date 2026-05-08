<?php

namespace App\Filament\Resources;

use App\Enums\WebhookHttpMethod;
use App\Filament\Resources\NotificationChannelsResource\Pages;
use App\Models\NotificationChannels;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class NotificationChannelsResource extends Resource
{
    protected static ?string $model = NotificationChannels::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected static string $urlPattern = "/(?:\{message\}.*\{description\}|\{description\}.*\{message\})/";

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('created_by', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {

        return $schema
            ->schema([
                TextInput::make('title')->required(),
                Select::make('method')
                    ->required()
                    ->columns(2)
                    ->autofocus()
                    ->placeholder('Select HTTP Method')
                    ->options([
                        WebhookHttpMethod::GET->value => 'GET',
                        WebhookHttpMethod::POST->value => 'POST',
                    ])
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        if ($state === WebhookHttpMethod::POST->value) {
                            $requestBody = $get('request_body');

                            if (is_array($requestBody) && count($requestBody)) {
                                $set('request_body', []);
                            }
                        }
                        $set('is_post_method', $state === WebhookHttpMethod::POST->value);
                    }),
                TextInput::make('url')
                    ->label('Webhook URL')
                    ->required()
                    ->default('https://')
                    ->maxLength(2083)
                    ->reactive()
                    ->rules([
                        fn (\Filament\Schemas\Components\Utilities\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {

                            if ($get('method') === WebhookHttpMethod::GET->value) {
                                if (! preg_match(self::$urlPattern, $value)) {
                                    $fail('The URL must contain {message} and {description} placeholder.');
                                }
                            }
                        },
                    ])
                    ->helperText(function (\Filament\Schemas\Components\Utilities\Get $get) {
                        $message = $get('method') === WebhookHttpMethod::POST->value
                            ?
                            '<b>{message}</b> and <b>{description}</b> placeholders can be in URL or request body (JSON).'
                            :
                            'URL must contain <b>{message}</b> and <b>{description}</b> placeholders.';

                        return new HtmlString($message);
                    }),
                KeyValue::make('request_body')
                    ->columnSpanFull()
                    ->rules([
                        fn (\Filament\Schemas\Components\Utilities\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {

                            if ($get('is_post_method')) {
                                $requestBodyValues = array_values(is_array($value) ? $value : []);
                                $url = (string) $get('url');

                                // Check if "message" placeholder is set is URL or in the request body
                                if (! str_contains($url, '{message}') && ! in_array('{message}', $requestBodyValues, true)) {
                                    $fail('The request body must contain key for {message} value or you can set the placeholder for it in the URL.');
                                }
                                // Check if "description" placeholder is set is URL or in the request body
                                if (! str_contains($url, '{description}') && ! in_array('{description}', $requestBodyValues, true)) {
                                    $fail('The request body must contain key for {description} value or you can set the placeholder for it in the URL.');
                                }
                            }
                        },
                    ])
                    ->visible(fn ($get) => $get('is_post_method')),
                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('method'),
                TextColumn::make('url')
                    ->label('Webhook URL')
                    ->state(fn (NotificationChannels $record): string => $record->maskedWebhookUrlForDisplay())
                    ->copyable()
                    ->copyableState(fn (NotificationChannels $record): ?string => $record->url),
                TextColumn::make('request_body')
                    ->state(fn (NotificationChannels $record): ?string => $record->maskedRequestBodyForDisplay())
                    ->copyable(fn (NotificationChannels $record): bool => $record->requestBodyForCopy() !== null)
                    ->copyableState(fn (NotificationChannels $record): ?string => $record->requestBodyForCopy()),
                TextColumn::make('description'),
                TextColumn::make('last_delivery_succeeded')
                    ->label('Last Delivery')
                    ->badge()
                    ->placeholder('No delivery evidence')
                    ->state(fn (NotificationChannels $record): ?string => $record->last_delivery_attempted_at
                        ? (($record->last_delivery_succeeded ? 'Success' : 'Failed').' '.($record->last_delivery_kind ?? 'send'))
                        : null)
                    ->color(fn (NotificationChannels $record): string => match ($record->last_delivery_succeeded) {
                        true => 'success',
                        false => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_delivery_response_code')
                    ->label('Response')
                    ->placeholder('No response code')
                    ->state(fn (NotificationChannels $record): ?string => $record->last_delivery_response_code
                        ? (string) $record->last_delivery_response_code
                        : null),
                TextColumn::make('last_delivery_summary')
                    ->label('Delivery Evidence')
                    ->placeholder('No test or send recorded yet')
                    ->wrap()
                    ->limit(100),
                TextColumn::make('last_delivery_attempted_at')
                    ->label('Attempted')
                    ->placeholder('Never')
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\Action::make('sendTest')
                    ->label('Send Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->authorize(fn (NotificationChannels $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Send a test webhook?')
                    ->modalDescription(fn (NotificationChannels $record): string => 'A sample Checkybot payload will be sent to "'.$record->title.'". The result will be saved as this channel\'s latest delivery evidence.')
                    ->modalSubmitActionLabel('Send test')
                    ->action(function (NotificationChannels $record): void {
                        $response = $record->sendWebhookNotification([
                            'message' => 'Checkybot webhook channel test',
                            'description' => 'This test confirms the saved webhook channel can receive Checkybot notifications.',
                        ], 'test');

                        $code = (int) ($response['code'] ?? 0);
                        $successful = $code >= 200 && $code < 300;
                        $summary = NotificationChannels::summarizeDeliveryResponse($response);

                        $notification = Notification::make()
                            ->title($successful ? 'Test webhook delivered' : 'Test webhook failed')
                            ->body($summary);

                        ($successful ? $notification->success() : $notification->danger())->send();
                    }),
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationChannels::route('/'),
            'create' => Pages\CreateNotificationChannels::route('/create'),
            'edit' => Pages\EditNotificationChannels::route('/{record}/edit'),
        ];
    }
}
