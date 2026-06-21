<?php

namespace MarinSolutions\CheckybotLaravel\Components;

class HealthComponent
{
    protected string $interval = '5m';

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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'interval' => $this->interval,
        ];
    }
}
