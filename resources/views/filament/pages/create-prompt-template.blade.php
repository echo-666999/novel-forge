<x-filament-panels::page>
    {{-- 左侧编辑、右侧实时预览；窄屏自动上下排列。后台未编译自定义主题，布局使用局部样式。 --}}
    <style>
        .nf-tpl-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
        .nf-tpl-pre { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; padding: .75rem 1rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.25); background: rgba(127,127,127,.06); font-size: .875rem; }
        @media (min-width: 1280px) {
            .nf-tpl-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); }
            .nf-tpl-side { position: sticky; top: 5rem; align-self: start; max-height: calc(100vh - 6rem); overflow-y: auto; }
        }
    </style>
    @php($preview = $this->preview())

    <div class="nf-tpl-grid">
        <x-filament::section heading="新增模板草稿" compact>
            {{-- 独立编辑页使用原生表单和错误提示，校验失败保留作者输入。 --}}
            @foreach ($errors->all() as $error)<p role="alert" style="color: var(--danger-600);">{{ $error }}</p>@endforeach
            <form wire:submit="saveDraft" style="display: grid; gap: 1.25rem;">
                {{ $this->form }}
                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                    <x-filament::button type="button" color="success" wire:click="saveAndPublish" wire:loading.attr="disabled" wire:target="saveAndPublish">保存并发布</x-filament::button>
                    <x-filament::button type="submit" color="gray" wire:loading.attr="disabled" wire:target="saveDraft">仅保存草稿</x-filament::button>
                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Pages\ManagePromptTemplates::getUrl()">取消</x-filament::button>
                </div>
                <p style="margin: 0; font-size: .8125rem; color: var(--gray-500);">“保存并发布”会先做本地变量与渲染回归（不发送 AI 请求），通过后立即生效；只影响此后新建的小说。</p>
            </form>
        </x-filament::section>

        <div class="nf-tpl-side" style="display: grid; gap: 1.5rem;">
            <x-filament::section heading="效果预览" description="补充模板用样例书名“验收样例书名”、题材“现实生活”替换变量；内置固定文本原样使用。" compact>
                @if ($preview['error'])
                    <p role="alert" style="margin: 0; color: var(--danger-600);">{{ $preview['error'] }}</p>
                @elseif ($preview['rendered'] === null)
                    <p style="margin: 0; color: var(--gray-400);">输入正文后在这里预览。</p>
                @else
                    <div class="nf-tpl-pre">{{ $preview['rendered'] }}</div>
                @endif
            </x-filament::section>

            <x-filament::section heading="与当前生效版本对照" compact>
                @if ($preview['publishedLabel'] === null)
                    <p style="margin: 0; color: var(--gray-400);">本阶段暂无生效版本，发布后将首次生效。</p>
                @elseif ($preview['diff'] === null)
                    <p style="margin: 0; color: var(--gray-400);">与当前生效的 {{ $preview['publishedLabel'] }} 完全相同，修改正文后显示改动。</p>
                @else
                    @include('filament.resources.novels.partials.body-diff', ['diff' => $preview['diff'], 'oldLabel' => '当前生效 '.$preview['publishedLabel'], 'newLabel' => '编辑中'])
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
