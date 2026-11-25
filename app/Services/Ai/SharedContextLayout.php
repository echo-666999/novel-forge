<?php

namespace App\Services\Ai;

use App\Services\Pipeline\ContextBuilder;

/** 共享资料前置的请求排列：同章各步骤共用的资料放在步骤指令之前，供应商才能按前缀复用缓存。 */
final class SharedContextLayout
{
    /** Responses协议改为“用户共享资料 → 系统步骤指令（含输出契约）→ 用户本次输入”；不是共享资料前置的提示返回 null，调用方保持原排列。
     * @param  array<int, mixed>  $input
     * @return list<array<string, mixed>>|null
     */
    public static function responsesInput(array $input, ?string $contract = null): ?array
    {
        $last = array_key_last($input);
        if ($last === null || ! array_is_list($input) || ($input[$last]['role'] ?? null) !== 'user' || count($input[$last]['content'] ?? []) !== 1) {
            return null;
        }
        $parts = is_string($input[$last]['content'][0]['text'] ?? null) ? ContextBuilder::splitShared($input[$last]['content'][0]['text']) : null;
        $instructions = array_slice($input, 0, $last);
        // 只处理单条用户输入及其前置系统指令，多轮或工具消息不改排列。
        if ($parts === null || array_filter($instructions, fn (mixed $item): bool => ($item['role'] ?? null) !== 'system' || ! is_string($item['content'] ?? null)) !== []) {
            return null;
        }
        $system = implode("\n\n", array_filter([...array_column($instructions, 'content'), $contract], fn (?string $text): bool => $text !== null && $text !== ''));
        $user = fn (string $text): array => ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $text]]];

        return [$user($parts[0]), ...($system === '' ? [] : [['role' => 'system', 'content' => $system]]), $user($parts[1])];
    }

    /** Messages协议的系统指令固定在顶层，只把共享资料与本次输入拆成两个文本块，发送内容不变。
     * @param  array<int, mixed>  $messages
     * @return array<int, mixed>
     */
    public static function anthropicMessages(array $messages): array
    {
        $last = array_key_last($messages);
        $content = $last === null ? null : ($messages[$last]['content'] ?? null);
        if (($messages[$last]['role'] ?? null) !== 'user' || ! is_array($content) || count($content) !== 1 || ($content[0]['type'] ?? null) !== 'text' || ! is_string($content[0]['text'] ?? null)) {
            return $messages;
        }
        $parts = ContextBuilder::splitShared($content[0]['text']);
        if ($parts !== null) {
            $messages[$last]['content'] = [['type' => 'text', 'text' => $parts[0]], ['type' => 'text', 'text' => $parts[1]]];
        }

        return $messages;
    }
}
