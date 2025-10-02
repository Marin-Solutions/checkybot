<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $this->getHeading() }}
        </x-slot>

        <x-slot name="actions">
            <div class="flex gap-2">
                <x-filament::button
                    size="sm"
                    :color="$timeRange === '1h' ? 'primary' : 'gray'"
                    wire:click="$set('timeRange', '1h')">
                    1 Hour
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    :color="$timeRange === '6h' ? 'primary' : 'gray'"
                    wire:click="$set('timeRange', '6h')">
                    6 Hours
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    :color="$timeRange === '24h' ? 'primary' : 'gray'"
                    wire:click="$set('timeRange', '24h')">
                    24 Hours
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    :color="$timeRange === '7d' ? 'primary' : 'gray'"
                    wire:click="$set('timeRange', '7d')">
                    7 Days
                </x-filament::button>
            </div>
        </x-slot>

        <div class="p-6">
            <x-filament-widgets::chart
                :data="$this->getData()"
                :options="$this->getOptions()"
                :type="$this->getType()"
                :height="$this->getMaxHeight()" />
        </div>
    </x-filament::section>
</x-filament-widgets::widget>