<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/** v6针对全文复核漏检追加一次修订；逐条回答冻结线索，不覆盖v5历史。 */
final class ContentReadingContract
{
    public const VERSION = 'b04-content-v6';

    /** 沿用v5正文约束，新版本要求审查每条阅读线索和相邻动作。 */
    public static function instructions(string $step): string
    {
        return ContentQualityContract::instructions($step).($step === 'revise'
            ? '另须逐条处理reading_findings的全文复核线索。原检查通过不代表这些问题已解决。调查结束后不能又决定次日开始同一调查；确认来信和访客到来不能在相邻段落重新开始。替换重复段时应推进已发生事件的后续动作与信息，而非换词再演一次。最多5段替换，保持篇幅。'
            : '必须对reading_findings每个ID分别返回reading_reviews，fixed说明原风险在当前正文是否已消除，reason解释时序或动作如何衔接，evidence_ids选择当前正文段落。不能遗漏、不能用故事悬念掩盖动作重演；未解决则fixed=false。特别核对每次出门、回塔、起身、走向门口和询问身份的前后顺序。');
    }

    /** 冻结风险ID枚举，检查不能以空数组跳过全文复核。
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema, string $step, array $anchors, array $rules, array $findings): array
    {
        $result = ContentQualityContract::schema($schema, $step, $anchors, $rules);
        if ($step === 'check') {
            $result['reading_reviews'] = $schema->array()->min(count($findings))->max(count($findings))->items($schema->object([
                'id' => $schema->string()->enum(array_keys($findings))->required(), 'fixed' => $schema->boolean()->required(),
                'reason' => $schema->string()->required(), 'evidence_ids' => $schema->array()->min(1)->items($schema->string()->enum(array_keys($anchors)))->required(),
            ])->withoutAdditionalProperties())->required();
        }

        return $result;
    }

    /** 原推进检查与逐项复核独立保存；任一未解决时不能通过。
     * @return array<string, mixed>
     */
    public static function check(array $data, string $body, array $pointIds, array $rules, array $findings): array
    {
        $reviews = $data['reading_reviews'] ?? null;
        unset($data['reading_reviews']);
        $result = ContentQualityContract::check($data, $body, $pointIds, $rules);
        ContentSchema::listing($reviews);
        $seen = [];
        $anchors = ContentSchema::anchors($body);
        $normalized = [];
        foreach ($reviews as $review) {
            ContentSchema::keys($review, ['id', 'fixed', 'reason', 'evidence_ids']);
            if (! is_string($review['id']) || ! isset($findings[$review['id']]) || isset($seen[$review['id']]) || ! is_bool($review['fixed'])) {
                throw new InvalidArgumentException('全文复核编号未知、重复或判断无效');
            }
            $seen[$review['id']] = true;
            ContentSchema::text($review['reason']);
            ContentSchema::listing($review['evidence_ids']);
            $evidence = [];
            foreach ($review['evidence_ids'] as $id) {
                if (! is_string($id) || ! isset($anchors[$id]) || isset($evidence[$id])) {
                    throw new InvalidArgumentException('全文复核引用未知或重复证据ID');
                }
                $evidence[$id] = ['paragraph' => $anchors[$id]['paragraph'], 'quote' => $anchors[$id]['text']];
            }
            if ($evidence === []) {
                throw new InvalidArgumentException('全文复核缺少当前正文证据');
            }
            // 复用原定位器，否定结论也必须附带可定位证据。
            $mapped = (new ProgressionChecker)->validate(['points' => [['id' => $review['id'], 'met' => true, 'reason' => $review['reason'], 'evidence' => array_values($evidence)]],
                'hook' => ['met' => false, 'reason' => '定位复用，不是钩子判断', 'evidence' => []], 'conflicts' => []], $body, [$review['id']]);
            $normalized[] = [...$review, 'evidence' => $mapped['points'][0]['evidence']];
            if (! $review['fixed'] && $result['verdict'] === 'pass') {
                $result['verdict'] = 'insufficient';
            }
        }
        if (array_diff(array_keys($findings), array_keys($seen)) !== []) {
            throw new InvalidArgumentException('全文复核遗漏冻结线索');
        }

        return [...$result, 'reading_reviews' => $normalized];
    }
}
