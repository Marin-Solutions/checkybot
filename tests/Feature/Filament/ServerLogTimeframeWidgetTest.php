<?php

use App\Filament\Resources\ServerResource\Enums\TimeFrame;
use App\Filament\Resources\ServerResource\Pages\LogServer;
use App\Filament\Resources\ServerResource\Widgets\CpuLoadChart;
use App\Filament\Resources\ServerResource\Widgets\DiskUsedChart;
use App\Filament\Resources\ServerResource\Widgets\RamUsedChart;
use App\Filament\Resources\ServerResource\Widgets\ServerLogTimeframe;
use App\Models\Server;
use App\Models\ServerInformationHistory;
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

        it('shows reporter evidence for setup diagnostics', function () {
            $this->server->update([
                'last_reporter_ip' => '198.51.100.24',
                'last_reporter_user_agent' => 'checkybot-reporter/1.0',
                'last_reporter_seen_at' => now()->subMinutes(5),
            ]);

            $this->get(LogServer::getUrl(['record' => $this->server]))
                ->assertSuccessful()
                ->assertSee('Reporter Evidence')
                ->assertSee('198.51.100.24')
                ->assertSee('checkybot-reporter/1.0');
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

        it('cpu chart normalizes load average by cpu cores', function () {
            $this->server->update(['cpu_cores' => 4]);

            ServerInformationHistory::factory()->create([
                'server_id' => $this->server->id,
                'cpu_load' => 3.0,
                'created_at' => now(),
            ]);

            $component = Livewire::test(CpuLoadChart::class, ['record' => $this->server->refresh()])
                ->assertSuccessful()
                ->instance();

            $data = (fn () => $this->getData())->call($component);

            expect($data['datasets'][0]['data'])->toBe([75.0]);
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
