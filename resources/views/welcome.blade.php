@php
    $adminUrl = route('filament.admin.pages.production-overview');
    $signedIn = auth()->check();
    $features = [
        ['title' => '故事蓝图', 'body' => '从一句创意出发，逐步确认设定、人物、世界规则与分卷大纲；每章写明必须完成的情节推进点和章末钩子。',
            'icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25'],
        ['title' => '固定阶段流水线', 'body' => '细纲、正文、检查、评审、提交按固定顺序推进，每章执行次数有硬上限；中断后从检查点继续，不会无限循环。',
            'icon' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z'],
        ['title' => '独立评审与定点修订', 'body' => '推进检查确认情节真的向前走；独立评审给出证据，不合格时只修改有问题的段落，而不是整章重写。',
            'icon' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z'],
        ['title' => '正式提交与故事状态', 'body' => '只有通过验收的版本才会正式提交。人物、事实与伏笔随提交演进，可按章节回放；失败不会污染正式剧情。',
            'icon' => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125'],
        ['title' => '前文记忆检索', 'body' => '中文分词、实体别名、模糊匹配与语义向量四路检索已提交的正文，写新章时自动带上相关的前文原句。',
            'icon' => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z'],
        ['title' => '费用清清楚楚', 'body' => '每次请求按官方单价估算，区分缓存、闲时与长上下文；按书设置预算，花了多少、还要多少，一目了然。',
            'icon' => 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z'],
    ];
    $flow = ['创意', '设定', '大纲', '细纲', '正文', '评审', '提交', '导出'];
    $stages = [['细纲', '已完成'], ['正文初稿', '已完成'], ['推进检查', '通过'], ['独立评审', '通过'], ['正式提交', '进行中']];
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="NovelForge 小说工坊：面向一位作者的私有 AI 长篇小说创作工作台。">
        <title>{{ config('app.name') }}</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css'])
        @endif
    </head>
    <body class="min-h-screen overflow-x-hidden bg-[#faf7f2] text-stone-900 antialiased dark:bg-[#12100d] dark:text-stone-100">
        <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-[36rem] bg-[radial-gradient(60rem_28rem_at_70%_-10%,rgba(217,119,6,0.14),transparent)] dark:bg-[radial-gradient(60rem_28rem_at_70%_-10%,rgba(251,191,36,0.10),transparent)]"></div>

        <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                <span class="grid size-9 place-items-center rounded-lg bg-stone-900 font-serif text-lg text-amber-300 dark:bg-amber-400 dark:text-stone-900">工</span>
                <span class="font-medium tracking-tight">NovelForge <span class="text-stone-500 dark:text-stone-400">· 小说工坊</span></span>
            </a>
            <a href="{{ $adminUrl }}" class="rounded-full px-4 py-2 text-sm text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 dark:text-stone-300 dark:hover:bg-white/10 dark:hover:text-white">
                {{ $signedIn ? '管理后台' : '登录' }}
            </a>
        </header>

        <main>
            <div class="relative isolate">
            <x-home.landscape class="absolute inset-x-0 bottom-0 -z-10 h-64 sm:h-80 lg:h-[26rem]" />
            <div aria-hidden="true" class="absolute inset-x-0 bottom-0 -z-10 h-24 bg-gradient-to-b from-transparent to-[#faf7f2] dark:to-[#12100d]"></div>
            <section class="mx-auto grid max-w-6xl items-center gap-14 px-6 pb-44 pt-12 lg:grid-cols-[1.15fr_1fr] lg:pb-56 lg:pt-20">
                <div>
                    <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-amber-700/20 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 dark:border-amber-300/20 dark:bg-amber-300/10 dark:text-amber-200">
                        <span class="size-1.5 rounded-full bg-amber-600 dark:bg-amber-300"></span>AI 长篇小说创作工作台
                    </p>
                    <h1 class="font-serif text-4xl leading-tight font-semibold tracking-tight sm:text-5xl lg:text-6xl">
                        把一个创意，<br>锻造成一部<span class="text-amber-700 dark:text-amber-300">长篇</span>。
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-8 text-stone-600 dark:text-stone-300">
                        为一位作者打造的私有创作工作台。你负责创意与判断，系统承担规划、逐章生成、评审修订与跨章记忆——人物不走样，伏笔不丢失，情节始终向前。
                    </p>
                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        <a href="{{ $adminUrl }}" class="group inline-flex items-center gap-2 rounded-full bg-stone-900 px-6 py-3 text-sm font-medium text-white shadow-lg shadow-stone-900/10 transition hover:bg-amber-700 dark:bg-amber-400 dark:text-stone-900 dark:hover:bg-amber-300">
                            {{ $signedIn ? '继续创作' : '进入管理后台' }}
                            <svg class="size-4 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                        </a>
                        <a href="#flow" class="rounded-full px-5 py-3 text-sm font-medium text-stone-700 transition hover:bg-stone-900/5 dark:text-stone-300 dark:hover:bg-white/10">了解工作方式</a>
                    </div>
                    <p class="mt-8 text-sm text-stone-500 dark:text-stone-400">单人私有部署 · 支持 OpenAI 与 DeepSeek · 每一步可追溯</p>
                </div>

                <figure aria-label="章节流水线示意" class="relative">
                    <div class="absolute -inset-4 -z-10 rotate-2 rounded-3xl bg-amber-100/60 dark:bg-amber-300/5"></div>
                    <div class="rounded-2xl border border-stone-900/10 bg-white p-6 shadow-xl shadow-stone-900/5 dark:border-white/10 dark:bg-stone-900">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs text-stone-500 dark:text-stone-400">章节流水线</p>
                                <p class="mt-1 font-serif text-xl font-semibold">第十二章</p>
                            </div>
                            <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-300/15 dark:text-amber-200">生成中</span>
                        </div>
                        <ol class="mt-6 space-y-3">
                            @foreach ($stages as [$stage, $state])
                                <li class="flex items-center gap-3">
                                    @if ($loop->last)
                                        <span class="grid size-6 place-items-center rounded-full border-2 border-amber-600 dark:border-amber-300"><span class="size-2 animate-pulse rounded-full bg-amber-600 dark:bg-amber-300"></span></span>
                                    @else
                                        <span class="grid size-6 place-items-center rounded-full bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900">
                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </span>
                                    @endif
                                    <span class="flex-1 text-sm {{ $loop->last ? 'font-medium' : 'text-stone-600 dark:text-stone-300' }}">{{ $stage }}</span>
                                    <span class="text-xs text-stone-400">{{ $state }}</span>
                                </li>
                            @endforeach
                        </ol>
                        <div class="mt-6 border-t border-dashed border-stone-900/10 pt-5 dark:border-white/10">
                            <div class="flex justify-between text-xs text-stone-500 dark:text-stone-400"><span>本章执行次数</span><span>6 / 20</span></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-white/10"><div class="h-full w-[30%] rounded-full bg-amber-600 dark:bg-amber-300"></div></div>
                            <p class="mt-4 text-sm leading-6 text-stone-600 dark:text-stone-300">本章推进点：主角拿到第一条关键线索，并在章末留下新的悬念。</p>
                        </div>
                    </div>
                    <figcaption class="mt-3 text-center text-xs text-stone-400">示意图</figcaption>
                </figure>
            </section>
            </div>

            <section class="border-y border-stone-900/5 bg-white/60 py-24 dark:border-white/5 dark:bg-white/[0.02]">
                <div class="mx-auto max-w-6xl px-6">
                    <div class="max-w-2xl">
                        <h2 class="font-serif text-3xl font-semibold tracking-tight">长篇写作里最难的部分，交给系统</h2>
                        <p class="mt-4 text-stone-600 dark:text-stone-300">不需要编写提示词，也不需要操作数据库；在中文后台里确认、调整、验收即可。</p>
                    </div>
                    <div class="mt-14 grid gap-px overflow-hidden rounded-2xl border border-stone-900/10 bg-stone-900/10 sm:grid-cols-2 lg:grid-cols-3 dark:border-white/10 dark:bg-white/10">
                        @foreach ($features as $feature)
                            <div class="bg-[#faf7f2] p-7 transition hover:bg-white dark:bg-[#12100d] dark:hover:bg-stone-900">
                                <span class="grid size-10 place-items-center rounded-xl bg-amber-100 text-amber-800 dark:bg-amber-300/10 dark:text-amber-300">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" /></svg>
                                </span>
                                <h3 class="mt-5 font-medium">{{ $feature['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ $feature['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="mx-auto grid max-w-6xl items-center gap-14 px-6 py-24 lg:grid-cols-2">
                <div class="order-2 lg:order-1">
                    <x-home.manuscript class="mx-auto max-w-md lg:max-w-none" />
                </div>
                <div class="order-1 lg:order-2">
                    <p class="text-sm font-medium text-amber-700 dark:text-amber-300">一章是怎样写成的</p>
                    <h2 class="mt-3 font-serif text-3xl font-semibold tracking-tight">像老编辑一样，逐段批注</h2>
                    <p class="mt-4 leading-7 text-stone-600 dark:text-stone-300">正文写完后，系统先对照细纲检查：本章承诺的推进点是否真的发生，章末钩子是否立得住。评审意见落到具体段落，修订也只改这些段落。</p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([['推进检查', '对照细纲逐条核对，不通过就不能提交。'], ['证据式评审', '每条意见都引用原文，说清问题在哪里。'], ['定稿入档', '通过验收的版本才会盖章成为正式剧情。']] as [$title, $body])
                            <li class="flex gap-4">
                                <span class="mt-1 grid size-6 shrink-0 place-items-center rounded-full bg-red-700/10 text-red-700 dark:bg-red-400/10 dark:text-red-400">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                </span>
                                <span><span class="font-medium">{{ $title }}</span><span class="block text-sm leading-6 text-stone-600 dark:text-stone-400">{{ $body }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            <section class="border-y border-stone-900/5 bg-white/60 py-24 dark:border-white/5 dark:bg-white/[0.02]">
                <div class="mx-auto max-w-6xl px-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-medium text-amber-700 dark:text-amber-300">跨章记忆</p>
                        <h2 class="mt-3 font-serif text-3xl font-semibold tracking-tight">第 3 章埋下的伏笔，第 100 章依然记得</h2>
                        <p class="mt-4 leading-7 text-stone-600 dark:text-stone-300">每条伏笔都记录埋设、强化与揭示的原文证据。写到后面的章节时，系统会主动提醒哪些线索该推进、哪些该收束。</p>
                    </div>
                    <div class="mt-12 overflow-x-auto rounded-2xl border border-stone-900/10 bg-[#faf7f2] p-6 sm:p-10 dark:border-white/10 dark:bg-[#12100d]">
                        <x-home.foreshadow class="min-w-[640px]" />
                    </div>
                </div>
            </section>

            <section id="flow" class="mx-auto max-w-6xl scroll-mt-8 px-6 py-24">
                <h2 class="font-serif text-3xl font-semibold tracking-tight">从一句话到一本书</h2>
                <p class="mt-4 max-w-2xl text-stone-600 dark:text-stone-300">每个阶段的结果都会保存，可单独重跑；任何一步出错都停在原地等你处理，不会擅自重试或改写已确认的内容。</p>
                <ol class="mt-12 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    @foreach ($flow as $step)
                        <li class="relative rounded-xl border border-stone-900/10 bg-white px-4 py-5 dark:border-white/10 dark:bg-stone-900">
                            <span class="font-mono text-xs text-amber-700 dark:text-amber-300">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <p class="mt-2 font-serif text-lg font-semibold">{{ $step }}</p>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-20 grid gap-8 md:grid-cols-3">
                    @foreach ([['不陷入循环', '固定阶段状态机，每章执行次数有上限，超限即停。'], ['流程可以跑通', '中间结果全部保存，中断后从检查点继续。'], ['情节必须推进', '推进检查不通过不提交，杜绝原地打转。']] as [$title, $body])
                        <div class="border-l-2 border-amber-600/60 pl-5 dark:border-amber-300/50">
                            <p class="font-medium">{{ $title }}</p>
                            <p class="mt-1.5 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-6 pb-24">
                <div class="relative overflow-hidden rounded-3xl bg-stone-900 px-8 pb-24 pt-14 text-center sm:px-16 dark:bg-stone-800/60">
                    <div aria-hidden="true" class="absolute inset-0 bg-[radial-gradient(40rem_16rem_at_50%_120%,rgba(251,191,36,0.25),transparent)]"></div>
                    <svg aria-hidden="true" class="absolute inset-x-0 bottom-0 h-24 w-full sm:h-32" viewBox="0 0 1200 160" preserveAspectRatio="none" fill="none">
                        <path d="M0 110 C120 70 220 40 320 70 C420 100 480 50 600 45 C720 40 780 100 900 95 C1020 90 1100 60 1200 70 V160 H0Z" fill="#ffffff" fill-opacity="0.04" />
                        <path d="M0 140 C160 110 300 100 440 120 C580 140 700 100 860 110 C1000 118 1100 140 1200 130 V160 H0Z" fill="#ffffff" fill-opacity="0.06" />
                    </svg>
                    <h2 class="relative font-serif text-3xl font-semibold tracking-tight text-white">下一章，从这里开始</h2>
                    <p class="relative mx-auto mt-4 max-w-xl text-stone-300">打开后台，查看每本书的进度、阻塞原因与下一步操作。</p>
                    <a href="{{ $adminUrl }}" class="relative mt-8 inline-flex items-center gap-2 rounded-full bg-amber-400 px-6 py-3 text-sm font-medium text-stone-900 transition hover:bg-amber-300">
                        {{ $signedIn ? '继续创作' : '进入管理后台' }}
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </a>
                </div>
            </section>
        </main>

        <footer class="border-t border-stone-900/5 py-8 dark:border-white/5">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-6 text-sm text-stone-500 sm:flex-row dark:text-stone-400">
                <p>© {{ now()->year }} {{ config('app.name') }}</p>
                <a href="{{ $adminUrl }}" class="hover:text-stone-900 dark:hover:text-white">管理后台</a>
            </div>
        </footer>
    </body>
</html>
