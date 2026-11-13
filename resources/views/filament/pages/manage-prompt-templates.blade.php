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
        {{ $this->overview }}
    </x-filament::section>

    <x-filament::section heading="版本历史" compact>
        <div style="margin-bottom: 1rem;">
            {{ $this->filters }}
        </div>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
