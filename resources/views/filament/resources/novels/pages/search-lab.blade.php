<x-filament-panels::page>
    {{-- 作者检索显式提交，正文与来源均使用转义输出，不把检索片段标为角色知识。 --}}
    <x-filament::section heading="检索正式正文" description="仅限本书、所选章位之前的正式版本。作者视角结果不能直接用作角色可知事实。">
        <form wire:submit="search" class="space-y-4">
            <label class="block">关键词或情节描述
                <x-filament::input.wrapper><x-filament::input wire:model.blur="query" required maxlength="200" aria-label="关键词或情节描述" /></x-filament::input.wrapper>
            </label>
            @error('query') <p role="alert">{{ $message }}</p> @enderror
            <label class="block">截至正式章号
                {{-- 明确选择范围，不使用可能保留默认全书值的数字输入框。 --}}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="throughChapter" required aria-label="截至正式章号">
                        <option value="">请选择截至章节</option>
                        @foreach ($this->chapterOptions() as $number => $label)
                            <option value="{{ $number }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @error('throughChapter') <p role="alert">{{ $message }}</p> @enderror
            <label class="block"><input type="checkbox" wire:model.live="semantic"> 启用语义召回（调用OpenAI嵌入接口，成功查询可复用缓存）</label>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="search">检索</x-filament::button>
            <span wire:loading wire:target="search" role="status">正在检索，请稍候…</span>
        </form>
    </x-filament::section>
    @if ($searchError) <p role="alert">{{ $searchError }}</p> @endif
    @if ($result !== null)
        <x-filament::section heading="本次已执行的检索结果">
            @if ($result['warning']) <p role="status">{{ $result['warning'] }}</p> @endif
            <p>实体精确 {{ $result['channel_counts']['exact'] }} · 中文全文 {{ $result['channel_counts']['fulltext'] }} · 模糊 {{ $result['channel_counts']['fuzzy'] }} · 向量 {{ $result['channel_counts']['vector'] ?? '未执行' }}</p>
            <p>本书 #{{ $result['novel_id'] }} · 截至第 {{ $result['through_chapter'] }} 章 · {{ count($result['results']) }} 个来源版本</p>
        </x-filament::section>
        @forelse ($result['results'] as $hit)
            <x-filament::section :heading="'第'.$hit['chapter'].'章 · 正式正文记录 #'.$hit['version_id']">
                <p>命中通路：{{ implode('、', array_map(fn ($name) => ['exact'=>'实体精确','fulltext'=>'中文全文','fuzzy'=>'模糊','vector'=>'向量'][$name], array_keys($hit['channels']))) }} · 融合分数 {{ number_format($hit['score'], 5) }}</p>
                <p class="whitespace-pre-wrap">{{ $hit['quote'] }}</p>
                <p class="break-all">来源摘要 {{ $hit['body_hash'] }} · 原文码点起始 {{ $hit['start_offset'] }}</p>
                <x-filament::link :href="\App\Filament\Resources\Novels\NovelResource::getUrl('state', ['record'=>$result['novel_id'], 'chapter'=>$hit['chapter']])">查看本章正式正文及状态</x-filament::link>
            </x-filament::section>
        @empty
            <p role="status">未找到匹配的正式正文。请确认关键词、章节上限及索引是否已构建。</p>
        @endforelse
    @endif
</x-filament-panels::page>
