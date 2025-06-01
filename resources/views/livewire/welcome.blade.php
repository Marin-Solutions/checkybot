<main class="max-w-7xl mx-auto" x-data="{ openModal: null }">
    <x-nav/>
    <div class="text-center pt-12 md:pt-24">
        <h1 class="text-3xl font-bold tracking-tight text-white md:text-6xl px-4">Unleash Your Apps with Checkybot<span
                class="text-emerald-500">.</span></h1>
        <p class="text-base pt-4 text-zinc-400 px-4">The next-gen open platform to launch, scale, and manage your
            apps—your way.</p>
        <div class="pb-10 pt-1 text-xs flex gap-6 justify-center flex-row flex-wrap"></div>

        <div id="features" class="text-center text-4xl font-bold pt-10 text-white">Features</div>
        <div class="mx-auto mt-16 max-w-2xl sm:mt-14 lg:mt-14 lg:max-w-none text-left">
            @php
                $features = [
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-activity-icon lucide-activity"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>',
                        'title' => 'Website Uptime Monitoring',
                        'desc' => 'Continuously checks your websites for availability and alerts you instantly if downtime is detected, ensuring your online presence is always reliable and minimizing potential business disruptions.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shield-check-icon lucide-shield-check"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
                        'title' => 'SSL Certificate Monitoring',
                        'desc' => 'Automatically tracks SSL certificate status and expiry dates, notifying you before certificates expire to prevent security warnings and maintain user trust.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cable-icon lucide-cable"><path d="M17 21v-2a1 1 0 0 1-1-1v-1a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1a1 1 0 0 1-1 1"/><path d="M19 15V6.5a1 1 0 0 0-7 0v11a1 1 0 0 1-7 0V9"/><path d="M21 21v-2h-4"/><path d="M3 5h4V3"/><path d="M7 5a1 1 0 0 1 1 1v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1V3"/></svg>',
                        'title' => 'Outbound Link Checking',
                        'desc' => 'Scans your website for outbound links, verifying their status and alerting you to broken or malicious links to protect your site’s reputation and user experience.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-server-icon lucide-server"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>',
                        'title' => 'Server Health Monitoring',
                        'desc' => 'Monitors server metrics such as CPU, RAM, and disk usage, providing real-time insights and alerts to help you prevent resource exhaustion and maintain optimal server performance.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-notebook-tabs-icon lucide-notebook-tabs"><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M15 2v20"/><path d="M15 7h5"/><path d="M15 12h5"/><path d="M15 17h5"/></svg>',
                        'title' => 'API Endpoint Monitoring',
                        'desc' => 'Regularly tests your API endpoints for availability and response time, alerting you to failures or slowdowns so you can maintain reliable integrations and services.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bell-ring-icon lucide-bell-ring"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M22 8c0-2.3-.8-4.3-2-6"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/><path d="M4 2C2.8 3.7 2 5.7 2 8"/></svg>',
                        'title' => 'Custom Notification Channels',
                        'desc' => 'Supports multiple notification channels including email and webhooks, allowing you to receive alerts in your preferred way and integrate with your existing workflows.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cloud-upload-icon lucide-cloud-upload"><path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/></svg>',
                        'title' => 'Automated Backups',
                        'desc' => 'Schedule and manage automated backups for your servers and databases, ensuring your critical data is always protected and easily restorable in case of failure.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text-icon lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
                        'title' => 'Incident Logging and Reporting',
                        'desc' => 'Keeps a detailed log of incidents, outages, and performance issues, providing you with actionable reports and historical data for analysis and compliance.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard-icon lucide-layout-dashboard"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
                        'title' => 'Monitoring Dashboard',
                        'desc' => 'Visualize the status and performance of all your resources in a unified dashboard, making it easy to spot issues and track trends at a glance.',
                    ],
                ];
            @endphp
            <div class="my-[1em] grid max-w-xl grid-cols-1 gap-x-8 px-4 gap-y-16 lg:max-w-none lg:grid-cols-3">
                @foreach($features as $feature)
                    <div class="flex flex-col">
                        <div class="flex items-center gap-x-3 text-xl text-white">
                            <span class="text-emerald-500">
                            {!! $feature['icon'] !!}
                            </span>
                            {{ $feature['title'] }}
                        </div>
                        <div class="mt-4 flex flex-auto flex-col text-sm leading-6 text-zinc-400">
                            {{ $feature['desc'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div id="screenshots" class="text-center text-4xl font-bold pt-10 text-white">Screenshots</div>
        <div
            x-data="{ openModal: null }"
            class="mx-auto mt-16 max-w-2xl sm:mt-14 lg:mt-14 lg:max-w-none text-left mb-16"
        >
            @php
                $screenshots = [
                    [
                        'img' => '/images/screenshot-dashboard.png',
                        'title' => 'Intuitive Dashboard',
                        'subtitle' => 'Monitor all your resources in one place with real-time updates.',
                    ],
                    [
                        'img' => '/images/screenshot-uptime.png',
                        'title' => 'Server Health Monitoring',
                        'subtitle' => 'Track server performance with real-time metrics for CPU, RAM, and disk usage through interactive charts and customizable alert thresholds.',
                    ],
                    [
                        'img' => '/images/screenshot-notification-channels.png',
                        'title' => 'Custom Notification Channels',
                        'subtitle' => 'Configure webhook notifications with custom URLs, request methods, and payload structures to seamlessly integrate alerts with your external systems and services.',
                    ],
                    [
                        'img' => '/images/screenshot-api.png',
                        'title' => 'API Endpoint Monitoring',
                        'subtitle' => 'Monitor API endpoints with customizable assertions, response time tracking, and detailed performance charts to ensure your integrations stay reliable and performant.',
                    ],
                    [
                        'img' => '/images/screenshot-ploi-integration.png',
                        'title' => 'Ploi Integration',
                        'subtitle' => 'Import and monitor your Ploi servers and sites with real-time status tracking, version info, and one-click synchronization to Checkybot monitoring.',
                    ],
                    [
                        'img' => '/images/screenshot-error-reporting.png',
                        'title' => 'Exception Tracking & Debugging',
                        'subtitle' => 'Capture detailed error reports with full stack traces, request data, and context information to quickly diagnose and resolve issues in your Laravel applications.',
                    ]
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 px-4">
                @foreach($screenshots as $i => $ss)
                    <div
                        class="flex flex-col items-center shadow cursor-pointer"
                        @click="openModal = {{ $i }}"
                    >
                        <img src="{{ $ss['img'] }}" alt="{{ $ss['title'] }}"
                             class="rounded border-4 border-zinc-800 mb-4 w-full object-cover max-h-56">
                        <div class="text-lg font-bold text-white self-start">{{ $ss['title'] }}</div>
                        <div class="text-xs text-zinc-400 mt-1 self-start pb-4">{{ $ss['subtitle'] }}</div>
                    </div>
                @endforeach
            </div>

            <template x-if="openModal !== null">
                <div
                    x-cloak
                    x-show="true"
                    x-transition
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
                    @click.self="openModal = null"
                    @keydown.window.escape="openModal = null"
                >
                    <div
                        x-transition
                        class="bg-zinc-900 rounded-lg shadow-lg w-full max-w-3xl p-6 relative flex flex-col items-center"
                    >
                        <button
                            class="absolute top-2 right-2 text-white text-3xl leading-none"
                            @click="openModal = null"
                        >×
                        </button>

                        <div class="mb-1 text-xl font-bold text-white self-start"
                             x-text="{{ json_encode($screenshots) }}[openModal].title"></div>
                        <div class="mb-4 text-sm text-zinc-400 self-start"
                             x-text="{{ json_encode($screenshots) }}[openModal].subtitle"></div>
                        <img :src="'{{ url('/') }}' + {{ json_encode($screenshots) }}[openModal].img"
                             class="rounded w-full object-contain max-h-[80vh] shadow-lg border-4 border-zinc-800"/>
                    </div>
                </div>
            </template>

        </div>

    </div>
</main>
