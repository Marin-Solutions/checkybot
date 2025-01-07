<div class="flex items-center w-full gap-2">
    @php
        $state = $getState();
    @endphp

    {{-- Debug output --}}
    <div>
        Raw state: {{ var_export($state, true) }}
    </div>
</div> 