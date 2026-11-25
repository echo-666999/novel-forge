{{-- 首页伏笔时间线插画：第3章埋设、第30/60章强化、第100章揭示的主伏笔，以及两条较短的伏笔线；内容为示意。 --}}
<svg {{ $attributes->merge(['class' => 'w-full h-auto']) }} viewBox="0 0 1100 270" fill="none" role="img" aria-label="伏笔时间线示意：第3章埋设，第30章、第60章强化，第100章揭示">
    <path d="M40 200 H1060" class="stroke-stone-300 dark:stroke-white/15" stroke-width="2" />
    @foreach ([1, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $chapter)
        @php($x = 40 + ($chapter - 1) * 1020 / 99)
        <path d="M{{ $x }} 195 v10" class="stroke-stone-300 dark:stroke-white/20" stroke-width="1.5" />
        <text x="{{ $x }}" y="228" text-anchor="middle" class="fill-stone-400" font-size="12">{{ $chapter }}</text>
    @endforeach
    <path d="M81.2 200 C180 100 360 100 462.4 200" class="stroke-stone-400/70 dark:stroke-stone-500" stroke-width="2" />
    <path d="M153.3 200 C330 70 560 70 720 200" class="stroke-stone-400/70 dark:stroke-stone-500" stroke-width="2" stroke-dasharray="6 6" />
    <path d="M60.6 200 C300 -20 820 -20 1060 200" class="stroke-amber-600 dark:stroke-amber-300" stroke-width="3" stroke-linecap="round" />
    <g class="fill-stone-400 dark:fill-stone-500">
        <circle cx="81.2" cy="200" r="4" /><circle cx="462.4" cy="200" r="4" />
        <circle cx="153.3" cy="200" r="4" /><circle cx="720" cy="200" r="4" />
    </g>
    <circle cx="60.6" cy="200" r="7" class="fill-amber-600 dark:fill-amber-300" />
    <circle cx="336.7" cy="61.4" r="6" class="fill-white stroke-amber-600 dark:fill-stone-900 dark:stroke-amber-300" stroke-width="3" />
    <circle cx="673.5" cy="41.6" r="6" class="fill-white stroke-amber-600 dark:fill-stone-900 dark:stroke-amber-300" stroke-width="3" />
    <circle cx="1060" cy="200" r="9" class="fill-amber-600 dark:fill-amber-300" />
    <circle cx="1060" cy="200" r="16" class="stroke-amber-600/40 dark:stroke-amber-300/40" stroke-width="2" />
    <g font-size="13" class="fill-stone-700 dark:fill-stone-200">
        <text x="40" y="254">埋设 · 第3章</text>
        <text x="336.7" y="40" text-anchor="middle">强化 · 第30章</text>
        <text x="673.5" y="20" text-anchor="middle">强化 · 第60章</text>
        <text x="1060" y="254" text-anchor="end" font-weight="600" class="fill-amber-700 dark:fill-amber-300">揭示 · 第100章</text>
    </g>
</svg>
