{{--
    正文工作台“评审、事件提取与正式提交”的结果展示，替代整段 JSON：评审结论与问题（阻塞在前）、事件、状态变化、伏笔流转、新实体、
    确定性验证和提交回执。原文引用默认折叠。后台未编译自定义主题，使用本组件局部样式。
--}}
<div class="nf-sub">
    <style>
        .nf-sub { display: grid; gap: 1.5rem; font-size: .875rem; line-height: 1.7; color: var(--gray-700); }
        .nf-sub h3 { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: 0 0 .625rem; font-size: .875rem; font-weight: 600; color: var(--gray-950); }
        .nf-sub h4 { margin: .25rem 0 .5rem; font-size: .8125rem; font-weight: 600; color: var(--gray-500); }
        .nf-sub-badge { display: inline-flex; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; background: var(--gray-100); color: var(--gray-600); white-space: nowrap; }
        .nf-sub-success { background: var(--success-50); color: var(--success-700); }
        .nf-sub-danger { background: var(--danger-50); color: var(--danger-700); }
        .nf-sub-warning { background: var(--warning-50); color: var(--warning-700); }
        .nf-sub-primary { background: var(--primary-50); color: var(--primary-700); }
        .nf-sub-summary { margin: 0; padding: .75rem 1rem; border-radius: .75rem; background: var(--gray-50); }
        .nf-sub-list { display: grid; gap: .625rem; margin: 0; padding: 0; list-style: none; }
        .nf-sub-item { padding: .625rem .875rem; border-radius: .625rem; border: 1px solid var(--gray-200); }
        .nf-sub-item-blocking { border-color: var(--danger-300); }
        .nf-sub-meta { display: flex; flex-wrap: wrap; gap: .375rem; margin-bottom: .25rem; }
        .nf-sub-muted { color: var(--gray-500); font-size: .8125rem; }
        .nf-sub details { margin-top: .375rem; }
        .nf-sub details summary { cursor: pointer; color: var(--gray-500); font-size: .8125rem; }
        .nf-sub blockquote { margin: .375rem 0 0; padding: .25rem 0 .25rem .75rem; border-left: 3px solid var(--gray-200); color: var(--gray-600); font-size: .8125rem; }
        .nf-sub-table { width: 100%; border-collapse: collapse; }
        .nf-sub-table th, .nf-sub-table td { padding: .375rem .5rem; border-bottom: 1px solid var(--gray-100); text-align: left; vertical-align: top; }
        .nf-sub-table th { font-size: .75rem; font-weight: 600; color: var(--gray-500); }
        .nf-sub-fields { display: grid; grid-template-columns: fit-content(10rem) 1fr; gap: .25rem .75rem; margin: 0; }
        .nf-sub-fields dt { color: var(--gray-500); }
        .nf-sub-fields dd { margin: 0; overflow-wrap: anywhere; }
        .nf-sub-empty { color: var(--gray-400); }
        .dark .nf-sub { color: var(--gray-300); }
        .dark .nf-sub h3 { color: #fff; }
        .dark .nf-sub .nf-sub-summary { background: color-mix(in oklab, var(--gray-800) 60%, transparent); }
        .dark .nf-sub .nf-sub-item { border-color: var(--gray-700); }
        .dark .nf-sub .nf-sub-item-blocking { border-color: color-mix(in oklab, var(--danger-400) 60%, transparent); }
        .dark .nf-sub .nf-sub-badge { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-sub .nf-sub-success { background: color-mix(in oklab, var(--success-400) 15%, transparent); color: var(--success-400); }
        .dark .nf-sub .nf-sub-danger { background: color-mix(in oklab, var(--danger-400) 15%, transparent); color: var(--danger-400); }
        .dark .nf-sub .nf-sub-warning { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
        .dark .nf-sub .nf-sub-primary { background: color-mix(in oklab, var(--primary-400) 15%, transparent); color: var(--primary-400); }
        .dark .nf-sub blockquote { border-left-color: var(--gray-700); color: var(--gray-400); }
        .dark .nf-sub :where(.nf-sub-table th, .nf-sub-table td) { border-bottom-color: var(--gray-800); }
    </style>

    {{-- 评审 --}}
    <section aria-label="基础评审">
        <h3>
            基础评审
            @if ($review !== null)
                <span class="nf-sub-badge {{ $review['passed'] ? 'nf-sub-success' : 'nf-sub-danger' }}">{{ $review['verdict'] }}</span>
                @php($blocking = count(array_filter($review['findings'], fn ($finding) => $finding['blocking'])))
                @if ($blocking > 0)<span class="nf-sub-badge nf-sub-danger">阻塞问题 {{ $blocking }}</span>@endif
                @if (count($review['findings']) - $blocking > 0)<span class="nf-sub-badge">建议 {{ count($review['findings']) - $blocking }}</span>@endif
            @endif
        </h3>
        @if ($review === null)
            <p class="nf-sub-empty">尚无记录</p>
        @else
            @if ($review['summary'])<p class="nf-sub-summary">{{ $review['summary'] }}</p>@endif
            @if ($review['findings'] !== [])
                <ul class="nf-sub-list" style="margin-top: .75rem;">
                    @foreach ($review['findings'] as $finding)
                        <li class="nf-sub-item {{ $finding['blocking'] ? 'nf-sub-item-blocking' : '' }}">
                            <div class="nf-sub-meta">
                                <span class="nf-sub-badge {{ $finding['blocking'] ? 'nf-sub-danger' : 'nf-sub-warning' }}">{{ $finding['blocking'] ? '阻塞' : '建议' }}</span>
                                <span class="nf-sub-badge">{{ $finding['kind'] }}</span>
                            </div>
                            <div>{{ $finding['reason'] }}</div>
                            @include('filament.resources.novels.partials.quotes', ['quotes' => $finding['quotes']])
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>

    {{-- 事件提取 --}}
    <section aria-label="事件提取">
        <h3>
            事件提取
            @if ($extraction !== null)
                <span class="nf-sub-badge">事件 {{ count($extraction['events']) }}</span>
                <span class="nf-sub-badge">状态变化 {{ count($extraction['changes']) }}</span>
                @if ($extraction['foreshadows'] !== [])<span class="nf-sub-badge nf-sub-primary">伏笔流转 {{ count($extraction['foreshadows']) }}</span>@endif
                @if ($extraction['entities'] !== [])<span class="nf-sub-badge nf-sub-warning">新实体 {{ count($extraction['entities']) }}</span>@endif
            @endif
        </h3>
        @if ($extraction === null)
            <p class="nf-sub-empty">尚无记录</p>
        @else
            @if ($extraction['foreshadows'] !== [])
                <h4>伏笔流转</h4>
                <ul class="nf-sub-list">
                    @foreach ($extraction['foreshadows'] as $item)
                        <li class="nf-sub-item">
                            <div class="nf-sub-meta"><span class="nf-sub-badge nf-sub-primary">{{ $item['action'] }}</span><strong>{{ $item['title'] }}</strong></div>
                            <div>{{ $item['explanation'] }}</div>
                            @include('filament.resources.novels.partials.quotes', ['quotes' => $item['quotes']])
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($extraction['entities'] !== [])
                <h4>新实体（需批准）</h4>
                <ul class="nf-sub-list">
                    @foreach ($extraction['entities'] as $item)
                        <li class="nf-sub-item">
                            <div>{{ $item['label'] }}</div>
                            @include('filament.resources.novels.partials.quotes', ['quotes' => $item['quotes']])
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($extraction['changes'] !== [])
                <h4>状态变化</h4>
                <table class="nf-sub-table">
                    <thead><tr><th>实体</th><th>属性</th><th>变化</th><th>类别</th></tr></thead>
                    <tbody>
                        @foreach ($extraction['changes'] as $change)
                            <tr>
                                <td>{{ $change['entity'] }}</td>
                                <td>{{ $change['property'] }}</td>
                                <td>{{ $change['before'] !== null ? $change['before'].' → ' : '' }}<strong>{{ $change['after'] }}</strong></td>
                                <td class="nf-sub-muted">{{ $change['category'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <h4>事件（按叙事顺序）</h4>
            @if ($extraction['events'] === [])
                <p class="nf-sub-empty">未提取到事件。</p>
            @else
                <ol class="nf-sub-list">
                    @foreach ($extraction['events'] as $event)
                        <li class="nf-sub-item">
                            <div class="nf-sub-meta">
                                <span class="nf-sub-badge">{{ $event['order'] ?? $loop->iteration }}</span>
                                @if ($event['type'])<span class="nf-sub-badge">{{ $event['type'] }}</span>@endif
                                @if ($event['category'])<span class="nf-sub-badge">{{ $event['category'] }}</span>@endif
                                @if ($event['time'] && $event['time'] !== '当前')<span class="nf-sub-badge nf-sub-warning">{{ $event['time'] }}</span>@endif
                            </div>
                            <div>{{ $event['summary'] }}</div>
                            <div class="nf-sub-muted">{{ $event['actor'] ?? '未标主体' }}{{ $event['targets'] !== [] ? ' → '.implode('、', $event['targets']) : '' }}</div>
                            @include('filament.resources.novels.partials.quotes', ['quotes' => $event['quotes']])
                        </li>
                    @endforeach
                </ol>
            @endif
        @endif
    </section>

    {{-- 确定性验证 --}}
    <section aria-label="确定性验证">
        <h3>
            确定性验证
            @if ($validation !== null)
                <span class="nf-sub-badge {{ $validation['passed'] ? 'nf-sub-success' : 'nf-sub-danger' }}">{{ $validation['passed'] ? '通过' : '未通过' }}</span>
                @if ($validation['approvals'] !== [])<span class="nf-sub-badge nf-sub-warning">待批准 {{ count($validation['approvals']) }}</span>@endif
            @endif
        </h3>
        @if ($validation === null)
            <p class="nf-sub-empty">尚无记录</p>
        @else
            @if ($validation['errors'] !== [])
                <ul class="nf-sub-list">
                    @foreach ($validation['errors'] as $error)
                        <li class="nf-sub-item nf-sub-item-blocking">{{ $error }}</li>
                    @endforeach
                </ul>
            @elseif ($validation['approvals'] === [])
                <p class="nf-sub-muted" style="margin: 0;">没有校验错误，也没有需要作者批准的实体。</p>
            @endif
            @if ($validation['approvals'] !== [])
                <h4>需要作者批准</h4>
                <ul class="nf-sub-list">
                    @foreach ($validation['approvals'] as $item)
                        <li class="nf-sub-item">{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>

    {{-- 提交回执 --}}
    <section aria-label="正式提交回执">
        <h3>正式提交回执 @if ($commit !== null)<span class="nf-sub-badge nf-sub-success">已提交</span>@endif</h3>
        @if ($commit === null)
            <p class="nf-sub-empty">尚未正式提交</p>
        @else
            <dl class="nf-sub-fields">
                @foreach ($commit as $row)
                    <dt>{{ $row['label'] }}</dt>
                    <dd>{{ $row['value'] }}</dd>
                @endforeach
            </dl>
        @endif
    </section>
</div>
