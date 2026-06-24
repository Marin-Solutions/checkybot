<x-filament-panels::page>
    <style>
        .health-overview {
            display: grid;
            gap: 1.25rem;
        }

        .health-overview__summary {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .health-overview__card,
        .health-overview__table-wrap {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: .5rem;
        }

        .dark .health-overview__card,
        .dark .health-overview__table-wrap {
            background: var(--gray-900);
            border-color: var(--gray-800);
        }

        .health-overview__card {
            color: var(--gray-950);
            display: block;
            padding: 1rem;
            text-decoration: none;
        }

        .dark .health-overview__card {
            color: white;
        }

        .health-overview__card:hover {
            background: var(--gray-50);
        }

        .dark .health-overview__card:hover {
            background: var(--gray-800);
        }

        .health-overview__card.is-active {
            box-shadow: inset 0 0 0 1px currentColor;
        }

        .health-overview__card--healthy {
            color: var(--success-600);
        }

        .health-overview__card--warning {
            color: var(--warning-600);
        }

        .health-overview__card--critical {
            color: var(--danger-600);
        }

        .health-overview__card-label {
            font-size: .875rem;
            font-weight: 600;
        }

        .health-overview__card-body {
            align-items: end;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: .75rem;
        }

        .health-overview__card-count {
            color: var(--gray-950);
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }

        .dark .health-overview__card-count {
            color: white;
        }

        .health-overview__card-percent {
            font-size: .875rem;
            font-weight: 600;
        }

        .health-overview__filters {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .health-overview__filter {
            border: 1px solid var(--gray-200);
            border-radius: .375rem;
            color: var(--gray-700);
            font-size: .875rem;
            font-weight: 600;
            padding: .5rem .75rem;
            text-decoration: none;
        }

        .dark .health-overview__filter {
            border-color: var(--gray-800);
            color: var(--gray-300);
        }

        .health-overview__filter:hover,
        .health-overview__filter.is-active {
            background: var(--primary-50);
            border-color: var(--primary-500);
            color: var(--primary-700);
        }

        .dark .health-overview__filter:hover,
        .dark .health-overview__filter.is-active {
            background: color-mix(in srgb, var(--primary-500) 12%, transparent);
            color: var(--primary-300);
        }

        .health-overview__table-scroll {
            overflow-x: auto;
        }

        .health-overview__table {
            border-collapse: collapse;
            font-size: .875rem;
            min-width: 760px;
            width: 100%;
        }

        .health-overview__table th {
            background: var(--gray-50);
            color: var(--gray-950);
            font-weight: 700;
            padding: .75rem 1rem;
            text-align: left;
        }

        .dark .health-overview__table th {
            background: var(--gray-950);
            color: white;
        }

        .health-overview__table td {
            border-top: 1px solid var(--gray-200);
            color: var(--gray-700);
            padding: .75rem 1rem;
            vertical-align: top;
        }

        .dark .health-overview__table td {
            border-color: var(--gray-800);
            color: var(--gray-300);
        }

        .health-overview__name {
            color: var(--gray-950);
            font-weight: 600;
            text-decoration: none;
        }

        .health-overview__name:hover {
            color: var(--primary-600);
            text-decoration: underline;
        }

        .dark .health-overview__name {
            color: white;
        }

        .dark .health-overview__name:hover {
            color: var(--primary-400);
        }

        .health-overview__badge {
            border-radius: .375rem;
            display: inline-flex;
            font-size: .75rem;
            font-weight: 700;
            line-height: 1;
            padding: .375rem .5rem;
        }

        .health-overview__badge--healthy {
            background: var(--success-50);
            color: var(--success-700);
        }

        .health-overview__badge--warning {
            background: var(--warning-50);
            color: var(--warning-700);
        }

        .health-overview__badge--critical {
            background: var(--danger-50);
            color: var(--danger-700);
        }

        .dark .health-overview__badge--healthy {
            background: color-mix(in srgb, var(--success-500) 14%, transparent);
            color: var(--success-300);
        }

        .dark .health-overview__badge--warning {
            background: color-mix(in srgb, var(--warning-500) 14%, transparent);
            color: var(--warning-300);
        }

        .dark .health-overview__badge--critical {
            background: color-mix(in srgb, var(--danger-500) 14%, transparent);
            color: var(--danger-300);
        }

        .health-overview__empty {
            color: var(--gray-500);
            padding: 2rem 1rem;
            text-align: center;
        }

        @media (max-width: 900px) {
            .health-overview__summary {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="health-overview">
        <div class="health-overview__summary">
            @foreach ($summary as $status => $bucket)
                <a
                    href="{{ \App\Filament\Pages\HealthOverview::getUrl(['status' => $status]) }}"
                    @class([
                        'health-overview__card',
                        "health-overview__card--{$status}",
                        'is-active' => $activeStatus === $status,
                    ])
                >
                    <div class="health-overview__card-label">{{ $bucket['label'] }}</div>
                    <div class="health-overview__card-body">
                        <div class="health-overview__card-count">{{ number_format($bucket['count']) }}</div>
                        <div class="health-overview__card-percent">{{ $bucket['percent'] }}%</div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="health-overview__filters">
            @foreach ($statusOptions as $status => $label)
                <a
                    href="{{ \App\Filament\Pages\HealthOverview::getUrl(['status' => $status]) }}"
                    @class([
                        'health-overview__filter',
                        'is-active' => $activeStatus === $status,
                    ])
                >
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="health-overview__table-wrap">
            <div class="health-overview__table-scroll">
                <table class="health-overview__table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td>
                                    <span class="health-overview__badge health-overview__badge--{{ $item['status'] }}">
                                        {{ ucfirst($item['status']) }}
                                    </span>
                                </td>
                                <td>{{ $item['type'] }}</td>
                                <td>
                                    @if ($item['url'])
                                        <a href="{{ $item['url'] }}" class="health-overview__name">
                                            {{ $item['name'] }}
                                        </a>
                                    @else
                                        <span class="health-overview__name">{{ $item['name'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $item['detail'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="health-overview__empty">
                                    No monitored checks in this bucket.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
