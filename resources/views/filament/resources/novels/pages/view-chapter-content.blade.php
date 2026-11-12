<x-filament-panels::page>
    {{-- 表格位于「运行与历史」标签内；先于标签渲染动作弹窗，否则在其他标签下点头部按钮时弹窗随隐藏标签一起不可见。 --}}
    <x-filament-actions::modals />

    {{ $this->content }}
</x-filament-panels::page>
