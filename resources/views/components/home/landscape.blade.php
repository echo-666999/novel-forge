{{-- 首页水墨远山插画：三层山影、晨日、飞鸟与小舟，随明暗主题变色；底部由页面渐变层过渡。 --}}
<svg {{ $attributes->merge(['class' => 'w-full']) }} viewBox="0 0 1440 520" preserveAspectRatio="xMidYMax slice" fill="none" aria-hidden="true">
    <defs>
        <radialGradient id="nf-sun" cx="0.5" cy="0.5" r="0.5">
            <stop offset="0" stop-color="#f59e0b" stop-opacity="0.55" />
            <stop offset="0.6" stop-color="#f59e0b" stop-opacity="0.18" />
            <stop offset="1" stop-color="#f59e0b" stop-opacity="0" />
        </radialGradient>
    </defs>
    <circle cx="1090" cy="190" r="150" fill="url(#nf-sun)" />
    <circle cx="1090" cy="190" r="46" class="fill-amber-500/50 dark:fill-amber-300/40" />
    <g class="stroke-stone-500/60 dark:stroke-stone-400/50" stroke-width="2" stroke-linecap="round">
        <path d="M820 150 q8 -8 16 0 q8 -8 16 0" />
        <path d="M870 120 q6 -6 12 0 q6 -6 12 0" />
        <path d="M790 185 q5 -5 10 0 q5 -5 10 0" />
    </g>
    <path class="fill-stone-300/45 dark:fill-stone-700/30" d="M0 360 C120 300 200 250 300 280 C380 300 420 220 520 200 C600 185 640 260 720 270 C820 280 860 210 960 190 C1040 175 1100 240 1180 250 C1280 262 1340 220 1440 230 V520 H0Z" />
    <path class="fill-stone-400/40 dark:fill-stone-600/30" d="M0 420 C100 380 180 330 260 350 C340 370 380 300 470 290 C560 280 600 360 700 370 C800 380 860 320 940 330 C1020 340 1080 300 1160 310 C1260 322 1340 380 1440 360 V520 H0Z" />
    <path class="fill-stone-500/35 dark:fill-stone-500/25" d="M0 470 C140 440 260 420 380 440 C500 460 600 410 760 420 C900 430 1000 470 1140 455 C1260 442 1360 420 1440 430 V520 H0Z" />
    <g class="stroke-stone-500/40 dark:stroke-stone-400/30" stroke-width="1.5" stroke-linecap="round">
        <path d="M560 492 h60 M640 492 h24 M590 502 h40" />
        <path d="M596 486 l30 0 l-6 6 h-18 z" class="fill-stone-500/50 dark:fill-stone-400/40" />
        <path d="M611 486 v-16 l10 12" />
    </g>
</svg>
