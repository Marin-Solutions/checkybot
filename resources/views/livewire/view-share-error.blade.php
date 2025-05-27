<div class="container mx-auto px-4 flex flex-col gap-y-8 py-8">
    <header>
        <h1
            class="fi-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-3xl"
        >
            {{ $this->error->projects->name }}
        </h1>
    </header>

    {{ $this->errorInfolist }}

    @push('styles')
        <style>
            .arg_section .fi-section-content-ctn > .fi-section-content {
                padding: .5rem 1rem !important;
                margin-top: .5rem;
                margin-bottom: 1.5rem;
            }
        </style>
    @endpush

    <div
        x-data="{
                stacktrace: @js($this->error->stacktrace),
                selectedIndex: 0,
                kolor: @js($this->error->context)
            }"
        x-init="console.log(kolor)"
    >
        <x-filament::section>
            <x-slot name="heading">
                Stacktrace
            </x-slot>
            <div
                class="h-[670px] flex divide-x dark:divide-gray-700"
            >
                <div class="overflow-y-auto scrollbar-hide">
                    <ul class="divide-y dark:divide-gray-700 pb-6">
                        <template x-for="(stack, index) in stacktrace" :key="index">
                            <li
                                style="word-break: break-all; white-space: normal; width: 300px"
                                class="px-6 py-3 text-xs"
                                :class="[selectedIndex === index ? 'bg-blue-700 text-white' : 'hover:bg-blue-200 hover:text-gray-950 cursor-pointer', index === 0 ? '!border-t-0' : '']"
                                @click="selectedIndex = index"
                            >
                                <span x-text="stack.class ?? stack.file"></span>
                                : <span x-text="stack.line_number"></span><br>
                                <b x-text="stack.method"></b>
                            </li>
                        </template>
                    </ul>
                </div>
                <div class="flex-1">
                        <pre x-text="stacktrace[selectedIndex].file + ' : ' + stacktrace[selectedIndex].line_number"
                             class="mb-4 pl-2 text-xs text-slate-600 dark:text-slate-400 font-medium"></pre>
                    <ul>
                        <template x-for="(code, key, index) in stacktrace[selectedIndex].code_snippet" :key="key">
                            <li>
                                <div class="flex">
                                    <pre class="px-2 text-sm" x-text="key"
                                         :class="key == stacktrace[selectedIndex].line_number ? 'bg-sky-300 text-gray-950':''"
                                    ></pre>
                                    <pre class="pl-6 text-sm w-full" x-text="code"
                                         :class="key == stacktrace[selectedIndex].line_number ? 'bg-sky-200 text-gray-950':'hover:bg-sky-100 hover:text-gray-950'"
                                    ></pre>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
            <div x-show="stacktrace[selectedIndex].arguments.length" class="mt-2">
                <x-filament::fieldset>
                    <x-slot name="label">
                        Arguments
                    </x-slot>

                    <ul>
                        <template x-for="(arg, key, index) in stacktrace[selectedIndex].arguments" :key="key">
                            <li>
                                <pre class="text-xs" x-text="'$' + arg.name + ':' + arg.original_type"></pre>
                                <x-filament::section class="arg_section">
                                        <pre class="text-xs"
                                             x-text="typeof arg.value === 'string' ? arg.value : JSON.stringify(arg.value, null, 2)"></pre>
                                </x-filament::section>
                            </li>
                        </template>
                    </ul>
                </x-filament::fieldset>
            </div>
        </x-filament::section>
    </div>

    @if($this->shouldShowRequestInfolist())
        {{ $this->requestInfolist }}
    @endif
</div>
