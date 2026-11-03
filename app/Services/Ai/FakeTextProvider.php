<?php

namespace App\Services\Ai;

/** 确定性离线供应商，不读取密钥、不调用 SDK，也不发送网络请求。 */
final class FakeTextProvider implements TextProvider
{
    /** 固定 JSON 是验收夹具，不代表模型实际生成的小说内容。 */
    public function generate(string $prompt, string $model): TextGenerationResult
    {
        $data = ['ok' => true, 'message' => 'A03 Fake Provider'];

        return new TextGenerationResult(
            text: json_encode($data, JSON_THROW_ON_ERROR),
            structured: $data,
            provider: 'fake',
            model: 'a03-fake-v1',
            usage: ['input_tokens' => null, 'output_tokens' => null, 'cache_read_tokens' => null, 'cache_write_tokens' => null, 'reasoning_tokens' => null],
            rawResponse: ['simulated' => true, 'output' => $data],
        );
    }
}
