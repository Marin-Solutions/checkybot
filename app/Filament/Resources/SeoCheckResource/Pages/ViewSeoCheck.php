<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

class ViewSeoCheck extends ViewRecord
{
    protected static string $resource = SeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Data')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $this->refreshFormData(['record']);
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('SEO Check Overview')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('website.name')
                                    ->label('Website'),
                                TextEntry::make('website.url')
                                    ->label('URL')
                                    ->url(fn($record) => $record->website->url)
                                    ->openUrlInNewTab(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn(string $state): string => match ($state) {
                                        'completed' => 'success',
                                        'running' => 'warning',
                                        'failed' => 'danger',
                                        'pending' => 'gray',
                                    }),
                                TextEntry::make('total_urls_crawled')
                                    ->label('URLs Crawled'),
                                TextEntry::make('started_at')
                                    ->label('Started At')
                                    ->dateTime(),
                                TextEntry::make('finished_at')
                                    ->label('Finished At')
                                    ->dateTime(),
                                TextEntry::make('duration')
                                    ->label('Duration')
                                    ->formatStateUsing(function ($record) {
                                        if ($record->started_at && $record->finished_at) {
                                            return $record->started_at->diffForHumans($record->finished_at, true);
                                        }

                                        return 'N/A';
                                    }),
                            ]),
                    ]),
                Section::make('Issues Summary')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('errors_count')
                                    ->label('Errors')
                                    ->badge()
                                    ->color('danger'),
                                TextEntry::make('warnings_count')
                                    ->label('Warnings')
                                    ->badge()
                                    ->color('warning'),
                                TextEntry::make('notices_count')
                                    ->label('Notices')
                                    ->badge()
                                    ->color('info'),
                            ]),
                    ]),
                Section::make('Issues Details')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Issues List')
                            ->formatStateUsing(function ($record) {
                                // Get a mix of different severity types
                                $errors = $record->seoIssues()->where('severity', 'error')->take(3)->get();
                                $warnings = $record->seoIssues()->where('severity', 'warning')->take(3)->get();
                                $notices = $record->seoIssues()->where('severity', 'notice')->take(3)->get();

                                $issues = $errors->concat($warnings)->concat($notices);

                                if ($issues->isEmpty()) {
                                    return 'No issues found';
                                }

                                $html = '<div style="font-family: system-ui, sans-serif;">';
                                foreach ($issues as $issue) {
                                    $severityColor = match ($issue->severity->value) {
                                        'error' => '#dc2626',
                                        'warning' => '#d97706',
                                        'notice' => '#2563eb',
                                        default => '#6b7280'
                                    };

                                    $html .= '<div style="border-left: 4px solid ' . $severityColor . '; padding: 12px 0 12px 16px; margin-bottom: 16px;">';
                                    $html .= '<div style="font-weight: 600; color: ' . $severityColor . '; margin-bottom: 4px;">';
                                    $html .= '[' . strtoupper($issue->severity->value) . '] ' . $issue->title;
                                    $html .= '</div>';
                                    $html .= '<div style="font-size: 14px; color: #6b7280; margin-bottom: 4px;">';
                                    $html .= $issue->description;
                                    $html .= '</div>';
                                    $html .= '<div style="font-size: 12px; color: #9ca3af;">';
                                    $html .= '<a href="' . $issue->url . '" target="_blank" style="color: #3b82f6; text-decoration: underline;">';
                                    $html .= \Str::limit($issue->url, 80);
                                    $html .= '</a>';
                                    $html .= '</div>';
                                    $html .= '</div>';
                                }
                                $html .= '</div>';

                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),
            ]);
    }
}
