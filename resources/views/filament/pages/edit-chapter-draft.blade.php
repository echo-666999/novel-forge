<x-filament-panels::page>
    {{-- 保存状态独立于正文，实时反馈不会暗示候选已审核或正式提交。 --}}
    <x-filament::section heading="工作草稿与不可变候选">
        <p>自动保存仅更新工作草稿；保存为新候选后，原正文与原评审仍保留，新候选需重新检查。</p>
        <p role="status" aria-live="polite" style="margin-top: .75rem;">{{ $message }}</p>
        @foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
    </x-filament::section>
    @if ($versionId !== null)
        <form wire:submit="saveDraft" style="display: grid; gap: 1.5rem;">
            {{ $this->form }}
            <div><x-filament::button type="submit" wire:loading.attr="disabled">立即保存工作草稿</x-filament::button></div>
        </form>
    @endif
</x-filament-panels::page>
