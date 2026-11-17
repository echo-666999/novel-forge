{{-- 编辑区正文与对比版本的逐段差异：红色删除、绿色新增，连续未改动的段折叠为一行。 --}}
<div style="display: grid; gap: .5rem; font-size: .875rem; line-height: 1.8;">
    <p style="color: var(--gray-500);">{{ $heading }}：共改动 {{ \App\Services\Pipeline\ParagraphDiff::changes($rows) }} 段。</p>
    @forelse ($rows as $row)
        @if ($row['type'] === 'unchanged')
            <p style="color: var(--gray-400); text-align: center;">⋯ 未改动 {{ $row['count'] }} 段 ⋯</p>
        @elseif ($row['type'] === 'changed')
            <div style="display: grid; gap: .25rem; padding: .5rem .75rem; border-radius: .5rem; box-shadow: 0 0 0 1px var(--gray-200);">
                <p>{{ $row['old'][0] }}<del style="background: rgb(244 63 94 / .2);">{{ $row['old'][1] }}</del>{{ $row['old'][2] }}</p>
                <p>{{ $row['new'][0] }}<ins style="background: rgb(34 197 94 / .22); text-decoration: none;">{{ $row['new'][1] }}</ins>{{ $row['new'][2] }}</p>
            </div>
        @elseif ($row['type'] === 'removed')
            <p style="padding: .5rem .75rem; border-radius: .5rem; background: rgb(244 63 94 / .1);"><span style="color: var(--danger-600);">删除：</span><del>{{ $row['text'] }}</del></p>
        @else
            <p style="padding: .5rem .75rem; border-radius: .5rem; background: rgb(34 197 94 / .1);"><span style="color: var(--success-600);">新增：</span>{{ $row['text'] }}</p>
        @endif
    @empty
        <p>两份正文都是空的。</p>
    @endforelse
</div>
