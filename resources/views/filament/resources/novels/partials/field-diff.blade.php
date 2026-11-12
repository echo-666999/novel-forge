{{--
    字段级版本对照（设定、实体定义等共用）：先给出变化统计，再按分区列出修改（旧版/新版并排）、新增、删除，未变化内容默认折叠。
    参数：$diff（FieldDiff::summarize 的结果）、$oldLabel、$newLabel（如“#12（2026-11-11 05:39）”）、可选 $sameMessage。
    后台未编译自定义主题，使用本组件局部样式。
--}}
@php
    $kindLabels = ['modified' => '修改', 'added' => '新增', 'removed' => '删除'];
@endphp
<div class="nf-diff">
    <style>
        .nf-diff { display: grid; gap: 1.5rem; font-size: .875rem; line-height: 1.7; color: var(--gray-700); }
        .nf-diff h3 { display: flex; align-items: center; gap: .5rem; margin: 0 0 .625rem; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--gray-500); }
        .nf-diff-summary { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1.5rem; padding: .875rem 1rem; border-radius: .75rem; background: var(--gray-50); }
        .nf-diff-versions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; color: var(--gray-500); }
        .nf-diff-versions strong { color: var(--gray-950); font-weight: 600; }
        .nf-diff-counts { display: flex; flex-wrap: wrap; gap: .5rem; }
        .nf-diff-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .nf-diff-badge-modified { background: var(--warning-50); color: var(--warning-700); }
        .nf-diff-badge-added { background: var(--success-50); color: var(--success-700); }
        .nf-diff-badge-removed { background: var(--danger-50); color: var(--danger-700); }
        .nf-diff-badge-unchanged { background: var(--gray-100); color: var(--gray-600); }
        .nf-diff-same { padding: 1rem 1.25rem; border-radius: .75rem; border: 1px solid var(--success-200); background: var(--success-50); color: var(--success-800); font-weight: 500; }
        .nf-diff-list { display: grid; gap: .75rem; }
        .nf-diff-item { padding: .75rem 1rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .nf-diff-item-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .5rem; font-weight: 600; color: var(--gray-950); }
        .nf-diff-sides { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .nf-diff-side { padding: .5rem .75rem; border-radius: .5rem; white-space: pre-line; }
        .nf-diff-side-label { display: block; margin-bottom: .125rem; font-size: .75rem; font-weight: 600; }
        .nf-diff-old { background: color-mix(in oklab, var(--danger-50) 70%, transparent); }
        .nf-diff-old .nf-diff-side-label { color: var(--danger-700); }
        .nf-diff-new { background: color-mix(in oklab, var(--success-50) 70%, transparent); }
        .nf-diff-new .nf-diff-side-label { color: var(--success-700); }
        .nf-diff-unchanged summary { cursor: pointer; font-weight: 600; color: var(--gray-500); }
        .nf-diff-unchanged-body { display: grid; gap: 1rem; margin-top: .75rem; }
        .nf-diff-fields { display: grid; grid-template-columns: fit-content(12rem) 1fr; gap: .25rem .75rem; margin: 0; }
        .nf-diff-fields dt { color: var(--gray-500); }
        .nf-diff-fields dd { margin: 0; white-space: pre-line; }
        /* 深色模式：用 .dark 前缀提高优先级，覆盖上面的浅色取值。 */
        .dark .nf-diff { color: var(--gray-300); }
        .dark .nf-diff :where(.nf-diff-versions strong, .nf-diff-item-head) { color: #fff; }
        .dark .nf-diff .nf-diff-summary { background: color-mix(in oklab, var(--gray-800) 60%, transparent); }
        .dark .nf-diff .nf-diff-item { border-color: var(--gray-700); }
        .dark .nf-diff .nf-diff-badge-modified { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
        .dark .nf-diff .nf-diff-badge-added { background: color-mix(in oklab, var(--success-400) 15%, transparent); color: var(--success-400); }
        .dark .nf-diff .nf-diff-badge-removed { background: color-mix(in oklab, var(--danger-400) 15%, transparent); color: var(--danger-400); }
        .dark .nf-diff .nf-diff-badge-unchanged { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-diff .nf-diff-same { border-color: color-mix(in oklab, var(--success-400) 30%, transparent); background: color-mix(in oklab, var(--success-400) 10%, transparent); color: var(--success-300); }
        .dark .nf-diff .nf-diff-old { background: color-mix(in oklab, var(--danger-400) 12%, transparent); }
        .dark .nf-diff .nf-diff-old .nf-diff-side-label { color: var(--danger-400); }
        .dark .nf-diff .nf-diff-new { background: color-mix(in oklab, var(--success-400) 12%, transparent); }
        .dark .nf-diff .nf-diff-new .nf-diff-side-label { color: var(--success-400); }
    </style>

    <div class="nf-diff-summary">
        <div class="nf-diff-versions">
            <span>旧版 <strong>{{ $oldLabel }}</strong></span>
            <span aria-hidden="true">→</span>
            <span>新版 <strong>{{ $newLabel }}</strong></span>
        </div>
        <div class="nf-diff-counts">
            <span class="nf-diff-badge nf-diff-badge-modified">修改 {{ $diff['counts']['modified'] }}</span>
            <span class="nf-diff-badge nf-diff-badge-added">新增 {{ $diff['counts']['added'] }}</span>
            <span class="nf-diff-badge nf-diff-badge-removed">删除 {{ $diff['counts']['removed'] }}</span>
            <span class="nf-diff-badge nf-diff-badge-unchanged">未变化 {{ $diff['counts']['unchanged'] }}</span>
        </div>
    </div>

    @if ($diff['identical'])
        <div class="nf-diff-same" role="status">{{ $sameMessage ?? '两个版本内容完全相同，没有需要关注的变化。' }}</div>
    @endif

    @foreach ($diff['sections'] as $section)
        @continue($section['changes'] === [])
        <section aria-label="{{ $section['title'] }}">
            <h3>{{ $section['title'] }}<span class="nf-diff-badge nf-diff-badge-modified">{{ count($section['changes']) }} 处变化</span></h3>
            <div class="nf-diff-list">
                @foreach ($section['changes'] as $change)
                    <div class="nf-diff-item">
                        <div class="nf-diff-item-head">
                            <span class="nf-diff-badge nf-diff-badge-{{ $change['kind'] }}">{{ $kindLabels[$change['kind']] }}</span>
                            <span>{{ $change['label'] }}</span>
                        </div>
                        <div class="nf-diff-sides">
                            @if ($change['old'] !== null)
                                <div class="nf-diff-side nf-diff-old"><span class="nf-diff-side-label">旧版</span>{{ $change['old'] }}</div>
                            @endif
                            @if ($change['new'] !== null)
                                <div class="nf-diff-side nf-diff-new"><span class="nf-diff-side-label">新版</span>{{ $change['new'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($diff['counts']['unchanged'] > 0)
        <details class="nf-diff-unchanged" @if ($diff['identical']) open @endif>
            <summary>未变化的内容（{{ $diff['counts']['unchanged'] }} 项）</summary>
            <div class="nf-diff-unchanged-body">
                @foreach ($diff['sections'] as $section)
                    @continue($section['unchanged'] === [])
                    <section aria-label="{{ $section['title'] }}（未变化）">
                        <h3>{{ $section['title'] }}</h3>
                        <dl class="nf-diff-fields">
                            @foreach ($section['unchanged'] as $item)
                                {{-- 同名条目（如多条规则）只在第一行显示标签。 --}}
                                <dt>{{ $loop->first || $section['unchanged'][$loop->index - 1]['label'] !== $item['label'] ? $item['label'] : '' }}</dt>
                                <dd>{{ $item['value'] }}</dd>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>
        </details>
    @endif
</div>
