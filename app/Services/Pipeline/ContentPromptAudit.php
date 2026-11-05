<?php

namespace App\Services\Pipeline;

use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\NovelStage;

/** 只读展示实际请求证据；历史还原必须匹配调用时保存的摘要。 */
final class ContentPromptAudit
{
    /** 新记录读取调用前快照；旧记录只根据冻结来源还原，不覆盖数据库。
     * @return array<string, mixed>
     */
    public function read(CostLog $log): array
    {
        $audit = $log->request_parameters['prompt_audit'] ?? null;
        if (is_array($audit)) {
            $valid = is_string($audit['user_prompt'] ?? null) && is_string($audit['system_prompt'] ?? null)
                && is_array($audit['response_schema'] ?? null)
                && hash('sha256', $audit['user_prompt']) === $log->prompt_hash
                && $audit['user_hash'] === $log->prompt_hash
                && hash('sha256', $audit['system_prompt']) === $audit['system_hash']
                && PlanningJson::hash($audit['response_schema']) === $audit['schema_hash'];

            return $valid ? ['status' => '调用前完整快照，摘要通过', ...$audit] : ['status' => '审计摘要不一致，拒绝展示为可信原文'];
        }
        $stage = NovelStage::find($log->novel_stage_id);
        $run = ChapterGenerationRun::find(data_get($stage?->input, 'run_id'));
        if ($run === null) {
            return ['status' => '缺少历史冻结来源，无法还原'];
        }
        $step = data_get($stage->input, 'step');
        $policy = $run->snapshot['novel']['chapter_length_policy'];
        $payload = ['frozen' => $run->snapshot, 'chapter_plan' => $step === 'fine' ? $run->snapshot['source_chapter'] : $run->plan_data, 'word_policy' => $policy];
        if (($run->config['version'] ?? null) !== 'b04-content-v1') {
            $payload += ['target_characters' => intdiv($policy['min_characters'] + $policy['max_characters'], 2), 'count_rule' => ContentSchema::COUNT_VERSION];
        }
        $version = ChapterVersion::find(data_get($stage->input, 'version_id'));
        if ($version !== null) {
            $payload += ['body_hash' => $version->body_hash, 'paragraphs' => ContentSchema::paragraphs($version->body)];
        }
        if ($step === 'length_repair' && $version !== null) {
            $payload['length_budget'] = ['current_characters' => $version->character_count,
                'minimum_addition' => $policy['min_characters'] - $version->character_count,
                'maximum_addition' => $policy['max_characters'] - $version->character_count,
                'target_addition' => intdiv($policy['min_characters'] + $policy['max_characters'], 2) - $version->character_count];
        }
        if (isset($run->config['paragraph_budget'])) {
            $payload['paragraph_budget'] = $run->config['paragraph_budget'];
        }
        $prompt = '本次步骤 '.$step.'；冻结输入：'.PlanningJson::encode($payload);
        $verified = hash('sha256', $prompt) === $log->prompt_hash;

        return ['status' => $verified ? '历史User Prompt已验证还原' : '历史User Prompt无法按摘要确认',
            'user_prompt' => $verified ? $prompt : '无法确认，不展示推测内容',
            'system_prompt' => data_get($run->config, 'protocol.'.$step.'.instructions') ?? '历史未保存，无法确认',
            'response_schema' => data_get($log->raw_response, 'text.format.schema'), 'parameters' => $log->request_parameters];
    }
}
