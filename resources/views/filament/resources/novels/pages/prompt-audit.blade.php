{{--
    “查看完整Prompt”弹窗：只列当前运行的请求，每个请求折叠展示系统提示、用户提示与响应结构；
    审计摘要不一致或缺少来源时只显示状态，不把不可信内容当原文展示。后台未编译自定义主题，使用本组件局部样式。
--}}
<div class="nf-prompt">
    <style>
        .nf-prompt { display: grid; gap: .75rem; font-size: .875rem; color: var(--gray-700); }
        .nf-prompt-note { color: var(--gray-500); font-size: .8125rem; }
        .nf-prompt details { border: 1px solid var(--gray-200); border-radius: .75rem; padding: .625rem .875rem; }
        .nf-prompt details > summary { cursor: pointer; display: flex; flex-wrap: wrap; gap: .375rem .75rem; align-items: center; font-weight: 600; color: var(--gray-950); }
        .nf-prompt-meta { font-weight: 400; color: var(--gray-500); font-size: .8125rem; }
        .nf-prompt-badge { display: inline-flex; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; background: var(--gray-100); color: var(--gray-600); }
        .nf-prompt-badge-warning { background: var(--warning-50); color: var(--warning-700); }
        .nf-prompt h4 { margin: .75rem 0 .25rem; font-size: .8125rem; font-weight: 600; color: var(--gray-500); }
        .nf-prompt pre { margin: 0; max-height: 28rem; overflow: auto; padding: .625rem .75rem; border-radius: .5rem; background: var(--gray-50); font-size: .75rem; line-height: 1.6; white-space: pre-wrap; overflow-wrap: anywhere; }
        .dark .nf-prompt { color: var(--gray-300); }
        .dark .nf-prompt details { border-color: var(--gray-700); }
        .dark .nf-prompt details > summary { color: #fff; }
        .dark .nf-prompt .nf-prompt-badge { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-prompt .nf-prompt-badge-warning { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
        .dark .nf-prompt pre { background: rgb(255 255 255 / .05); }
    </style>

    <p class="nf-prompt-note">本运行共 {{ count($requests) }} 次请求；点开单个请求查看调用前保存的完整内容。其他运行的请求请切换到对应运行查看。</p>
    @forelse ($requests as $request)
        <details>
            <summary>
                <span>请求 #{{ $request['cost_log_id'] }} · {{ $request['step'] }}</span>
                <span class="nf-prompt-badge {{ $request['trusted'] ? '' : 'nf-prompt-badge-warning' }}">{{ $request['status'] }}</span>
                <span class="nf-prompt-meta">{{ $request['model'] }} · {{ $request['time'] }}</span>
            </summary>
            @if ($request['trusted'])
                <h4>系统提示</h4>
                <pre>{{ $request['system_prompt'] }}</pre>
                <h4>用户提示</h4>
                <pre>{{ $request['user_prompt'] }}</pre>
                <h4>响应结构</h4>
                <pre>{{ $request['response_schema'] }}</pre>
            @endif
        </details>
    @empty
        <p class="nf-prompt-note">本运行还没有发出请求。</p>
    @endforelse
</div>
