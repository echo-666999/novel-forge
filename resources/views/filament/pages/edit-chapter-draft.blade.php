<x-filament-panels::page>
    {{-- 步骤条与保存状态：草稿自动保存，只有“保存并重新提交”才会发出请求。 --}}
    @php($step = $versionId === null ? 0 : ($this->hasPendingCheck() ? 2 : 1))
    <x-filament::section compact>
        <ol style="display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin: 0; padding: 0; list-style: none; font-size: .875rem;">
            @foreach (['按下方问题修改正文（自动保存草稿）', '点“保存并重新提交”', '推进检查', '评审、事件提取并正式提交'] as $index => $label)
                <li style="{{ $index + 1 === $step ? 'font-weight: 600; color: var(--primary-600);' : 'color: var(--gray-500);' }}">{{ $index + 1 }}. {{ $label }}</li>
            @endforeach
        </ol>
        <p style="margin-top: .5rem; font-size: .8125rem; color: var(--gray-500);">第 3、4 步自动进行；任何一关没过会停下，再打开本页即可看到新的问题。旧正文、评审与费用都会保留。</p>
        <p role="status" aria-live="polite" style="margin-top: .5rem;">{{ $message }}</p>
        @foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
    </x-filament::section>
    @if ($versionId !== null && ($ai = $this->aiRevision()) !== null)
        {{-- AI 修订结果待作者决定：查看改动后采纳或回到上一版。 --}}
        <x-filament::section compact>
            <p style="font-size: .875rem;">
                当前候选是 AI 按你选的 {{ $ai['issues'] }} 条问题修改后的版本（推进检查：{{ match ($ai['verdict']) { 'pass' => '通过', null => '尚未完成', default => '未通过，下方列出了新问题' } }}）。
                点“查看改动”看 AI 改了哪些；满意就{{ $ai['verdict'] === 'pass' ? '点“提交（推进检查已通过）”' : '继续修改后“保存并重新提交”' }}，不满意点“不采纳，回到上一版”。
            </p>
        </x-filament::section>
    @endif
    @if ($versionId !== null)
        @php($local = $this->localChecks())
        @if ($local !== null)
            {{-- 本地检查随自动保存刷新，不调用模型；点击条目在编辑框中定位。 --}}
            <x-filament::section heading="本地检查（不花钱，随输入刷新）" compact>
                <div style="display: grid; gap: .375rem; font-size: .875rem;">
                    @if ($local['rejected'] !== null)
                        <p style="color: var(--danger-600);">当前正文与版本 {{ $local['rejected']['version'] }} 完全相同，该版本已被提交评审 #{{ $local['rejected']['review'] }} 驳回；按问题修改后才能保存。</p>
                    @endif
                    <p>
                        字数 <strong>{{ $local['count'] }}</strong>
                        @if ($local['min'] !== null)
                            / 要求 {{ $local['min'] }}–{{ $local['max'] }}
                            @if ($local['length_ok'])
                                <span style="color: var(--success-600);">· 符合</span>
                            @else
                                <span style="color: var(--danger-600);">· {{ $local['count'] < $local['min'] ? '还差 '.($local['min'] - $local['count']).' 字' : '超出 '.($local['count'] - $local['max']).' 字' }}，保存为新候选会被拒绝</span>
                            @endif
                        @endif
                    </p>
                    @forelse ($local['problems'] as $problem)
                        <button type="button" style="text-align: left; color: var(--danger-600);" x-data x-on:click="$dispatch('nf-locate', { quote: @js($problem['quote']) })">
                            · {{ $problem['label'] }} —— “{{ \Illuminate\Support\Str::limit($problem['quote'], 40) }}”
                        </button>
                    @empty
                        <p style="color: var(--gray-500);">未发现写作说明、叙述转述对白或照搬细纲。</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif
        <form wire:submit="saveDraft" style="display: grid; gap: 1.5rem;">
            {{ $this->form }}
            <div><x-filament::button type="submit" wire:loading.attr="disabled">立即保存工作草稿</x-filament::button></div>
        </form>
    @endif
</x-filament-panels::page>
