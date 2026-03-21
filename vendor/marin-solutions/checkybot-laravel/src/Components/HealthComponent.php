<?php

namespace MarinSolutions\CheckybotLaravel\Components;

use Carbon\CarbonInterface;
use Closure;
use RuntimeException;

class HealthComponent
{
    protected string $interval = '5m';

    protected ?string $metricName = null;

    protected ?Closure $metricResolver = null;

    /**
     * @var array{operator: string, value: mixed}|null
     */
    protected ?array $warningThreshold = null;

    /**
     * @var array{operator: string, value: mixed}|null
     */
    protected ?array $dangerThreshold = null;

    public function __construct(
        protected string $name
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getInterval(): string
    {
        return $this->interval;
    }

    public function every(string $interval): self
    {
        $this->interval = $interval;

        return $this;
    }

    public function interval(string $interval): self
    {
        return $this->every($interval);
    }

    public function everyMinute(): self
    {
        return $this->every('1m');
    }

    public function everyFiveMinutes(): self
    {
        return $this->every('5m');
    }

    public function hourly(): self
    {
        return $this->every('1h');
    }

    public function daily(): self
    {
        return $this->every('1d');
    }

    public function metric(string $name, Closure $resolver): self
    {
        $this->metricName = $name;
        $this->metricResolver = $resolver;

        return $this;
    }

    public function warningWhen(string $operator, mixed $value): self
    {
        $this->warningThreshold = [
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function dangerWhen(string $operator, mixed $value): self
    {
        $this->dangerThreshold = [
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'interval' => $this->interval,
            'metric' => $this->metricName,
            'warning_threshold' => $this->warningThreshold,
            'danger_threshold' => $this->dangerThreshold,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toHeartbeatPayload(CarbonInterface $observedAt): array
    {
        if ($this->metricName === null || $this->metricResolver === null) {
            throw new RuntimeException("Health component [{$this->name}] is missing a metric definition.");
        }

        $metricValue = ($this->metricResolver)();
        $status = $this->evaluateStatus($metricValue);

        return [
            'name' => $this->name,
            'interval' => $this->interval,
            'status' => $status,
            'summary' => null,
            'metrics' => [
                $this->metricName => $metricValue,
            ],
            'observed_at' => $observedAt->toISOString(),
        ];
    }

    protected function evaluateStatus(mixed $metricValue): string
    {
        if ($this->matchesThreshold($metricValue, $this->dangerThreshold)) {
            return 'danger';
        }

        if ($this->matchesThreshold($metricValue, $this->warningThreshold)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param  array{operator: string, value: mixed}|null  $threshold
     */
    protected function matchesThreshold(mixed $metricValue, ?array $threshold): bool
    {
        if ($threshold === null) {
            return false;
        }

        return match ($threshold['operator']) {
            '>' => $metricValue > $threshold['value'],
            '>=' => $metricValue >= $threshold['value'],
            '<' => $metricValue < $threshold['value'],
            '<=' => $metricValue <= $threshold['value'],
            '==' => $metricValue == $threshold['value'],
            '===' => $metricValue === $threshold['value'],
            '!=' => $metricValue != $threshold['value'],
            '!==' => $metricValue !== $threshold['value'],
            default => throw new RuntimeException("Unsupported operator [{$threshold['operator']}] for health component [{$this->name}]."),
        };
    }
}
