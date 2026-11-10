<x-filament-panels::page>
    <x-filament::section heading="新增模板草稿">
        <p>内置固定提示词替换对应阶段固定文本；补充创作要求追加到固定文本之后。保存草稿不会立即生效，测试并发布后仅影响新建小说。</p>
        <p>补充模板支持变量：@{{ title }}、@{{ genre }}、@{{ stage }}；内置固定文本不进行变量替换。</p>
        {{-- 独立新增页使用原生表单和错误提示，校验失败保留作者输入。 --}}
        @foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
        <form wire:submit="saveDraft" style="display: grid; gap: 1.5rem; margin-top: 1.5rem;">
            {{ $this->form }}
            <div style="display: flex; gap: 0.75rem;">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveDraft">保存为新草稿</x-filament::button>
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\ManagePromptTemplates::getUrl()">取消</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
