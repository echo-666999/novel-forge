{{-- “查看完整设定”弹窗：重点（核心矛盾、待决定问题、作者决定）在前，人物、世界、基调、保留细节分区展示。后台未编译自定义主题，使用本组件局部样式。 --}}
<div class="nf-setting">
    <style>
        .nf-setting { display: grid; gap: 1.5rem; font-size: .875rem; line-height: 1.7; color: var(--gray-700); }
        .nf-setting :where(h3) { margin: 0 0 .625rem; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--gray-500); }
        .nf-setting-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; font-size: .8125rem; color: var(--gray-500); }
        .nf-setting-badge { display: inline-flex; align-items: center; padding: .0625rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        .nf-setting-badge-success { background: var(--success-50); color: var(--success-700); }
        .nf-setting-badge-danger { background: var(--danger-50); color: var(--danger-700); }
        .nf-setting-badge-warning { background: var(--warning-50); color: var(--warning-700); }
        .nf-setting-focus { padding: 1rem 1.25rem; border-radius: .75rem; border: 1px solid var(--primary-200); background: var(--primary-50); }
        .nf-setting-focus-label { font-size: .75rem; font-weight: 600; color: var(--primary-700); }
        .nf-setting-conflict { margin-top: .25rem; font-size: 1rem; font-weight: 500; line-height: 1.75; color: var(--gray-950); }
        .nf-setting-open { margin-top: .875rem; padding-top: .875rem; border-top: 1px dashed var(--primary-200); }
        .nf-setting-open li + li { margin-top: .375rem; }
        .nf-setting-decisions { display: grid; gap: .75rem; margin: 0; padding: 0; list-style: none; counter-reset: decision; }
        .nf-setting-decision { position: relative; padding: .75rem 1rem .75rem 2.75rem; border-radius: .75rem; border: 1px solid var(--gray-200); counter-increment: decision; }
        .nf-setting-decision::before { content: counter(decision); position: absolute; left: 1rem; top: .8rem; width: 1.25rem; height: 1.25rem; border-radius: 9999px; background: var(--primary-600); color: #fff; font-size: .75rem; font-weight: 600; line-height: 1.25rem; text-align: center; }
        .nf-setting-question { color: var(--gray-500); }
        .nf-setting-answer { margin-top: .25rem; font-weight: 500; color: var(--gray-950); }
        .nf-setting-impact { margin-top: .25rem; font-size: .8125rem; color: var(--gray-500); }
        .nf-setting-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .nf-setting-card { padding: .875rem 1rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .nf-setting-card-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .5rem; margin-bottom: .5rem; }
        .nf-setting-card-title strong { font-size: .9375rem; color: var(--gray-950); }
        .nf-setting-card-title span { font-size: .8125rem; color: var(--gray-500); }
        .nf-setting-fields { display: grid; grid-template-columns: max-content 1fr; gap: .25rem .75rem; margin: 0; }
        .nf-setting-fields dt { color: var(--gray-500); white-space: nowrap; }
        .nf-setting-fields dd { margin: 0; }
        .nf-setting-rules { margin: .5rem 0 0; padding-left: 1.125rem; list-style: disc; }
        .nf-setting-rules li + li { margin-top: .25rem; }
        .nf-setting-tags { display: flex; flex-wrap: wrap; gap: .375rem; }
        .nf-setting-tag { padding: .125rem .625rem; border-radius: .375rem; background: var(--gray-100); color: var(--gray-700); font-size: .8125rem; }
        .nf-setting-empty { color: var(--gray-400); }
        /* 深色模式：用 .dark 前缀提高优先级，覆盖上面的浅色取值。 */
        .dark .nf-setting { color: var(--gray-300); }
        .dark .nf-setting :where(.nf-setting-conflict, .nf-setting-answer, .nf-setting-card-title strong) { color: #fff; }
        .dark .nf-setting .nf-setting-focus { border-color: color-mix(in oklab, var(--primary-400) 30%, transparent); background: color-mix(in oklab, var(--primary-400) 10%, transparent); }
        .dark .nf-setting .nf-setting-focus-label { color: var(--primary-400); }
        .dark .nf-setting .nf-setting-open { border-top-color: color-mix(in oklab, var(--primary-400) 30%, transparent); }
        .dark .nf-setting :where(.nf-setting-decision, .nf-setting-card) { border-color: var(--gray-700); }
        .dark .nf-setting .nf-setting-tag { background: var(--gray-800); color: var(--gray-300); }
        .dark .nf-setting .nf-setting-badge-success { background: color-mix(in oklab, var(--success-400) 15%, transparent); color: var(--success-400); }
        .dark .nf-setting .nf-setting-badge-danger { background: color-mix(in oklab, var(--danger-400) 15%, transparent); color: var(--danger-400); }
        .dark .nf-setting .nf-setting-badge-warning { background: color-mix(in oklab, var(--warning-400) 15%, transparent); color: var(--warning-400); }
    </style>

    <div class="nf-setting-meta">
        <span>确认于 {{ $confirmation->confirmed_at?->format('Y-m-d H:i') }}</span>
        @if ($confirmation->hasValidHash())
            <span class="nf-setting-badge nf-setting-badge-success">摘要校验有效</span>
        @else
            <span class="nf-setting-badge nf-setting-badge-danger">来源损坏，禁止使用</span>
        @endif
        <span>{{ $plans === [] ? '尚无大纲引用此记录' : '被大纲 #'.implode('、#', $plans).' 引用' }}</span>
    </div>

    <section class="nf-setting-focus" aria-label="重点关注">
        <div class="nf-setting-focus-label">核心矛盾</div>
        <p class="nf-setting-conflict">{{ $setting['core_conflict'] ?? '未填写核心矛盾' }}</p>
        @if ($setting['open_questions'] !== [])
            <div class="nf-setting-open">
                <div class="nf-setting-focus-label">待决定问题（{{ count($setting['open_questions']) }}）</div>
                <ul class="nf-setting-rules">
                    @foreach ($setting['open_questions'] as $item)
                        <li>
                            {{ $item['question'] }}
                            @if ($item['blocking'])<span class="nf-setting-badge nf-setting-badge-warning">阻塞</span>@endif
                            @if ($item['impact'])<div class="nf-setting-impact">影响：{{ $item['impact'] }}</div>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <section aria-label="作者决定">
        <h3>作者决定（{{ count($setting['decisions']) }}）</h3>
        @if ($setting['decisions'] === [])
            <p class="nf-setting-empty">本次确认没有需要作者回答的问题。</p>
        @else
            <ol class="nf-setting-decisions">
                @foreach ($setting['decisions'] as $item)
                    <li class="nf-setting-decision">
                        @if ($item['question'])<div class="nf-setting-question">{{ $item['question'] }}</div>@endif
                        <div class="nf-setting-answer">
                            {{ $item['answer'] ?? '未作答' }}
                            @if ($item['blocking'])<span class="nf-setting-badge nf-setting-badge-warning">阻塞</span>@endif
                        </div>
                        @if ($item['impact'])<div class="nf-setting-impact">影响：{{ $item['impact'] }}</div>@endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    @if ($setting['characters'] !== [])
        <section aria-label="人物">
            <h3>人物（{{ count($setting['characters']) }}）</h3>
            <div class="nf-setting-grid">
                @foreach ($setting['characters'] as $character)
                    <div class="nf-setting-card">
                        <div class="nf-setting-card-title">
                            <strong>{{ $character['name'] }}</strong>
                            @if ($character['role'])<span>{{ $character['role'] }}</span>@endif
                        </div>
                        <dl class="nf-setting-fields">
                            @foreach ($character['fields'] as $field)
                                <dt>{{ $field['label'] }}</dt>
                                <dd>{{ $field['value'] }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="nf-setting-grid">
        <section class="nf-setting-card" aria-label="世界与规则">
            <h3>世界与规则</h3>
            <dl class="nf-setting-fields">
                <dt>时代</dt>
                <dd>{{ $setting['world']['era'] ?? '未填写' }}</dd>
                <dt>背景</dt>
                <dd>{{ $setting['world']['background'] ?? '未填写' }}</dd>
            </dl>
            @if ($setting['world']['rules'] !== [])
                <ul class="nf-setting-rules">
                    @foreach ($setting['world']['rules'] as $rule)
                        <li>{{ $rule }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($setting['tone'] !== [])
            <section class="nf-setting-card" aria-label="基调">
                <h3>基调</h3>
                <dl class="nf-setting-fields">
                    @foreach ($setting['tone'] as $field)
                        <dt>{{ $field['label'] }}</dt>
                        <dd>{{ $field['value'] }}</dd>
                    @endforeach
                </dl>
            </section>
        @endif
    </div>

    @if ($setting['preserved'] !== [])
        <section aria-label="保留细节">
            <h3>原始创意中必须保留的细节（{{ count($setting['preserved']) }}）</h3>
            <div class="nf-setting-tags">
                @foreach ($setting['preserved'] as $detail)
                    <span class="nf-setting-tag">{{ $detail }}</span>
                @endforeach
            </div>
        </section>
    @endif

    @if ($setting['other'] !== [])
        <section aria-label="其他">
            <h3>其他</h3>
            <ul class="nf-setting-rules">
                @foreach ($setting['other'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
