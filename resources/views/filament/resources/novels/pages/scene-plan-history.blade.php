{{--
    场景规划“版本与覆盖”弹窗：先列本章必须覆盖的推进点（变化前 → 行动 → 变化后），再按时间倒序列出场景规划版本；
    最新版本展开，旧版本折叠。预检问题醒目标出。后台未编译自定义主题，使用本组件局部样式。
--}}
<div class="nf-scene">
    <style>
        .nf-scene { display: grid; gap: 1.5rem; font-size: .875rem; line-height: 1.7; color: var(--gray-700); }
        .nf-scene h3 { margin: 0 0 .625rem; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--gray-500); }
        .nf-scene-list { display: grid; gap: .75rem; }
        .nf-scene-card { padding: .75rem 1rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .nf-scene-card-head { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .5rem; margin-bottom: .375rem; font-weight: 600; color: var(--gray-950); }
        .nf-scene-badge { display: inline-flex; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; background: var(--gray-100); color: var(--gray-600); }
        .nf-scene-badge-primary { background: var(--primary-50); color: var(--primary-700); }
        .nf-scene-badge-success { background: var(--success-50); color: var(--success-700); }
        .nf-scene-badge-warning { background: var(--warning-50); color: var(--warning-700); }
        .nf-scene-flow { display: grid; gap: .25rem; }
        .nf-scene-flow dt { font-size: .75rem; color: var(--gray-500); }
        .nf-scene-flow dd { margin: 0 0 .25rem; }
        .nf-scene-verify { margin-top: .25rem; font-size: .8125rem; color: var(--gray-500); }
        .nf-scene-issues { margin: 0; padding: .625rem .875rem .625rem 1.75rem; border-radius: .5rem; background: var(--warning-50); color: var(--warning-800); list-style: disc; }
        .nf-scene-ok { color: var(--success-700); }
        .nf-scene-fields { display: grid; grid-template-columns: fit-content(8rem) 1fr; gap: .25rem .75rem; margin: 0; }
        .nf-scene-fields dt { color: var(--gray-500); }
        .nf-scene-fields dd { margin: 0; white-space: pre-line; }
        .nf-scene details > summary { cursor: pointer; font-weight: 600; color: var(--gray-600); }
        .nf-scene details[open] > summary { margin-bottom: .75rem; }
        .nf-scene-empty { color: var(--gray-400); }
        .dark .nf-scene { color: var(--gray-300); }
        .dark .nf-scene .nf-scene-card { border-color: var(--gray-700); }
        .dark .nf-scene .nf-scene-card-head { color: #fff; }
        .dark .nf-scene .nf-scene-badge { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-scene .nf-scene-badge-primary { background: color-mix(in oklab, var(--primary-400) 15%, transparent); color: var(--primary-400); }
        .dark .nf-scene .nf-scene-badge-success { background: color-mix(in oklab, var(--success-400) 15%, transparent); color: var(--success-400); }
        .dark .nf-scene .nf-scene-badge-warning { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
        .dark .nf-scene .nf-scene-issues { background: color-mix(in oklab, var(--warning-400) 12%, transparent); color: var(--warning-300); }
        .dark .nf-scene .nf-scene-ok { color: var(--success-400); }
    </style>

    <section aria-label="必须覆盖的推进点">
        <h3>必须覆盖的推进点（{{ count($points) }}）</h3>
        @if ($points === [])
            <p class="nf-scene-empty">本章没有登记推进点。</p>
        @else
            <div class="nf-scene-list">
                @foreach ($points as $index => $point)
                    <div class="nf-scene-card">
                        <div class="nf-scene-card-head">
                            <span>推进点 {{ $index + 1 }}</span>
                            @if ($point['subject'])<span class="nf-scene-badge">{{ $point['subject'] }}</span>@endif
                            @if ($point['type'])<span class="nf-scene-badge nf-scene-badge-primary">{{ $point['type'] }}</span>@endif
                        </div>
                        <dl class="nf-scene-flow">
                            <dt>变化前</dt>
                            <dd>{{ $point['before'] ?? '未填写' }}</dd>
                            <dt>行动</dt>
                            <dd>{{ $point['action'] ?? '未填写' }}</dd>
                            <dt>变化后</dt>
                            <dd><strong>{{ $point['after'] ?? '未填写' }}</strong></dd>
                        </dl>
                        @if ($point['verification'])<div class="nf-scene-verify">核对方式：{{ $point['verification'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section aria-label="场景规划版本">
        <h3>场景规划版本（{{ count($versions) }}）</h3>
        @if ($versions === [])
            <p class="nf-scene-empty">尚未规划场景；使用“编辑 / 拆分 / 合并”保存第一个版本。</p>
        @else
            <div class="nf-scene-list">
                @foreach ($versions as $version)
                    <details class="nf-scene-card" @if ($loop->first) open @endif>
                        <summary>
                            场景记录 #{{ $version['id'] }}
                            <span class="nf-scene-badge {{ $version['frozen'] ? 'nf-scene-badge-success' : '' }}">{{ $version['status'] }}</span>
                            @if ($loop->first)<span class="nf-scene-badge nf-scene-badge-primary">最新</span>@endif
                            <span style="font-weight: 400; color: var(--gray-500);">{{ $version['created_at'] }}</span>
                        </summary>
                        <div style="display: grid; gap: .75rem;">
                            @if ($version['reason'])<div><span style="color: var(--gray-500);">原因：</span>{{ $version['reason'] }}</div>@endif
                            @if ($version['issues'] === [])
                                <div class="nf-scene-ok">预检通过：推进点覆盖与总字数符合要求。</div>
                            @else
                                <ul class="nf-scene-issues">
                                    @foreach ($version['issues'] as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @foreach ($version['scenes'] as $sceneIndex => $scene)
                                <div class="nf-scene-card">
                                    <div class="nf-scene-card-head">场景 {{ $sceneIndex + 1 }}：{{ $scene['title'] ?? '未命名' }}</div>
                                    <dl class="nf-scene-fields">
                                        @foreach ($scene['rows'] as $row)
                                            <dt>{{ $row['label'] }}</dt>
                                            <dd>{{ $row['value'] }}</dd>
                                        @endforeach
                                    </dl>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
    </section>
</div>
