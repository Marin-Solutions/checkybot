<?php

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use App\Filament\Resources\ServerResource\Pages\LogServer;
use App\Filament\Resources\ServerResource\Widgets\CpuLoadChart;
use App\Filament\Resources\ServerResource\Widgets\DiskUsedChart;
use App\Filament\Resources\ServerResource\Widgets\RamUsedChart;
use App\Filament\Resources\ServerResource\Widgets\ServerLogTimeframe;
use App\Models\Server;
use Livewire\Livewire;

describe('ServerLogTimeframe Widget', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
        $this->server = Server::factory()->create(['created_by' => $this->user->id]);
    });

    describe('smoke tests', function () {
        it('renders without errors', function () {
            Livewire::test(ServerLogTimeframe::class, ['record' => $this->server])
                ->assertSuccessful()
                ->assertStatus(200);
        });

        it('has a form', function () {
            Livewire::test(ServerLogTimeframe::class, ['record' => $this->server])
                ->assertFormExists();
        });

        it('displays timeframe select field', function () {
            Livewire::test(ServerLogTimeframe::class, ['record' => $this->server])
                ->assertFormFieldExists('timeFrame');
        });
    });

    describe('form functionality', function () {
        it('has correct default timeframe value', function () {
            Livewire::test(ServerLogTimeframe::class, ['record' => $this->server])
                ->assertFormSet(['timeFrame' => TimeFrame::LAST_24_HOURS->value]);
        });

        it('can change timeframe selection', function () {
            Livewire::test(ServerLogTimeframe::class, ['record' => $this->server])
                ->fillForm(['timeFrame' => TimeFrame::LAST_7_DAYS->value])
                ->assertFormSet(['timeFrame' => TimeFrame::LAST_7_DAYS->value]);
        });

        it('dispatches updateTimeframe event when changed', function () {
            Livewire::test(ServerLogTimeframe::class, ['record' => $this->server])
                ->set('data.timeFrame', TimeFrame::LAST_7_DAYS->value)
                ->assertDispatched('updateTimeframe');
        });
    });
});

describe('LogServer Page with Widgets', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
        $this->server = Server::factory()->create(['created_by' => $this->user->id]);
    });

    describe('smoke tests', function () {
        it('renders the log server page without errors', function () {
            $this->get(LogServer::getUrl(['record' => $this->server]))
                ->assertSuccessful()
                ->assertStatus(200);
        });

        it('contains the server log timeframe widget', function () {
            $this->get(LogServer::getUrl(['record' => $this->server]))
                ->assertSeeLivewire(ServerLogTimeframe::class);
        });
    });
});

describe('Chart Widgets', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
        $this->server = Server::factory()->create(['created_by' => $this->user->id]);
    });

    describe('smoke tests', function () {
        it('cpu chart renders with record', function () {
            Livewire::test(CpuLoadChart::class, ['record' => $this->server])
                ->assertSuccessful();
        });

        it('ram chart renders with record', function () {
            Livewire::test(RamUsedChart::class, ['record' => $this->server])
                ->assertSuccessful();
        });

        it('disk chart renders with record', function () {
            Livewire::test(DiskUsedChart::class, ['record' => $this->server])
                ->assertSuccessful();
        });
    });
});
