<nav class="flex items-center w-full p-2 min-h-16 gap-0 lg:gap-2 relative px-4">
    <a href="/">
        <span class="text-2xl block font-bold text-gray-200">Coolify</span>
    </a>
    <div class="flex-1"></div>
    <div class="md:flex flex-col md:flex-row absolute md:relative top-full right-0 bg-coolgray-300 md:bg-transparent w-48 md:w-auto mt-2 md:mt-0 rounded md:rounded-none p-2 md:p-0 z-50 mr-4 hidden">
        @php
            $navItems = [
                [
                    'href' => '/pricing',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-hand-coins-icon lucide-hand-coins"><path d="M11 15h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17"/><path d="m7 21 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 16 6 6"/><circle cx="16" cy="9" r="2.9"/><circle cx="6" cy="5" r="3"/></svg>',
                    'label' => 'Pricing',
                    'badge' => null,
                    'iconColor' => null
                ],
                [
                    'href' => '/docs',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-book-icon lucide-book"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg>',
                    'label' => 'Docs',
                    'badge' => null,
                    'iconColor' => null
                ],
                [
                    'href' => '/merch',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square-percent-icon lucide-square-percent"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="m15 9-6 6"/><path d="M9 9h.01"/><path d="M15 15h.01"/></svg>',
                    'label' => 'Merch',
                    'badge' => null,
                    'iconColor' => null
                ],
                [
                    'href' => '/community',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-gamepad2-icon lucide-gamepad-2"><line x1="6" x2="10" y1="11" y2="11"/><line x1="8" x2="8" y1="9" y2="13"/><line x1="15" x2="15.01" y1="12" y2="12"/><line x1="18" x2="18.01" y1="10" y2="10"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"/></svg>',
                    'label' => 'Community',
                    'badge' => '(12k+)',
                    'iconColor' => 'text-violet-500'
                ]
            ];
        @endphp
        @foreach($navItems as $item)
            <a href="{{ $item['href'] }}" class="flex gap-2 rounded py-2 px-4 relative items-center hover:bg-zinc-800">
                {!! str_replace('<svg', '<svg class="' . ($item['iconColor'] ?? 'text-yellow-500') . ' size-5"', $item['icon']) !!}
                {{ $item['label'] }}
                @if($item['badge'])
                    <span class="text-xs self-start">
                            {{ $item['badge'] }}
                        </span>
                @endif
            </a>
        @endforeach
        <a href="/login" class="p-2 px-4 rounded text-white relative border border-violet-900 hover:bg-violet-900 bg-violet-900/45 font-bold text-xs self-center">Login</a>
    </div>
</nav>
