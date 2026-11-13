{{-- 原文引用默认折叠，点击“原文依据”展开；参数 $quotes = list<{paragraph: ?int, quote: string}>。样式随所在组件。 --}}
@if ($quotes !== [])
    <details>
        <summary>原文依据（{{ count($quotes) }} 处）</summary>
        @foreach ($quotes as $quote)
            <blockquote>{{ $quote['paragraph'] !== null ? '第'.$quote['paragraph'].'段：' : '' }}{{ $quote['quote'] }}</blockquote>
        @endforeach
    </details>
@endif
