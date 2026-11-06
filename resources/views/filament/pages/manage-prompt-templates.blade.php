<x-filament-panels::page>
    <x-filament::section heading="公共与阶段模板">
        <p>模板是程序契约之外的补充要求。输出 Schema、工具限制及正式提交门禁由程序管理，不可在此修改。</p>
        <p>新小说创建时冻结全部发布模板及变量；后续章节、重试和恢复沿用旧快照。语言：中文（zh-CN）。</p>
        <p>可用变量：@{{ title }}（书名）、@{{ genre }}（题材）、@{{ stage }}（阶段键）。不执行 PHP 或 Blade。</p>
        @if ($message)<p role="status">{{ $message }}</p>@endif
        @foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
        {{-- 原生 Schema 提供标签、边框、焦点与垂直布局，不依赖后台未注册的工具类。 --}}
        <form wire:submit="saveDraft" style="display: grid; gap: 1.5rem; margin-top: 1.5rem;">
            {{ $this->form }}
            <div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveDraft">保存为新草稿</x-filament::button></div>
        </form>
    </x-filament::section>
    <x-filament::section heading="版本与发布历史">
        {{ $this->filters }}
        {{-- 显式局部排版只用于只读内容，历史长文本必须换行避免横向溢出。 --}}
        <div style="display: grid; gap: 1rem; margin-top: 1.5rem;">
        @forelse ($versions as $version)
            <x-filament::section :heading="'版本 #'.$version->id" wire:key="template-{{ $version->id }}">
                <div style="display: grid; gap: 0.75rem;">
                <h3>#{{ $version->id }} · {{ \App\Services\Ai\PromptTemplateManager::SCOPES[$version->scope] }} · {{ $version->status }} · {{ $version->language }} · 根运行引用 {{ $references[$version->id] ?? 0 }}</h3>
                <p>{{ $version->notes }}</p>
                <pre style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $version->body }}</pre>
                <details style="padding-block: 0.5rem;"><summary>比较当前发布版本 / 本地回归样例</summary>
                    <p>当前发布：#{{ $routes[$version->scope] ?? '无（程序契约）' }}</p>
                    <pre style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $published[$version->scope]->body ?? '无补充模板' }}</pre>
                    <pre style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $version->test_report ?? '尚未测试；不可发布' }}</pre>
                </details>
                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                <x-filament::button wire:click="copyVersion({{ $version->id }})">复制编辑</x-filament::button>
                @if (in_array($version->status, ['draft', 'tested']))<x-filament::button wire:click="testVersion({{ $version->id }})">验证变量并测试</x-filament::button>@endif
                @if ($version->status === 'tested')<x-filament::button wire:click="publishVersion({{ $version->id }})" wire:confirm="确认发布此版本，仅影响未来新小说？">发布</x-filament::button>@endif
                @if ($version->status === 'retired')<x-filament::button wire:click="publishVersion({{ $version->id }}, true)" wire:confirm="确认回滚到此历史版本？">回滚到此版本</x-filament::button>@endif
                @if ($version->status === 'published')<x-filament::button wire:click="retireVersion('{{ $version->scope }}')" wire:confirm="确认退役，未来新小说恢复此阶段程序契约？">退役</x-filament::button>@endif
                </div>
                </div>
            </x-filament::section>
        @empty<p>尚无补充模板，所有阶段使用原程序契约。</p>@endforelse
        </div>
    </x-filament::section>
    <x-filament::section heading="小说根运行冻结记录">
        @forelse ($bindings as $binding)
            <details style="padding-block: 0.5rem;">
                <summary><a href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('view', ['record' => $binding['id']]) }}">#{{ $binding['id'] }} {{ $binding['title'] }}</a></summary>
                @if ($binding['snapshot'] === null)<p>历史运行：原程序契约，未回填当前模板。</p>
                @else
                    <p>快照摘要：{{ $binding['snapshot']['hash'] }}</p>
                    @forelse ($binding['snapshot']['items'] as $item)<p>{{ $item['scope'] }} · 版本 #{{ $item['id'] }}</p><pre style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $item['body'] }}</pre>
                    @empty<p>创建时无发布模板，保持原程序契约。</p>@endforelse
                @endif
            </details>
        @empty<p>暂无真实小说根运行。</p>@endforelse
    </x-filament::section>
</x-filament-panels::page>
