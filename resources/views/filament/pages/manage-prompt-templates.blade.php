<x-filament-panels::page>
    {{-- 说明移入标题旁提示，操作反馈保留可见，避免发布结果被折叠隐藏。 --}}
    @if ($message)<p role="status">{{ $message }}</p>@endif
    @foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
    @php
        $templateTips = implode("\n\n", [
            '“内置固定”版本替换对应阶段的固定提示词；公共与阶段补充模板追加创作要求。输出 Schema、工具限制及正式提交门禁仍由程序管理。',
            '新小说创建时冻结全部发布模板及变量；后续章节、重试和恢复沿用旧快照。语言：中文（zh-CN）。',
            '补充模板可用变量（内置固定文本不进行变量替换）：{{ title }}（书名）、{{ genre }}（题材）、{{ stage }}（阶段键）。不执行 PHP 或 Blade。',
        ]);
    @endphp
    <x-filament::section>
        <x-slot name="heading">
            <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                <span>版本与发布历史</span>
                <x-filament::icon-button icon="heroicon-o-question-mark-circle" color="gray" label="模板使用说明" :tooltip="$templateTips" />
            </span>
        </x-slot>
        {{-- 筛选区与表格保持独立间距，避免输入框边框紧贴表头。 --}}
        <div style="margin-bottom: 1.5rem;">
            {{ $this->filters }}
        </div>
        {{-- 使用与小说列表一致的 Filament 表格，正文通过行内详情弹窗查看。 --}}
        {{ $this->table }}
    </x-filament::section>
    <x-filament::section heading="小说根运行冻结记录" collapsible collapsed>
        @forelse ($bindings as $binding)
            <details style="padding-block: 0.5rem;">
                <summary><a href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('view', ['record' => $binding['id']]) }}">#{{ $binding['id'] }} {{ $binding['title'] }}</a></summary>
                @if ($binding['snapshot'] === null)<p>历史运行：原程序契约，未回填当前模板。</p>
                @else
                    <p>快照摘要：{{ $binding['snapshot']['hash'] }}</p>
                    @forelse ($binding['snapshot']['items'] as $item)<p>{{ $item['scope'] }} · {{ $versionLabels[$item['id']] ?? ('记录 #'.$item['id']) }}</p><pre style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $item['body'] }}</pre>
                    @empty<p>创建时无发布模板，保持原程序契约。</p>@endforelse
                @endif
            </details>
        @empty<p>暂无真实小说根运行。</p>@endforelse
    </x-filament::section>
</x-filament-panels::page>
