<?php

namespace App\Filament\Resources\MonitorApisResource\RelationManagers;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Support\ApiMonitorEvidenceFormatter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AssertionsRelationManager extends RelationManager
{
    protected static string $relationship = 'assertions';

    protected static ?string $title = 'API Assertions';

    protected static ?string $recordTitleAttribute = 'data_path';

    protected static ?string $inverseRelationship = 'monitorApi';

    /**
     * @var array<int|string, array<string, mixed>>
     */
    protected array $assertionPreviews = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('data_path')
                    ->required()
                    ->label('JSON Path')
                    ->helperText('The path to the value in the JSON response (e.g. data.user.id)'),

                Forms\Components\Select::make('assertion_type')
                    ->required()
                    ->options([
                        'type_check' => 'Check Type',
                        'value_compare' => 'Compare Value',
                        'exists' => 'Value Exists',
                        'not_exists' => 'Value Does Not Exist',
                        'array_length' => 'Array Length',
                        'regex_match' => 'Regex Match',
                    ])
                    ->reactive()
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('comparison_operator', null)),

                Forms\Components\Select::make('expected_type')
                    ->options([
                        'string' => 'String',
                        'integer' => 'Integer',
                        'boolean' => 'Boolean',
                        'array' => 'Array',
                        'object' => 'Object',
                        'float' => 'Float',
                        'null' => 'Null',
                    ])
                    ->required(fn (Forms\Get $get) => $get('assertion_type') === 'type_check')
                    ->visible(fn (Forms\Get $get) => $get('assertion_type') === 'type_check'),

                Forms\Components\Select::make('comparison_operator')
                    ->options([
                        '=' => 'Equals',
                        '!=' => 'Not Equals',
                        '>' => 'Greater Than',
                        '<' => 'Less Than',
                        '>=' => 'Greater Than or Equal',
                        '<=' => 'Less Than or Equal',
                        'contains' => 'Contains',
                    ])
                    ->required(fn (Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length']))
                    ->visible(fn (Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length'])),

                Forms\Components\TextInput::make('expected_value')
                    ->required(fn (Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length']))
                    ->visible(fn (Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length']))
                    ->label(fn (Forms\Get $get) => $get('assertion_type') === 'array_length' ? 'Expected Length' : 'Expected Value'),

                Forms\Components\TextInput::make('regex_pattern')
                    ->required(fn (Forms\Get $get) => $get('assertion_type') === 'regex_match')
                    ->visible(fn (Forms\Get $get) => $get('assertion_type') === 'regex_match')
                    ->helperText('Regular expression pattern (e.g. /^[0-9]+$/)')
                    ->placeholder('/pattern/'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->label('Sort Order')
                    ->helperText('Lower numbers are evaluated first'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('data_path')
                    ->label('JSON Path')
                    ->searchable(),

                Tables\Columns\TextColumn::make('assertion_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                Tables\Columns\TextColumn::make('expected_type')
                    ->label('Expected Type')
                    ->visible(fn ($record) => $record && $record->assertion_type === 'type_check'),

                Tables\Columns\TextColumn::make('comparison_operator')
                    ->label('Operator')
                    ->visible(fn ($record) => $record && in_array($record->assertion_type, ['value_compare', 'array_length'])),

                Tables\Columns\TextColumn::make('expected_value')
                    ->label('Expected Value')
                    ->visible(fn ($record) => $record && in_array($record->assertion_type, ['value_compare', 'array_length'])),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add Assertion'),
            ])
            ->actions([
                ViewAction::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-beaker')
                    ->color('info')
                    ->beforeFormFilled(function (): void {
                        $this->assertionPreviews = [];
                    })
                    ->modalHeading(fn (MonitorApiAssertion $record): string => "Preview {$record->data_path}")
                    ->modalDescription('Evaluates this assertion against the latest saved response body, or runs a fresh API test when no saved response is available.')
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Section::make('Assertion Result')
                            ->schema([
                                TextEntry::make('preview_result')
                                    ->label('Result')
                                    ->state(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['passed'] ? 'Passed' : 'Failed')
                                    ->badge()
                                    ->color(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['passed'] ? 'success' : 'danger'),
                                TextEntry::make('preview_source')
                                    ->label('Response Source')
                                    ->state(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['source_label']),
                                TextEntry::make('preview_http_code')
                                    ->label('HTTP Code')
                                    ->state(fn (MonitorApiAssertion $record): ?int => $this->previewFor($record)['http_code'])
                                    ->badge()
                                    ->color(fn (?int $state): string => ApiMonitorEvidenceFormatter::httpCodeColor($state))
                                    ->placeholder('-'),
                                TextEntry::make('preview_response_time')
                                    ->label('Response Time')
                                    ->state(function (MonitorApiAssertion $record): ?string {
                                        $responseTime = $this->previewFor($record)['response_time_ms'] ?? null;

                                        return $responseTime !== null ? "{$responseTime}ms" : null;
                                    })
                                    ->placeholder('-'),
                                TextEntry::make('preview_path')
                                    ->label('JSON Path')
                                    ->state(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['path'])
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('preview_type')
                                    ->label('Assertion')
                                    ->state(fn (MonitorApiAssertion $record): string => str_replace('_', ' ', (string) $this->previewFor($record)['type']))
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('preview_message')
                                    ->label('Message')
                                    ->state(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['message'])
                                    ->columnSpanFull(),
                                TextEntry::make('preview_expected')
                                    ->label('Expected')
                                    ->state(fn (MonitorApiAssertion $record): string => ApiMonitorEvidenceFormatter::stringifyAssertionValue($this->previewFor($record)['expected']))
                                    ->icon('heroicon-o-flag')
                                    ->iconColor('info')
                                    ->copyable(),
                                TextEntry::make('preview_actual')
                                    ->label('Actual')
                                    ->state(fn (MonitorApiAssertion $record): string => ApiMonitorEvidenceFormatter::stringifyAssertionValue($this->previewFor($record)['actual']))
                                    ->icon(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['passed'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                                    ->iconColor(fn (MonitorApiAssertion $record): string => $this->previewFor($record)['passed'] ? 'success' : 'danger')
                                    ->copyable(),
                                TextEntry::make('preview_error')
                                    ->label('Response Error')
                                    ->state(fn (MonitorApiAssertion $record): ?string => $this->previewFor($record)['error'])
                                    ->visible(fn (MonitorApiAssertion $record): bool => filled($this->previewFor($record)['error']))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewFor(MonitorApiAssertion $assertion): array
    {
        return $this->assertionPreviews[$assertion->getKey()] ??= $this->ownerMonitor()->previewAssertion($assertion);
    }

    private function ownerMonitor(): MonitorApis
    {
        /** @var MonitorApis $ownerRecord */
        $ownerRecord = $this->getOwnerRecord();

        return $ownerRecord;
    }
}
