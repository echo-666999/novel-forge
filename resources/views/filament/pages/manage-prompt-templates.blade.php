<x-filament-panels::page>
    {{-- 操作结果以通知弹出；这里只保留服务端校验错误，避免失败无提示。 --}}
    @foreach ($errors->all() as $error)<p role="alert" style="color: var(--danger-600);">{{ $error }}</p>@endforeach
    @php
        $templateTips = implode("\n\n", [
            '内置固定提示词：替换该阶段的固定文本，不做变量替换。补充创作要求：追加在固定文本之后，公共要求用于所有阶段。',
            '补充模板可用变量：{{ title }}（书名）、{{ genre }}（题材）、{{ stage }}（阶段键）。输出 Schema、工具限制和正式提交门禁始终由程序管理。',
            '发布只影响此后新建的小说；已有小说的后续章节、重试和恢复沿用创建时冻结的模板。',
        ]);
    @endphp

    <x-filament::section compact>
        <x-slot name="heading">
            <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                <span>各阶段当前生效</span>
                <x-filament::icon-button icon="heroicon-o-question-mark-circle" color="gray" label="模板使用说明" :tooltip="$templateTips" />
            </span>
        </x-slot>
        {{-- 点版本标签查看详情，点“修改”带着该阶段当前正文进入编辑页。 --}}
        <div style="display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));">
            @foreach ($this->overviewRows() as $row)
                <div style="padding: .625rem .75rem; border-radius: .625rem; border: 1px solid rgba(127,127,127,.22);">
                    <div style="font-weight: 600; font-size: .875rem; margin-bottom: .375rem;">{{ $row['stage'] }}</div>
                    @foreach ($row['items'] as $item)
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-top: .25rem;">
                            @if ($item['id'] !== null)
                                <x-filament::badge tag="button" :color="$item['kind'] === '固定' ? 'info' : 'success'" wire:click="mountAction('details', { id: {{ $item['id'] }} })" tooltip="查看当前生效正文">{{ $item['label'] }}</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">{{ $item['label'] }}</x-filament::badge>
                            @endif
                            <x-filament::link :href="$item['edit']" size="sm">{{ $item['id'] === null && $item['kind'] === '补充' ? '添加' : '修改' }}</x-filament::link>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="版本历史" compact>
        <div style="margin-bottom: 1rem;">
            {{ $this->filters }}
        </div>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
