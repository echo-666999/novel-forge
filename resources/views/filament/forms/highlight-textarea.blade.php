{{--
    带问题标记的正文编辑框：文本框背景透明，背后叠一层与之同字体同排版的标记层，按原文片段给需要调整的内容加底色。
    输入、滚动时两层同步；某处改写后片段不再出现，标记随之消失。标记来源与颜色：推进检查或一键提交的阻塞问题（红）、评审建议（紫）、本地重复原文（黄）、可能的时序倒退（蓝）。
    问题按卡片列出，按引文是否还在正文中自动判断“未改动/已改写”；左侧显示段号 p1、p2……，与评审中的段号对应，原因里的段号可点击跳转。
    后台未编译自定义主题，使用本组件局部样式。
--}}
@php
    $statePath = $getStatePath();
    $marks = $source['marks'];
    $issues = $source['issues'];
    $localMarks = array_values(array_filter($marks, fn (array $mark): bool => in_array($mark['kind'], ['repeat', 'timeline'], true)));
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <script>
        // 组件逻辑放在脚本里注册，避免写进 HTML 属性时实体被提前解码。
        window.nfHighlightTextarea = (marks, issues, initial) => ({
            // 初始正文取自服务器：组件 init 先于 wire:model 给文本框赋值，此时读文本框只会得到空字符串，问题会被误判为“已改写”。
            text: initial ?? '',
            marks,
            issues,
            init() {
                // wire:model 赋值后再以文本框为准，之后由 input 事件同步。
                this.$nextTick(() => {
                    this.text = this.$refs.input.value || this.text;
                });
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
            // 问题状态：引文都还在为未改动，全部不在为已改写；无引文时按处理方式说明。
            status(issue) {
                if (issue.quotes.length === 0) {
                    return issue.scope === 'text' ? 'nolocate' : issue.scope;
                }
                const present = issue.quotes.filter((quote) => this.text.includes(quote)).length;
                return present === issue.quotes.length ? 'todo' : (present === 0 ? 'done' : 'partial');
            },
            statusLabel(issue) {
                return { todo: '未改动', partial: '部分已改', done: '原文已改写', nolocate: '无法自动定位，请对照原因修改', rerun: '不用改正文，重新一键提交即可', approval: '需在提交页处理' }[this.status(issue)];
            },
            blocking() {
                return this.issues.filter((issue) => issue.severity === 'blocking' && issue.scope === 'text' && issue.quotes.length > 0);
            },
            handled() {
                return this.blocking().filter((issue) => ['partial', 'done'].includes(this.status(issue))).length;
            },
            // 原因文字拆成普通文本与可点击段号。
            segments(reason) {
                const parts = [];
                let last = 0;
                for (const match of reason.matchAll(/(?<![A-Za-z])p(\d{1,4})/g)) {
                    if (match.index > last) {
                        parts.push({ text: reason.slice(last, match.index), paragraph: null });
                    }
                    parts.push({ text: match[0], paragraph: Number(match[1]) });
                    last = match.index + match[0].length;
                }
                if (last < reason.length) {
                    parts.push({ text: reason.slice(last), paragraph: null });
                }
                return parts;
            },
            // 段号即第几个非空行，与保存格式“每段一行”的段号一致。
            paragraphStarts() {
                const starts = new Map();
                let offset = 0;
                let number = 0;
                for (const line of this.text.split('\n')) {
                    if (line.trim() !== '') {
                        starts.set(offset, ++number);
                    }
                    offset += line.length + 1;
                }
                return starts;
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
                const starts = this.paragraphStarts();
                let html = '';
                let index = 0;
                while (index < this.text.length) {
                    if (starts.has(index)) {
                        html += '<span class="nf-hl-pn" data-n="p' + starts.get(index) + '"></span>';
                    }
                    const kind = kinds[index];
                    let end = index + 1;
                    while (end < this.text.length && kinds[end] === kind && ! starts.has(end)) {
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
                this.select(this.text.indexOf(mark.quote), mark.quote.length);
            },
            locateParagraph(number) {
                for (const [offset, paragraph] of this.paragraphStarts()) {
                    if (paragraph === number) {
                        const end = this.text.indexOf('\n', offset);
                        this.select(offset, (end === -1 ? this.text.length : end) - offset);
                        return;
                    }
                }
            },
            select(at, length) {
                if (at === -1) {
                    return;
                }
                this.$refs.input.focus();
                this.$refs.input.setSelectionRange(at, at + length);
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
        x-data="nfHighlightTextarea(@js($marks), @js($issues), @js((string) $getState()))"
        x-on:nf-locate.window="locate({ quote: $event.detail.quote })"
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
                font-size: .9375rem; line-height: 1.9; padding: .75rem 1rem .75rem 3.25rem; border-width: 0; outline: none; box-sizing: border-box; }
            .nf-hl-pn { position: relative; display: inline-block; width: 0; }
            .nf-hl-pn::before { content: attr(data-n); position: absolute; right: .75rem; top: 0; color: var(--gray-400); font-size: .75rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
            .nf-hl-head { display: grid; gap: .25rem; font-size: .875rem; }
            .nf-hl-notice { padding: .5rem .75rem; border-radius: .5rem; background: rgb(14 165 233 / .1); color: var(--gray-700); font-size: .875rem; }
            .nf-hl-cards { display: grid; gap: .5rem; max-height: 24rem; overflow: auto; }
            .nf-hl-card { display: grid; gap: .375rem; padding: .625rem .75rem; border-radius: .5rem; box-shadow: 0 0 0 1px var(--gray-200); font-size: .8125rem; color: var(--gray-700); }
            .nf-hl-card-blocking { box-shadow: 0 0 0 1px rgb(244 63 94 / .45); }
            .nf-hl-card-head { display: flex; flex-wrap: wrap; gap: .375rem; align-items: center; }
            .nf-hl-tag { padding: 0 .375rem; border-radius: .25rem; background: var(--gray-100); font-size: .75rem; }
            .nf-hl-tag-blocking { background: rgb(244 63 94 / .15); color: rgb(190 18 60); }
            .nf-hl-tag-advisory { background: rgb(139 92 246 / .15); color: rgb(109 40 217); }
            .nf-hl-state-done, .nf-hl-state-partial { color: var(--success-600); }
            .nf-hl-state-todo { color: var(--danger-600); }
            .nf-hl-link { color: var(--primary-600); text-decoration: underline; }
            .nf-hl-quote { text-align: left; color: var(--gray-600); }
            .nf-hl-quote:hover { color: var(--primary-600); }
            .nf-hl-quote.nf-hl-done { color: var(--gray-400); text-decoration: line-through; }
            .nf-hl-backdrop mark { color: transparent; border-radius: .1875rem; }
            .nf-hl-check, .nf-hl-swatch-check { background: rgb(244 63 94 / .28); }
            .nf-hl-repeat, .nf-hl-swatch-repeat { background: rgb(245 158 11 / .32); }
            .nf-hl-timeline, .nf-hl-swatch-timeline { background: rgb(14 165 233 / .28); }
            .nf-hl-block, .nf-hl-swatch-block { background: rgb(244 63 94 / .28); }
            .nf-hl-advise, .nf-hl-swatch-advise { background: rgb(139 92 246 / .24); }
            .dark .nf-hl-card { color: var(--gray-300); box-shadow: 0 0 0 1px rgb(255 255 255 / .15); }
            .dark .nf-hl-tag { background: rgb(255 255 255 / .1); }
            .dark .nf-hl-notice { color: var(--gray-200); }
            .dark .nf-hl-block, .dark .nf-hl-swatch-block { background: rgb(244 63 94 / .38); }
            .dark .nf-hl-advise, .dark .nf-hl-swatch-advise { background: rgb(139 92 246 / .34); }
            .dark .nf-hl-box { background: rgb(255 255 255 / .05); box-shadow: 0 0 0 1px rgb(255 255 255 / .2); }
            .dark .nf-hl-input { color: #fff; caret-color: #fff; }
            .dark .nf-hl-legend, .dark .nf-hl-list button { color: var(--gray-300); }
            .dark .nf-hl-list button:hover { background: rgb(255 255 255 / .05); }
            .dark .nf-hl-check, .dark .nf-hl-swatch-check { background: rgb(244 63 94 / .38); }
            .dark .nf-hl-repeat, .dark .nf-hl-swatch-repeat { background: rgb(245 158 11 / .38); }
            .dark .nf-hl-timeline, .dark .nf-hl-swatch-timeline { background: rgb(14 165 233 / .38); }
        </style>

        @if ($source['notice'] !== null)
            <p class="nf-hl-notice">{{ $source['notice'] }}</p>
        @endif
        @if ($marks !== [] || $issues !== [])
            <div class="nf-hl-legend">
                @if ($source['origin'] === 'check')
                    <span><span class="nf-hl-swatch nf-hl-swatch-check"></span>推进检查指出的问题</span>
                @elseif ($issues !== [])
                    <span><span class="nf-hl-swatch nf-hl-swatch-block"></span>阻塞问题（必须改）</span>
                    <span><span class="nf-hl-swatch nf-hl-swatch-advise"></span>建议（可选）</span>
                @endif
                <span><span class="nf-hl-swatch nf-hl-swatch-repeat"></span>本地检出的重复原文</span>
                <span><span class="nf-hl-swatch nf-hl-swatch-timeline"></span>可能的时序倒退</span>
                @if ($source['origin'] === 'check')
                    <span>· 推进检查问题来自版本 {{ $source['version'] }}；点击条目定位，改写后标记自动消失</span>
                @elseif ($issues !== [])
                    <span>· 问题来自{{ $source['title'] }}；点击引文或段号定位，改写后标记自动消失</span>
                @endif
            </div>
        @endif
        @if ($issues !== [])
            <div class="nf-hl-head">
                <strong>{{ $source['title'] }}：{{ count($issues) }} 条问题</strong>
                <span x-show="blocking().length > 0" x-text="'阻塞问题已处理 ' + handled() + ' / ' + blocking().length + (handled() === blocking().length ? ' —— 可以保存为新候选了' : '')"></span>
            </div>
            <div class="nf-hl-cards">
                <template x-for="(issue, index) in issues" :key="index">
                    <div class="nf-hl-card" :class="issue.severity === 'blocking' ? 'nf-hl-card-blocking' : ''">
                        <div class="nf-hl-card-head">
                            <span class="nf-hl-tag" :class="'nf-hl-tag-' + issue.severity" x-text="issue.severity === 'blocking' ? '阻塞' : '建议'"></span>
                            <span class="nf-hl-tag" x-text="issue.category"></span>
                            <span :class="'nf-hl-state-' + status(issue)" x-text="statusLabel(issue)"></span>
                        </div>
                        <div>
                            <template x-for="(part, partIndex) in segments(issue.reason)" :key="partIndex">
                                <span>
                                    <template x-if="part.paragraph !== null"><button type="button" class="nf-hl-link" x-on:click="locateParagraph(part.paragraph)" x-text="part.text"></button></template>
                                    <template x-if="part.paragraph === null"><span x-text="part.text"></span></template>
                                </span>
                            </template>
                        </div>
                        <div x-show="issue.hint" x-text="'建议：' + issue.hint"></div>
                        <template x-for="(quote, quoteIndex) in issue.quotes" :key="quoteIndex">
                            <button type="button" class="nf-hl-quote" :class="text.includes(quote) ? '' : 'nf-hl-done'" x-on:click="locate({ quote })"
                                x-text="'“' + (quote.length > 50 ? quote.slice(0, 50) + '…' : quote) + '”' + (text.includes(quote) ? '' : '（已不在正文中）')"></button>
                        </template>
                    </div>
                </template>
            </div>
        @endif
        @if ($localMarks !== [] || ($source['origin'] === 'check' && $marks !== []))
            <ul class="nf-hl-list">
                <template x-for="(mark, index) in marks.filter((mark) => ['repeat', 'timeline'].includes(mark.kind) || ! issues.length)" :key="index">
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
                x-on:change="text = $event.target.value"
                x-on:scroll="sync()"
                x-on:mouseup="sync()"
                x-on:keyup="sync()"
            ></textarea>
        </div>
    </div>
</x-dynamic-component>
