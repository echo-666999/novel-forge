{{--
    正文按段落对照（阅读器、正式稿修订共用）：先给出变化统计，再按原文顺序列出修改（旧版/新版并排，段内变化文字高亮）、新增、删除；
    连续未变化的段落折叠为“未变化 N 段”，点击展开。参数：$diff（BodyVersionDiff::paragraphs 的结果）、$oldLabel、$newLabel。
--}}
@php
    $kindLabels = ['modified' => '修改', 'added' => '新增', 'removed' => '删除'];
    $render = fn (array $parts): string => collect($parts)->map(fn (array $part): string => e($part[0]).($part[1] !== '' ? '<mark class="nf-body-mark">'.e($part[1]).'</mark>' : '').e($part[2]))->implode('');
@endphp
<div class="nf-body">
    <style>
        .nf-body { display: grid; gap: 1rem; font-size: .9375rem; line-height: 1.9; color: var(--gray-700); }
        .nf-body-summary { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1.5rem; padding: .75rem 1rem; border-radius: .75rem; background: var(--gray-50); font-size: .8125rem; color: var(--gray-500); }
        .nf-body-summary strong { color: var(--gray-950); }
        .nf-body-counts { display: flex; flex-wrap: wrap; gap: .5rem; }
        .nf-body-badge { display: inline-flex; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; white-space: nowrap; line-height: 1.6; }
        .nf-body-modified { background: var(--warning-50); color: var(--warning-700); }
        .nf-body-added { background: var(--success-50); color: var(--success-700); }
        .nf-body-removed { background: var(--danger-50); color: var(--danger-700); }
        .nf-body-unchanged { background: var(--gray-100); color: var(--gray-600); }
        .nf-body-same-all { padding: .875rem 1rem; border-radius: .75rem; border: 1px solid var(--success-200); background: var(--success-50); color: var(--success-800); font-size: .875rem; font-weight: 500; }
        .nf-body-change { padding: .75rem 1rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .nf-body-change-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; font-size: .8125rem; color: var(--gray-500); }
        .nf-body-sides { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); }
        .nf-body-side { padding: .5rem .75rem; border-radius: .5rem; overflow-wrap: anywhere; }
        .nf-body-side-label { display: block; font-size: .75rem; font-weight: 600; }
        .nf-body-old { background: color-mix(in oklab, var(--danger-50) 70%, transparent); }
        .nf-body-old .nf-body-side-label { color: var(--danger-700); }
        .nf-body-new { background: color-mix(in oklab, var(--success-50) 70%, transparent); }
        .nf-body-new .nf-body-side-label { color: var(--success-700); }
        .nf-body-old .nf-body-mark { background: color-mix(in oklab, var(--danger-400) 30%, transparent); color: inherit; text-decoration: line-through; border-radius: .125rem; }
        .nf-body-new .nf-body-mark { background: color-mix(in oklab, var(--success-400) 35%, transparent); color: inherit; border-radius: .125rem; }
        .nf-body-same summary { cursor: pointer; padding: .375rem .75rem; border-radius: .5rem; background: var(--gray-50); font-size: .8125rem; color: var(--gray-500); }
        .nf-body-same p { margin: .5rem 0 0; color: var(--gray-500); overflow-wrap: anywhere; }
        .nf-body-no { margin-right: .5rem; font-size: .75rem; color: var(--gray-400); }
        .dark .nf-body { color: var(--gray-300); }
        .dark .nf-body .nf-body-summary { background: color-mix(in oklab, var(--gray-800) 60%, transparent); }
        .dark .nf-body .nf-body-summary strong { color: #fff; }
        .dark .nf-body .nf-body-change { border-color: var(--gray-700); }
        .dark .nf-body .nf-body-modified { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
        .dark .nf-body .nf-body-added { background: color-mix(in oklab, var(--success-400) 15%, transparent); color: var(--success-400); }
        .dark .nf-body .nf-body-removed { background: color-mix(in oklab, var(--danger-400) 15%, transparent); color: var(--danger-400); }
        .dark .nf-body .nf-body-unchanged { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-body .nf-body-same-all { border-color: color-mix(in oklab, var(--success-400) 30%, transparent); background: color-mix(in oklab, var(--success-400) 10%, transparent); color: var(--success-300); }
        .dark .nf-body .nf-body-old { background: color-mix(in oklab, var(--danger-400) 12%, transparent); }
        .dark .nf-body .nf-body-old .nf-body-side-label { color: var(--danger-400); }
        .dark .nf-body .nf-body-new { background: color-mix(in oklab, var(--success-400) 12%, transparent); }
        .dark .nf-body .nf-body-new .nf-body-side-label { color: var(--success-400); }
        .dark .nf-body .nf-body-same summary { background: color-mix(in oklab, var(--gray-800) 60%, transparent); }
    </style>

    <div class="nf-body-summary">
        <span>旧版 <strong>{{ $oldLabel }}</strong> → 新版 <strong>{{ $newLabel }}</strong></span>
        <span class="nf-body-counts">
            <span class="nf-body-badge nf-body-modified">修改 {{ $diff['counts']['modified'] }} 段</span>
            <span class="nf-body-badge nf-body-added">新增 {{ $diff['counts']['added'] }} 段</span>
            <span class="nf-body-badge nf-body-removed">删除 {{ $diff['counts']['removed'] }} 段</span>
            <span class="nf-body-badge nf-body-unchanged">未变化 {{ $diff['counts']['unchanged'] }} 段</span>
        </span>
    </div>

    @if ($diff['identical'])
        <div class="nf-body-same-all" role="status">两个版本的正文完全相同。</div>
    @endif

    @foreach ($diff['hunks'] as $hunk)
        @if ($hunk['type'] === 'same')
            @php($first = $hunk['paragraphs'][0]['no'])
            @php($last = $hunk['paragraphs'][array_key_last($hunk['paragraphs'])]['no'])
            <details class="nf-body-same">
                <summary>未变化 {{ count($hunk['paragraphs']) }} 段（新版第 {{ $first === $last ? $first : $first.'～'.$last }} 段），点击展开</summary>
                @foreach ($hunk['paragraphs'] as $paragraph)
                    <p><span class="nf-body-no">{{ $paragraph['no'] }}</span>{{ $paragraph['text'] }}</p>
                @endforeach
            </details>
        @else
            <div class="nf-body-change">
                <div class="nf-body-change-head">
                    <span class="nf-body-badge nf-body-{{ $hunk['kind'] }}">{{ $kindLabels[$hunk['kind']] }}</span>
                    <span>{{ $hunk['new_no'] !== null ? '新版第 '.$hunk['new_no'].' 段' : '' }}{{ $hunk['new_no'] !== null && $hunk['old_no'] !== null ? ' · ' : '' }}{{ $hunk['old_no'] !== null ? '旧版第 '.$hunk['old_no'].' 段' : '' }}</span>
                </div>
                <div class="nf-body-sides">
                    @if ($hunk['old'] !== null)
                        <div class="nf-body-side nf-body-old"><span class="nf-body-side-label">旧版</span>{!! $render($hunk['old']) !!}</div>
                    @endif
                    @if ($hunk['new'] !== null)
                        <div class="nf-body-side nf-body-new"><span class="nf-body-side-label">新版</span>{!! $render($hunk['new']) !!}</div>
                    @endif
                </div>
            </div>
        @endif
    @endforeach
</div>
