<?php

namespace Tests;

use App\Services\Pipeline\ContextBuilder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;

abstract class TestCase extends BaseTestCase
{
    /** 还原Responses请求中的完整用户提示；共享资料前置时与审计快照中的用户提示一致。 */
    public static function requestPrompt(Request $request): string
    {
        $input = $request['input'];
        if (count($input) === 3 && $input[0]['role'] === 'user' && $input[1]['role'] === 'system') {
            return $input[0]['content'][0]['text'].ContextBuilder::SHARED_SEPARATOR.$input[2]['content'][0]['text'];
        }

        return $input[1]['content'][0]['text'];
    }

    /** Responses请求中的系统指令，不论是否共享资料前置。 */
    public static function requestSystem(Request $request): string
    {
        $input = $request['input'];

        return count($input) === 3 && $input[0]['role'] === 'user' ? $input[1]['content'] : $input[0]['content'];
    }

    /** 合并共享资料与本次输入，得到模型实际收到的完整冻结输入。
     * @return array<string, mixed>
     */
    public static function requestPayload(Request $request, string $marker = '冻结输入：'): array
    {
        return self::promptPayload(self::requestPrompt($request), $marker);
    }

    /** 从完整用户提示解析冻结输入；共享资料前置时合并两部分。
     * @return array<string, mixed>
     */
    public static function promptPayload(string $prompt, string $marker = '冻结输入：'): array
    {
        $shared = [];
        $parts = ContextBuilder::splitShared($prompt);
        if ($parts !== null) {
            $shared = json_decode(mb_substr($parts[0], mb_strlen(ContextBuilder::SHARED_MARKER)), true, flags: JSON_THROW_ON_ERROR);
            $prompt = $parts[1];
        }

        return [...$shared, ...json_decode(explode($marker, $prompt, 2)[1], true, flags: JSON_THROW_ON_ERROR)];
    }
}
