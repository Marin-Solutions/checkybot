<?php

    namespace App\Filament\Resources;

    use App\Enums\WebhookHttpMethod;
    use App\Filament\Resources\NotificationChannelsResource\Pages;
    use App\Models\NotificationChannels;
    use Filament\Forms\Components\KeyValue;
    use Filament\Forms\Components\Select;
    use Filament\Forms\Components\Textarea;
    use Filament\Forms\Components\TextInput;
    use Filament\Forms\Form;
    use Filament\Forms\Get;
    use Filament\Resources\Resource;
    use Filament\Tables;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Table;
    use Illuminate\Support\HtmlString;

    class NotificationChannelsResource extends Resource
    {
        protected static ?string $model = NotificationChannels::class;

        protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
        protected static ?string $navigationGroup = 'Operations';
        protected static ?int $navigationSort = 3;
        protected static string $urlPattern = "/(?:\{message\}.*\{description\}|\{description\}.*\{message\})/";

        public static function testWebhook( array $data ): void
        {
            app('debugbar')->log($data);
        }

        public static function form( Form $form ): Form
        {

            return $form
                ->schema([
                    TextInput::make('title')->required(),
                    Select::make('method')
                        ->required()
                        ->columns(2)
                        ->autofocus()
                        ->placeholder('Select HTTP Method')
                        ->options([
                            WebhookHttpMethod::GET->value  => 'GET',
                            WebhookHttpMethod::POST->value => 'POST',
                        ])
                        ->reactive()
                        ->afterStateUpdated(function ( $state, callable $set, callable $get ) {
                            if ( $state === WebhookHttpMethod::POST->value ) {
                                if ( count($get('request_body')) ) {
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
                            fn( Get $get ): \Closure => function ( string $attribute, $value, \Closure $fail ) use ( $get ) {

                                if ( $get('method') === WebhookHttpMethod::GET->value ) {
                                    if ( !preg_match(self::$urlPattern, $value) ) {
                                        $fail('The URL must contain {message} and {description} placeholder.');
                                    }
                                }

                            }
                        ])
                        ->helperText(function ( Get $get ) {
                            $message = $get('method') === WebhookHttpMethod::POST->value
                                ?
                                "<b>{message}</b> and <b>{description}</b> placeholders can be in URL or request body (JSON)."
                                :
                                "URL must contain <b>{message}</b> and <b>{description}</b> placeholders.";
                            return new HtmlString($message);
                        }),
                    KeyValue::make('request_body')
                        ->columnSpanFull()
                        ->rules([
                            fn( Get $get ): \Closure => function ( string $attribute, $value, \Closure $fail ) use ( $get ) {

                                if ( $get('is_post_method') ) {

                                    // Check if "message" placeholder is set is URL or in the request body
                                    if ( !str_contains($get('url'), "{message}") && !in_array('{message}', array_values($value)) ) {
                                        $fail('The request body must contain key for {message} value or you can set the placeholder for it in the URL.');
                                    }
                                    // Check if "description" placeholder is set is URL or in the request body
                                    if ( !str_contains($get('url'), "{description}") && !in_array('{description}', array_values($value)) ) {
                                        $fail('The request body must contain key for {description} value or you can set the placeholder for it in the URL.');
                                    }

                                }

                            }
                        ])
                        ->visible(fn( $get ) => $get("is_post_method")),
                    Textarea::make('description')
                        ->columnSpanFull(),
                ])
            ;
        }

        public static function table( Table $table ): Table
        {
            return $table
                ->columns([
                    TextColumn::make('title'),
                    TextColumn::make('method'),
                    TextColumn::make('url')->label('Webhook URL'),
                    TextColumn::make('request_body'),
                    TextColumn::make('description')
                ])
                ->filters([
                    //
                ])
                ->actions([
                    Tables\Actions\EditAction::make(),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ])
            ;
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
                'index'  => Pages\ListNotificationChannels::route('/'),
                'create' => Pages\CreateNotificationChannels::route('/create'),
                'edit'   => Pages\EditNotificationChannels::route('/{record}/edit'),
            ];
        }
    }
