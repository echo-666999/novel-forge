{{--
    章节大纲“查看推进点”弹窗：本章目标置顶突出，摘要、冲突、章末钩子紧凑排列；
    推进点按“变化前 → 行动 → 变化后”一行展示，正文完成条件单独标出，推进点编号弱化显示。
    后台未编译自定义主题，使用本组件局部样式。
--}}
<div class="nf-outline">
    <style>
        .nf-outline { display: grid; gap: 1rem; font-size: .875rem; line-height: 1.65; color: var(--gray-700); }
        .nf-outline h3 { margin: 0 0 .5rem; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--gray-500); }
        .nf-outline-source { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; font-size: .8125rem; color: var(--gray-500); }
        .nf-outline-badge { display: inline-flex; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; background: var(--gray-100); color: var(--gray-600); white-space: nowrap; }
        .nf-outline-badge-primary { background: var(--primary-50); color: var(--primary-700); }
        .nf-outline-badge-warning { background: var(--warning-50); color: var(--warning-700); }
        .nf-outline-goal { padding: .75rem 1rem; border-radius: .75rem; border-left: 4px solid var(--primary-500); background: var(--primary-50); }
        .nf-outline-goal-label { font-size: .75rem; font-weight: 600; color: var(--primary-700); }
        .nf-outline-goal-text { margin-top: .125rem; font-size: 1rem; font-weight: 600; color: var(--gray-950); }
        .nf-outline-fields { display: grid; grid-template-columns: fit-content(6rem) 1fr; gap: .375rem .75rem; margin: 0; }
        .nf-outline-fields dt { color: var(--gray-500); white-space: nowrap; }
        .nf-outline-fields dd { margin: 0; }
        .nf-outline-hook { color: var(--gray-950); font-weight: 500; }
        .nf-outline-list { display: grid; gap: .625rem; }
        .nf-outline-card { padding: .625rem .875rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .nf-outline-card-head { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .5rem; margin-bottom: .5rem; font-weight: 600; color: var(--gray-950); }
        .nf-outline-id { margin-left: auto; font-family: ui-monospace, monospace; font-size: .75rem; font-weight: 400; color: var(--gray-400); }
        .nf-outline-flow { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; gap: .5rem; align-items: start; }
        .nf-outline-step-label { font-size: .75rem; color: var(--gray-500); }
        .nf-outline-step-after { color: var(--gray-950); font-weight: 600; }
        .nf-outline-arrow { padding-top: 1.1rem; color: var(--gray-400); }
        .nf-outline-verify { display: flex; gap: .5rem; margin-top: .5rem; padding: .375rem .625rem; border-radius: .5rem; background: var(--success-50); color: var(--success-800); }
        .nf-outline-verify-label { flex: none; font-weight: 600; }
        .nf-outline-empty { color: var(--gray-400); }
        @media (max-width: 640px) {
            .nf-outline-flow { grid-template-columns: 1fr; gap: .25rem; }
            .nf-outline-arrow { display: none; }
        }
        .dark .nf-outline { color: var(--gray-300); }
        .dark .nf-outline .nf-outline-badge { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-outline .nf-outline-badge-primary { background: color-mix(in oklab, var(--primary-400) 15%, transparent); color: var(--primary-400); }
        .dark .nf-outline .nf-outline-badge-warning { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
        .dark .nf-outline .nf-outline-goal { background: color-mix(in oklab, var(--primary-400) 12%, transparent); border-left-color: var(--primary-400); }
        .dark .nf-outline .nf-outline-goal-label { color: var(--primary-400); }
        .dark .nf-outline .nf-outline-goal-text,
        .dark .nf-outline .nf-outline-hook,
        .dark .nf-outline .nf-outline-card-head,
        .dark .nf-outline .nf-outline-step-after { color: #fff; }
        .dark .nf-outline .nf-outline-card { border-color: var(--gray-700); }
        .dark .nf-outline .nf-outline-verify { background: color-mix(in oklab, var(--success-400) 12%, transparent); color: var(--success-300); }
    </style>

    <div class="nf-outline-source">
        @if ($revision === null)
            <span class="nf-outline-badge">原章纲</span><span>大纲生成时的原章纲</span>
        @else
            <span class="nf-outline-badge nf-outline-badge-warning">作者修订 #{{ $revision['id'] }}</span>
            <span>{{ $revision['reason'] }} · 新运行按此冻结，已开始的旧运行仍按原快照</span>
        @endif
    </div>

    <div class="nf-outline-goal">
        <div class="nf-outline-goal-label">本章目标</div>
        <div class="nf-outline-goal-text">{{ $objective ?? '未填写' }}</div>
    </div>

    <dl class="nf-outline-fields">
        <dt>摘要</dt>
        <dd>{{ $summary ?? '未填写' }}</dd>
        <dt>冲突</dt>
        <dd>{{ $conflict ?? '未填写' }}</dd>
        <dt>章末钩子</dt>
        <dd class="nf-outline-hook">{{ $hook ?? '未填写' }}</dd>
    </dl>

    <section aria-label="必须完成的情节推进点">
        <h3>必须完成的情节推进点（{{ count($points) }}）</h3>
        @if ($points === [])
            <p class="nf-outline-empty">本章没有登记推进点。</p>
        @else
            <div class="nf-outline-list">
                @foreach ($points as $index => $point)
                    <div class="nf-outline-card">
                        <div class="nf-outline-card-head">
                            <span>推进点 {{ $index + 1 }}</span>
                            @if ($point['type'])<span class="nf-outline-badge nf-outline-badge-primary">{{ $point['type'] }}</span>@endif
                            @if ($point['subject'])<span class="nf-outline-badge">{{ $point['subject'] }}</span>@endif
                            @if ($point['id'])<span class="nf-outline-id">{{ $point['id'] }}</span>@endif
                        </div>
                        <div class="nf-outline-flow">
                            <div><div class="nf-outline-step-label">变化前</div>{{ $point['before'] ?? '未填写' }}</div>
                            <div class="nf-outline-arrow" aria-hidden="true">→</div>
                            <div><div class="nf-outline-step-label">具体行动</div>{{ $point['action'] ?? '未填写' }}</div>
                            <div class="nf-outline-arrow" aria-hidden="true">→</div>
                            <div class="nf-outline-step-after"><div class="nf-outline-step-label">变化后</div>{{ $point['after'] ?? '未填写' }}</div>
                        </div>
                        @if ($point['verification'])
                            <div class="nf-outline-verify"><span class="nf-outline-verify-label">正文完成条件</span><span>{{ $point['verification'] }}</span></div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
