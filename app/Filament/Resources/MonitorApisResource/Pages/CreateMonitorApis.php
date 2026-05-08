<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use App\Filament\Resources\Support\ValidatesProjectAssignment;
use App\Traits\MonitoringApis;
use Filament\Resources\Pages\CreateRecord;

class CreateMonitorApis extends CreateRecord
{
    use MonitoringApis;
    use ValidatesProjectAssignment;

    protected static string $resource = MonitorApisResource::class;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $pendingAssertions = [];

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateProjectAssignment($data['project_id'] ?? null);

        $data['created_by'] = auth()->id();

        $this->pendingAssertions = $this->normalizeAssertionsForCreate($data['assertions'] ?? []);
        unset($data['assertions']);

        if (blank($data['data_path'] ?? null) && filled($this->pendingAssertions[0]['data_path'] ?? null)) {
            $data['data_path'] = $this->pendingAssertions[0]['data_path'];
        }

        if (blank($data['request_body_type'] ?? null)) {
            $data['request_body'] = null;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->pendingAssertions === []) {
            return;
        }

        $this->record->assertions()->createMany($this->pendingAssertions);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $assertions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssertionsForCreate(array $assertions): array
    {
        return collect($assertions)
            ->values()
            ->map(fn (array $assertion, int $index): array => [
                'data_path' => $assertion['data_path'],
                'assertion_type' => $assertion['assertion_type'],
                'expected_type' => $assertion['expected_type'] ?? null,
                'comparison_operator' => $assertion['comparison_operator'] ?? null,
                'expected_value' => $assertion['expected_value'] ?? null,
                'regex_pattern' => $assertion['regex_pattern'] ?? null,
                'sort_order' => $index + 1,
                'is_active' => $assertion['is_active'] ?? true,
            ])
            ->all();
    }

    public function doMonitoring(): void
    {
        $this->callDoMonitoring($this->form);
    }

    protected function getFormActions(): array
    {
        return array_merge([$this->doMonitorApiAction()], parent::getFormActions());
    }
}
