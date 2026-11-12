{{-- 实体定义弹窗：有来源版本时先列出与来源版本的差异（审阅与批准的重点），再展示本版本完整定义；首次建立只展示定义。 --}}
<div style="display: grid; gap: 1.5rem;">
    @if ($diff !== null)
        <section aria-label="与来源版本对照">
            <h3 style="margin: 0 0 .625rem; font-size: .875rem; font-weight: 600;">与来源版本对照</h3>
            @include('filament.resources.novels.partials.field-diff', ['diff' => $diff, 'oldLabel' => $parentLabel, 'newLabel' => $currentLabel, 'sameMessage' => '本版本与来源版本的定义完全相同。'])
        </section>
    @endif

    <section aria-label="本版本完整定义">
        <h3 style="margin: 0 0 .625rem; font-size: .875rem; font-weight: 600;">{{ $diff === null ? '完整定义（首次建立，没有来源版本）' : '本版本完整定义' }}</h3>
        @include('filament.resources.novels.partials.field-list', ['groups' => $groups])
    </section>

    <p style="margin: 0; font-size: .75rem; color: var(--gray-400); overflow-wrap: anywhere;">内容摘要：{{ $hash }}</p>
</div>
