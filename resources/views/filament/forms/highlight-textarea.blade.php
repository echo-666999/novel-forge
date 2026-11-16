{{--
    带问题标记的正文编辑框：文本框背景透明，背后叠一层与之同字体同排版的标记层，按原文片段给需要调整的内容加底色。
    输入、滚动时两层同步；某处改写后片段不再出现，标记随之消失。标记来源与颜色：推进检查问题（红）、本地重复原文（黄）、可能的时序倒退（蓝）。
    后台未编译自定义主题，使用本组件局部样式。
--}}
@php
    $statePath = $getStatePath();
    $marks = $source['marks'];
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <script>
        // 组件逻辑放在脚本里注册，避免写进 HTML 属性时实体被提前解码。
        window.nfHighlightTextarea ??= (marks) => ({
            text: '',
            marks,
            init() {
                this.text = this.$refs.input.value;
                const style = getComputedStyle(this.$refs.input);
                for (const property of ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textIndent', 'tabSize',
                    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'boxSizing']) {
                    this.$refs.backdrop.style[property] = style[property];
                }
            },
            escape(value) {
                return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            },
            present(mark) {
                return this.text.includes(mark.quote);
            },
            render() {
                // 逐字记录底色：重叠时范围更短、更具体的标记优先（例如整段红色里的重复句仍显示黄色）。
                const kinds = new Array(this.text.length).fill(null);
                const lengths = new Array(this.text.length).fill(Infinity);
                for (const mark of this.marks) {
                    let from = 0;
                    let at;
                    while ((at = this.text.indexOf(mark.quote, from)) !== -1) {
                        for (let index = at; index < at + mark.quote.length; index++) {
                            if (mark.quote.length < lengths[index]) {
                                kinds[index] = mark.kind;
                                lengths[index] = mark.quote.length;
                            }
                        }
                        from = at + mark.quote.length;
                    }
                }
                let html = '';
                let index = 0;
                while (index < this.text.length) {
                    const kind = kinds[index];
                    let end = index + 1;
                    while (end < this.text.length && kinds[end] === kind) {
                        end++;
                    }
                    const segment = this.escape(this.text.slice(index, end));
                    html += kind === null ? segment : '<mark class="nf-hl-' + kind + '">' + segment + '</mark>';
                    index = end;
                }
                // 末尾补一个空行，保证最后一行换行后两层高度一致。
                return html + '\n ';
            },
            locate(mark) {
                const at = this.text.indexOf(mark.quote);
                if (at === -1) {
                    return;
                }
                this.$refs.input.focus();
                this.$refs.input.setSelectionRange(at, at + mark.quote.length);
                const lines = this.text.slice(0, at).split('\n').length;
                this.$refs.input.scrollTop = Math.max(0, (lines - 3) * (parseFloat(getComputedStyle(this.$refs.input).lineHeight) || 24));
                this.sync();
            },
            sync() {
                this.$refs.backdrop.scrollTop = this.$refs.input.scrollTop;
                this.$refs.backdrop.scrollLeft = this.$refs.input.scrollLeft;
            },
        });
    </script>
    <div
        x-data="nfHighlightTextarea(@js($marks))"
        {{-- 标记层由 Alpine 维护，自动保存后的重绘不得清空；正文仍经 wire:model 同步到服务端。 --}}
        wire:ignore
        class="nf-hl"
    >
        <style>
            .nf-hl { display: grid; gap: .75rem; }
            .nf-hl-legend { display: flex; flex-wrap: wrap; gap: .5rem 1rem; font-size: .8125rem; color: var(--gray-600); }
            .nf-hl-swatch { display: inline-block; width: .875rem; height: .875rem; border-radius: .1875rem; vertical-align: -.125rem; margin-right: .25rem; }
            .nf-hl-list { display: grid; gap: .25rem; margin: 0; padding: 0; list-style: none; font-size: .8125rem; max-height: 12rem; overflow: auto; }
            .nf-hl-list button { display: flex; gap: .5rem; width: 100%; text-align: left; padding: .25rem .5rem; border-radius: .375rem; color: var(--gray-700); }
            .nf-hl-list button:hover { background: var(--gray-50); }
            .nf-hl-list .nf-hl-done { color: var(--gray-400); text-decoration: line-through; }
            .nf-hl-box { position: relative; border-radius: .5rem; background: #fff; box-shadow: 0 0 0 1px var(--gray-300); }
            .nf-hl-box:focus-within { box-shadow: 0 0 0 2px var(--primary-600); }
            .nf-hl-backdrop, .nf-hl-input { margin: 0; border-style: solid; border-color: transparent; white-space: pre-wrap; overflow-wrap: break-word; word-break: normal; overflow-y: scroll; }
            .nf-hl-backdrop { position: absolute; inset: 0; color: transparent; pointer-events: none; overflow-x: hidden; }
            .nf-hl-input { position: relative; display: block; width: 100%; min-height: 36rem; resize: vertical; background: transparent; color: var(--gray-950); caret-color: var(--gray-950);
                font-size: .9375rem; line-height: 1.9; padding: .75rem 1rem; border-width: 0; outline: none; box-sizing: border-box; }
            .nf-hl-backdrop mark { color: transparent; border-radius: .1875rem; }
            .nf-hl-check, .nf-hl-swatch-check { background: rgb(244 63 94 / .28); }
            .nf-hl-repeat, .nf-hl-swatch-repeat { background: rgb(245 158 11 / .32); }
            .nf-hl-timeline, .nf-hl-swatch-timeline { background: rgb(14 165 233 / .28); }
            .dark .nf-hl-box { background: rgb(255 255 255 / .05); box-shadow: 0 0 0 1px rgb(255 255 255 / .2); }
            .dark .nf-hl-input { color: #fff; caret-color: #fff; }
            .dark .nf-hl-legend, .dark .nf-hl-list button { color: var(--gray-300); }
            .dark .nf-hl-list button:hover { background: rgb(255 255 255 / .05); }
            .dark .nf-hl-check, .dark .nf-hl-swatch-check { background: rgb(244 63 94 / .38); }
            .dark .nf-hl-repeat, .dark .nf-hl-swatch-repeat { background: rgb(245 158 11 / .38); }
            .dark .nf-hl-timeline, .dark .nf-hl-swatch-timeline { background: rgb(14 165 233 / .38); }
        </style>

        @if ($marks !== [])
            <div class="nf-hl-legend">
                <span><span class="nf-hl-swatch nf-hl-swatch-check"></span>推进检查指出的问题</span>
                <span><span class="nf-hl-swatch nf-hl-swatch-repeat"></span>本地检出的重复原文</span>
                <span><span class="nf-hl-swatch nf-hl-swatch-timeline"></span>可能的时序倒退</span>
                <span>· 推进检查问题来自版本 {{ $source['version'] }}；点击条目定位，改写后标记自动消失</span>
            </div>
            <ul class="nf-hl-list">
                <template x-for="(mark, index) in marks" :key="index">
                    <li>
                        <button type="button" x-on:click="locate(mark)" :class="present(mark) ? '' : 'nf-hl-done'">
                            <span class="nf-hl-swatch" :class="'nf-hl-swatch-' + mark.kind"></span>
                            <span x-text="mark.label + ' —— “' + (mark.quote.length > 40 ? mark.quote.slice(0, 40) + '…' : mark.quote) + '”' + (present(mark) ? '' : '（已不在正文中）')"></span>
                        </button>
                    </li>
                </template>
            </ul>
        @endif

        <div class="nf-hl-box">
            <div class="nf-hl-backdrop" x-ref="backdrop" aria-hidden="true" x-html="render()"></div>
            <textarea
                x-ref="input"
                id="{{ $getId() }}"
                class="nf-hl-input"
                rows="24"
                maxlength="1000000"
                wire:model.live.debounce.2000ms="{{ $statePath }}"
                x-on:input="text = $event.target.value"
                x-on:scroll="sync()"
                x-on:mouseup="sync()"
                x-on:keyup="sync()"
            ></textarea>
        </div>
    </div>
</x-dynamic-component>
