<main class="max-w-7xl mx-auto">
    <x-nav/>
    <div class="text-center pt-12 md:pt-24">
        <h1 class="text-3xl font-bold tracking-tight text-white md:text-6xl px-4">Unleash Your Apps with Checkybot<span
                class="text-emerald-500">.</span></h1>
        <p class="text-base pt-4 text-zinc-400 px-4">The next-gen open platform to launch, scale, and manage your apps—your way.</p>
        <div class="pb-10 pt-1 text-xs flex gap-6 justify-center flex-row flex-wrap">
            @php
                $heroNavItems = [
//                    [
//                        'href' => '#features',
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5 text-emerald-500 lucide lucide-rocket-icon lucide-rocket"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
//                        'label' => 'Features',
//                        'iconColor' => null
//                    ],
                    [
                        'href' => '/screenshots',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5 text-emerald-500 lucide lucide-camera-icon lucide-camera"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>',
                        'label' => 'Screenshots',
                        'iconColor' => null
                    ],
                    [
                        'href' => '/videos',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5 text-emerald-500 lucide lucide-video-icon lucide-video"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
                        'label' => 'Videos',
                        'iconColor' => null
                    ],
                ];
            @endphp
            @foreach($heroNavItems as $item)
                <a href="{{ $item['href'] }}"
                   class="hover:underline cursor-pointer text-white flex gap-2 items-center justify-center text-xs">
                    {!! $item['icon'] !!}
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>

        {{--        <div class="pb-2 pt-2 flex justify-center text-centerb gap-8 px-4">--}}
        {{--            @php--}}
        {{--                $plans = [--}}
        {{--                    [--}}
        {{--                        'href' => '/cloud',--}}
        {{--                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cloud-icon lucide-cloud"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>',--}}
        {{--                        'label' => 'Cloud',--}}
        {{--                        'stat' => '2,013+',--}}
        {{--                        'desc' => 'customers in the cloud.'--}}
        {{--                    ],--}}
        {{--                    [--}}
        {{--                        'href' => '/self-hosted',--}}
        {{--                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ghost-icon lucide-ghost"><path d="M9 10h.01"/><path d="M15 10h.01"/><path d="M12 2a8 8 0 0 0-8 8v12l3-3 2.5 2.5L12 19l2.5 2.5L17 19l3 3V10a8 8 0 0 0-8-8z"/></svg>',--}}
        {{--                        'label' => 'Self Hosted',--}}
        {{--                        'stat' => '186,861+',--}}
        {{--                        'desc' => 'self-hosted instances.'--}}
        {{--                    ]--}}
        {{--                ];--}}
        {{--            @endphp--}}
        {{--            @foreach($plans as $plan)--}}
        {{--                <div>--}}
        {{--                    <a href="{{ $plan['href'] }}"--}}
        {{--                       class="text-xs sm:text-base font-bold rounded p-4 px-2 text-white bg-zinc-800/85 hover:bg-zinc-800 flex gap-2 lg:w-64 justify-center">--}}
        {{--                        {!! $plan['icon'] !!}--}}
        {{--                        {{ $plan['label'] }}--}}
        {{--                    </a>--}}
        {{--                    <div--}}
        {{--                        class="text-neutral-400 sm:text-base text-xs sm:flex-row flex flex-col gap-1 pt-2 sm:justify-center">--}}
        {{--                        <span--}}
        {{--                            class="text-yellow-500 mt-[0.1rem] font-bold font-mono">{{ $plan['stat'] }}</span> {{ $plan['desc'] }}--}}
        {{--                    </div>--}}
        {{--                </div>--}}
        {{--            @endforeach--}}
        {{--        </div>--}}

        {{-- List of sponsors --}}
        {{--        <div class="text-white pb-4 pt-6 text-base">--}}
        {{--            Special sponsors--}}
        {{--            <a target="_blank" href="/sdfsdf"--}}
        {{--               class="text-yellow-500 border rounded-full px-[0.3rem] ml-1 border-yellow-500 cursor-pointer hover:bg-yellow-500 hover:text-black font-bold text-xs">?</a>--}}
        {{--            <div class="text-xs text-neutral-400">Check out our sponsors' amazing products (click on them)! It helps us--}}
        {{--                grow!--}}
        {{--            </div>--}}
        {{--        </div>--}}

        {{--        <div class="text-center pt-4">--}}
        {{--            <a href="#" class="text-yellow-500 underline text-xs hover:text-white">...and many more...</a>--}}
        {{--        </div>--}}

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
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users-icon lucide-users"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/></svg>',
                        'title' => 'Team Collaboration',
                        'desc' => 'Invite team members, assign roles, and manage permissions, enabling efficient collaboration and shared responsibility for monitoring and incident response.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cloud-upload-icon lucide-cloud-upload"><path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/></svg>',
                        'title' => 'Automated Backups',
                        'desc' => 'Schedule and manage automated backups for your servers and databases, ensuring your critical data is always protected and easily restorable in case of failure.',
                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-folder-sync-icon lucide-folder-sync"><path d="M9 20H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H20a2 2 0 0 1 2 2v.5"/><path d="M12 10v4h4"/><path d="m12 14 1.535-1.605a5 5 0 0 1 8 1.5"/><path d="M22 22v-4h-4"/><path d="m22 18-1.535 1.605a5 5 0 0 1-8-1.5"/></svg>',
//                        'title' => 'Backup Storage Integrations',
//                        'desc' => 'Connect to various remote storage providers such as SFTP, FTP, AWS S3, and custom S3-compatible services for flexible and secure backup storage options.',
//                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text-icon lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
                        'title' => 'Incident Logging and Reporting',
                        'desc' => 'Keeps a detailed log of incidents, outages, and performance issues, providing you with actionable reports and historical data for analysis and compliance.',
                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-timer-reset-icon lucide-timer-reset"><path d="M10 2h4"/><path d="M12 14v-4"/><path d="M4 13a8 8 0 0 1 8-7 8 8 0 1 1-5.3 14L4 17.6"/><path d="M9 17H4v5"/></svg>',
//                        'title' => 'Customizable Monitoring Intervals',
//                        'desc' => 'Set monitoring intervals to fit your needs, from every minute to every 24 hours, balancing resource usage and responsiveness for different types of checks.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-key-icon lucide-key"><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>',
//                        'title' => 'Role-Based Access Control',
//                        'desc' => 'Manage user access with fine-grained roles and permissions, ensuring only authorized team members can view or modify sensitive monitoring and configuration data.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rocket-icon lucide-rocket"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
//                        'title' => 'Fast Deployments',
//                        'desc' => 'Deploy monitoring agents and integrations quickly with simple setup steps, reducing onboarding time and allowing you to start protecting your infrastructure immediately.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square-terminal-icon lucide-square-terminal"><path d="m7 11 2-2-2-2"/><path d="M11 13h4"/><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/></svg>',
//                        'title' => 'CLI & API Access',
//                        'desc' => 'Automate and control monitoring tasks via a command-line interface or REST API, enabling seamless integration with your DevOps pipelines and custom tools.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-lock-icon lucide-lock"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
//                        'title' => 'Built-in Security Best Practices',
//                        'desc' => 'Implements security best practices by default, including automatic SSL, secure authentication, and data encryption, helping you safeguard your infrastructure effortlessly.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clock-alert-icon lucide-clock-alert"><path d="M12 6v6l4 2"/><path d="M16 21.16a10 10 0 1 1 5-13.516"/><path d="M20 11.5v6"/><path d="M20 21.5h.01"/></svg>',
//                        'title' => 'Customizable Alerts',
//                        'desc' => 'Configure alert thresholds and notification preferences for each monitored resource, ensuring you only receive relevant and actionable alerts.',
//                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard-icon lucide-layout-dashboard"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
                        'title' => 'Monitoring Dashboard',
                        'desc' => 'Visualize the status and performance of all your resources in a unified dashboard, making it easy to spot issues and track trends at a glance.',
                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bug-icon lucide-bug"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>',
//                        'title' => 'Error Reporting for Applications',
//                        'desc' => 'Integrate with your applications to capture and report errors, helping you identify and resolve bugs quickly to improve software reliability.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-no-axes-combined-icon lucide-chart-no-axes-combined"><path d="M12 16v5"/><path d="M16 14v7"/><path d="M20 10v11"/><path d="m22 3-8.646 8.646a.5.5 0 0 1-.708 0L9.354 8.354a.5.5 0 0 0-.707 0L2 15"/><path d="M4 18v3"/><path d="M8 14v7"/></svg>',
//                        'title' => 'Historical Performance Analytics',
//                        'desc' => 'Access historical data and analytics for your monitored resources, enabling you to identify patterns, optimize performance, and plan for future growth.',
//                    ],
//                    [
//                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plug-icon lucide-plug"><path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/></svg>',
//                        'title' => 'Easy Integration with Existing Tools',
//                        'desc' => 'Integrates smoothly with popular tools and platforms, allowing you to extend Checkybot’s capabilities and fit it into your existing workflow.',
//                    ],
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

    </div>
</main>
