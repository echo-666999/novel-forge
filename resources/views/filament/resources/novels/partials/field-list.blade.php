{{--
    结构化内容的分组展示（实体定义、场景规划等共用），替代整段 JSON。
    参数：$groups = list<{title: ?string, rows: list<{label: string, value?: ?string, items?: list<string>, tags?: bool}>}>
    值为空的行显示“未填写”；items 默认按条目列出，tags 为真时显示为标签。后台未编译自定义主题，使用本组件局部样式。
--}}
<div class="nf-fields">
    <style>
        .nf-fields { display: grid; gap: 1rem; font-size: .875rem; line-height: 1.7; color: var(--gray-700); }
        .nf-fields h3 { margin: 0 0 .5rem; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--gray-500); }
        .nf-fields-dl { display: grid; grid-template-columns: fit-content(10rem) 1fr; gap: .375rem .75rem; margin: 0; }
        .nf-fields-dl dt { color: var(--gray-500); }
        .nf-fields-dl dd { margin: 0; white-space: pre-line; overflow-wrap: anywhere; }
        .nf-fields-empty { color: var(--gray-400); }
        .nf-fields-items { margin: 0; padding-left: 1.125rem; list-style: disc; }
        .nf-fields-items li + li { margin-top: .25rem; }
        .nf-fields-tags { display: flex; flex-wrap: wrap; gap: .375rem; }
        .nf-fields-tag { padding: .0625rem .5rem; border-radius: .375rem; background: var(--gray-100); font-size: .8125rem; }
        .dark .nf-fields { color: var(--gray-300); }
        .dark .nf-fields .nf-fields-tag { background: var(--gray-800); }
    </style>
    @foreach ($groups as $group)
        <section @if (filled($group['title'] ?? null)) aria-label="{{ $group['title'] }}" @endif>
            @if (filled($group['title'] ?? null))
                <h3>{{ $group['title'] }}</h3>
            @endif
            <dl class="nf-fields-dl">
                @foreach ($group['rows'] as $row)
                    <dt>{{ $row['label'] }}</dt>
                    <dd>
                        @if (array_key_exists('items', $row))
                            @if ($row['items'] === [])
                                <span class="nf-fields-empty">无</span>
                            @elseif ($row['tags'] ?? false)
                                <span class="nf-fields-tags">
                                    @foreach ($row['items'] as $item)
                                        <span class="nf-fields-tag">{{ $item }}</span>
                                    @endforeach
                                </span>
                            @else
                                <ul class="nf-fields-items">
                                    @foreach ($row['items'] as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @elseif (filled($row['value'] ?? null))
                            {{ $row['value'] }}
                        @else
                            <span class="nf-fields-empty">未填写</span>
                        @endif
                    </dd>
                @endforeach
            </dl>
        </section>
    @endforeach
</div>
