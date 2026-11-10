<x-filament-panels::page>
    {{-- 后台未编译自定义 Tailwind 主题，布局使用本页局部样式；窄屏自动改为上下排列。 --}}
    <style>
        .nf-acceptance-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
        .nf-acceptance-chips { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
        .nf-acceptance-chip { display: inline-block; padding: .25rem .625rem; border-radius: .5rem; border: 1px solid rgba(127, 127, 127, .35); text-decoration: none; font-size: .875rem; }
        .nf-acceptance-chip[aria-current="page"] { font-weight: 700; border-width: 2px; }
        .nf-acceptance-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
        @media (min-width: 1024px) {
            .nf-acceptance-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); }
            .nf-acceptance-side { position: sticky; top: 5rem; align-self: start; max-height: calc(100vh - 6rem); overflow-y: auto; }
        }
    </style>

    @php($progress = $this->progress())
    @php($rating = $this->currentRating())

    <x-filament::section>
        <p role="status">
            批次 #{{ $batchId }} · 已评分 {{ $progress['rated'] }}/{{ $progress['total'] }} 章 · 达标 {{ $progress['passed'] }} 章 ·
            机器核验：{{ $progress['machine'] === null ? '尚未执行' : ($progress['machine'] ? '通过' : '未通过') }}
        </p>
        <nav class="nf-acceptance-chips" aria-label="批次章节">
            @foreach ($progress['chapters'] as $chapter)
                <a class="nf-acceptance-chip" href="{{ $this->chapterUrl($chapter['number']) }}" @if ($chapter['number'] === $chapterNumber) aria-current="page" @endif
                   title="{{ $chapter['title'] }}">
                    {{ $chapter['pass'] === null ? '○' : ($chapter['pass'] ? '✓' : '✗') }} 第{{ $chapter['number'] }}章
                </a>
            @endforeach
        </nav>
        <p style="margin-top: .5rem; font-size: .875rem; opacity: .75;">○ 未评分　✓ 达标　✗ 未达标（每项至少3分、合计至少27分且无阻塞问题才算达标）</p>
    </x-filament::section>

    <div class="nf-acceptance-grid">
        <x-filament::section :heading="'第'.$chapterNumber.'章 · '.$this->version()->title" :description="'冻结版本 #'.$this->version()->id.'，与批次清单一致才计入验收。'">
            @unless ($this->sourceIntact())
                <p role="alert" style="margin-bottom: 1rem;">冻结正文摘要与批次记录不一致，评分将被拒绝，请回到验收工作台检查来源。</p>
            @endunless
            {{-- 记录最近一次正文内选择，点击引用按钮时选区可能已被浏览器清除。 --}}
            <div data-acceptance-body
                 x-data
                 x-on:mouseup="window.novelForgeAcceptanceSelection = window.getSelection().toString()"
                 x-on:keyup="window.novelForgeAcceptanceSelection = window.getSelection().toString()"
                 style="overflow-wrap: anywhere; line-height: 1.9; font-size: {{ in_array($fontSize, [16, 20, 24, 28], true) ? $fontSize : 20 }}px;">{!! nl2br(e($this->version()->body)) !!}</div>
        </x-filament::section>

        <div class="nf-acceptance-side">
            <x-filament::section heading="本章评分">
                @if ($rating !== null)
                    <div style="margin-bottom: 1rem;">
                        <p>上次评分（{{ $rating['rated_at'] }}）：合计 {{ $rating['sum'] }} 分，{{ $rating['pass'] ? '达标' : '未达标' }}{{ $rating['blocking'] ? '，标记了阻塞问题' : '' }}。</p>
                        <p style="font-size: .875rem; opacity: .75;">
                            @foreach (\App\Services\Pipeline\AcceptanceWorkbench::DIMENSIONS as $key => $label){{ $label }} {{ $rating['scores'][$key] ?? '-' }}@if (! $loop->last)　@endif @endforeach
                        </p>
                        <p style="font-size: .875rem; opacity: .75;">重新提交会追加一条新评分，旧记录保留在证据包中。</p>
                    </div>
                @endif

                @if ($this->canRate())
                    <form wire:submit="save">
                        {{ $this->form }}
                        <div class="nf-acceptance-actions">
                            <x-filament::button type="button" wire:click="saveAndNext" wire:loading.attr="disabled">保存并进入下一章</x-filament::button>
                            <x-filament::button type="submit" color="gray" wire:loading.attr="disabled">只保存本章</x-filament::button>
                        </div>
                    </form>
                @else
                    <p>只有批次负责人可以评分；批次关闭后仅可阅读。</p>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
