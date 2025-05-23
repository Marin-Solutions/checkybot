<main class="max-w-5xl mx-auto">
    <x-nav/>
    <div class="text-center pt-12 md:pt-24">
        <h1 class="text-3xl font-bold tracking-tight text-white md:text-6xl px-4">Welcome to Coolify<span
                class="text-yellow-500">.</span></h1>
        <p class="text-base pt-4 text-neutral-400 px-4">An open-source & self-hostable Heroku / Netlify / Vercel
            alternative.</p>
        <div class="pb-10 pt-1 text-xs flex gap-6 justify-center flex-row flex-wrap">
            @php
                $heroNavItems = [
                    [
                        'href' => '#features',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rocket-icon lucide-rocket"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
                        'label' => 'Features',
                        'iconColor' => null
                    ],
                    [
                        'href' => '/screenshots',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-camera-icon lucide-camera"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>',
                        'label' => 'Screenshots',
                        'iconColor' => null
                    ],
                    [
                        'href' => '/videos',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-video-icon lucide-video"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
                        'label' => 'Videos',
                        'iconColor' => null
                    ],
                    [
                        'href' => '/building-in-live-stream',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bot-message-square-icon lucide-bot-message-square"><path d="M12 6V2H8"/><path d="m8 18-4 4V8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2Z"/><path d="M2 12h2"/><path d="M9 11v2"/><path d="M15 11v2"/><path d="M20 12h2"/></svg>',
                        'label' => 'Building in live-stream',
                        'iconColor' => 'text-violet-500'
                    ]
                ];
            @endphp
            @foreach($heroNavItems as $item)
                <a href="{{ $item['href'] }}"
                   class="hover:underline cursor-pointer text-white flex gap-2 items-center justify-center text-xs">
                    {!! str_replace('<svg', '<svg class="' . ($item['iconColor'] ?? 'text-yellow-500') . ' size-5"', $item['icon']) !!}
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>

        <div class="pb-2 pt-2 flex justify-center text-centerb gap-8 px-4">
            @php
                $plans = [
                    [
                        'href' => '/cloud',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cloud-icon lucide-cloud"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>',
                        'label' => 'Cloud',
                        'stat' => '2,013+',
                        'desc' => 'customers in the cloud.'
                    ],
                    [
                        'href' => '/self-hosted',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ghost-icon lucide-ghost"><path d="M9 10h.01"/><path d="M15 10h.01"/><path d="M12 2a8 8 0 0 0-8 8v12l3-3 2.5 2.5L12 19l2.5 2.5L17 19l3 3V10a8 8 0 0 0-8-8z"/></svg>',
                        'label' => 'Self Hosted',
                        'stat' => '186,861+',
                        'desc' => 'self-hosted instances.'
                    ]
                ];
            @endphp
            @foreach($plans as $plan)
                <div>
                    <a href="{{ $plan['href'] }}"
                       class="text-xs sm:text-base font-bold rounded p-4 px-2 text-white bg-zinc-800/85 hover:bg-zinc-800 flex gap-2 lg:w-64 justify-center">
                        {!! $plan['icon'] !!}
                        {{ $plan['label'] }}
                    </a>
                    <div
                        class="text-neutral-400 sm:text-base text-xs sm:flex-row flex flex-col gap-1 pt-2 sm:justify-center">
                        <span
                            class="text-yellow-500 mt-[0.1rem] font-bold font-mono">{{ $plan['stat'] }}</span> {{ $plan['desc'] }}
                    </div>
                </div>
            @endforeach
        </div>

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

        <div class="text-center text-4xl font-bold pt-10 text-white">Features</div>
        <div class="mx-auto mt-16 max-w-2xl sm:mt-14 lg:mt-14 lg:max-w-none text-left">
            @php
                $features = [
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-code-xml-icon lucide-code-xml"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>',
                        'title' => 'Any Language',
                        'desc' => 'Coolify is compatible with a wide range of programming languages and frameworks, enabling you to launch static websites, APIs, backends, databases, services, and other types of applications.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-server-icon lucide-server text-green-500"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/></svg>',
                        'title' => 'Database Ready',
                        'desc' => 'Deploy and manage popular databases with ease, including PostgreSQL, MySQL, MongoDB, and more.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-git-branch-icon lucide-git-branch text-blue-500"><line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>',
                        'title' => 'Git Integration',
                        'desc' => 'Connect your Git repositories for seamless deployments and automated builds.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shield-check-icon lucide-shield-check text-purple-500"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
                        'title' => 'Secure by Default',
                        'desc' => 'Automatic SSL certificates and security best practices out of the box.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cloud-icon lucide-cloud text-cyan-500"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>',
                        'title' => 'Cloud & Self-Hosted',
                        'desc' => 'Deploy to your own infrastructure or use our managed cloud service.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rocket-icon lucide-rocket text-pink-500"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
                        'title' => 'Fast Deployments',
                        'desc' => 'Experience rapid build and deployment times for your projects.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users-icon lucide-users text-orange-500"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                        'title' => 'Team Collaboration',
                        'desc' => 'Invite your team and manage access with role-based permissions.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-terminal-icon lucide-terminal text-lime-500"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>',
                        'title' => 'CLI & API',
                        'desc' => 'Automate and control everything via command line or REST API.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-monitor-icon lucide-monitor text-red-500"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>',
                        'title' => 'Monitoring',
                        'desc' => 'Built-in monitoring and logging for your applications.',
                    ],
                    [
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-columns3-cog-icon lucide-columns-3-cog"><path d="M10.5 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5.5"/><path d="m14.3 19.6 1-.4"/><path d="M15 3v7.5"/><path d="m15.2 16.9-.9-.3"/><path d="m16.6 21.7.3-.9"/><path d="m16.8 15.3-.4-1"/><path d="m19.1 15.2.3-.9"/><path d="m19.6 21.7-.4-1"/><path d="m20.7 16.8 1-.4"/><path d="m21.7 19.4-.9-.3"/><path d="M9 3v18"/><circle cx="18" cy="18" r="3"/></svg>',
                        'title' => 'Customizable',
                        'desc' => 'Tweak and extend Coolify to fit your workflow and needs.',
                    ],
                ];
            @endphp
            <div class="my-[1em] grid max-w-xl grid-cols-1 gap-x-8 px-4 gap-y-16 lg:max-w-none lg:grid-cols-3">
                @foreach($features as $feature)
                    <div class="flex flex-col">
                        <div class="flex items-center gap-x-3 text-xl text-white">
                            <span class="text-yellow-500">
                            {!! $feature['icon'] !!}
                            </span>
                            {{ $feature['title'] }}
                        </div>
                        <div class="mt-4 flex flex-auto flex-col text-sm leading-6 text-neutral-400">
                            {{ $feature['desc'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</main>
