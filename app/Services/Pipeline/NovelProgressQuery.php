<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\ProductionFailure;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\Novel;
use App\Models\ProductionRun;

/** 只读汇总一本书“在哪一步、为什么停、下一步做什么”，供工作台、列表与总览共用；不改变任何状态。 */
final class NovelProgressQuery
{
    /** 工作台步骤条的固定顺序。 */
    public const STEPS = ['idea' => '创意', 'setting' => '设定确认', 'outline' => '大纲', 'production' => '生产', 'acceptance' => '验收', 'export' => '导出'];

    /** 返回当前步骤、标题、说明、建议动作（页面动作名）或入口链接，以及是否有在途任务。
     * @return array{step: string, headline: string, detail: string, action: ?string, url: ?string, running: bool}
     */
    public function for(Novel $novel): array
    {
        if ($novel->generation_mode === GenerationMode::Simulation) {
            return $this->result('idea', '模拟流程（仅用于自检，不生成正文）', '这本书使用模拟流程创建，不会调用模型。需要真实创作时请新建一本书。', running: in_array($novel->status->value, ['pending', 'running'], true));
        }
        $run = ProductionRun::activeFor($novel->id);
        if ($run !== null) {
            $step = in_array($run->step, ProductionRunService::PLANNING_STEPS, true) ? ($run->step === 'outline' ? 'outline' : 'setting') : 'production';
            $url = NovelResource::getUrl('run', ['record' => $novel]);
            if ($run->status === 'failed') {
                $failure = ProductionFailure::describe($run->error, $run->step);

                return $this->result($step, '需要你处理：'.$failure['label'], $failure['advice'], url: $url);
            }

            return $this->result($step, '正在自动生成：第'.($run->artifacts['chapter_number'] ?? $run->start_chapter).'章 · '.self::stepLabel($run->step),
                '授权范围第'.$run->start_chapter.'～'.$run->chapter_limit.'章。可以离开页面，回来时状态会自动刷新。', url: $url, running: true);
        }
        $setting = $novel->stages()->where('stage', Stage::Setting->value)->latest('id')->first();
        if ($setting === null || in_array($setting->status, [StageStatus::Pending, StageStatus::Running], true)) {
            return $this->result('setting', '正在生成设定', '模型正在根据你的创意生成设定候选，完成后会在这里提示你确认。', running: true);
        }
        if ($setting->status !== StageStatus::Completed) {
            return $this->result('setting', '设定生成失败', '可以在“运行与恢复”页面重跑设定，旧结果和费用会保留。', url: NovelResource::getUrl('run', ['record' => $novel]));
        }
        $plan = $novel->outlinePlans()->latest('id')->first();
        if ($plan === null) {
            return $novel->settingConfirmations()->exists()
                ? $this->result('outline', '可以生成大纲', '设定已确认。点击“生成大纲”，系统按每批10章生成全书章节规划。', action: 'generateOutline')
                : $this->result('setting', '等你确认设定', '审阅模型生成的世界观、人物和待确认问题，修改后保存为确认版本。', action: 'confirmSetting');
        }
        if (in_array($plan->status, ['pending', 'running'], true)) {
            return $this->result('outline', '正在生成大纲：已完成'.($plan->next_chapter - 1).'/'.$plan->target_chapters.'章', '大纲生成中，可以离开页面。', url: NovelResource::getUrl('outline', ['record' => $novel]), running: true);
        }
        if ($plan->status !== 'completed') {
            return $this->result('outline', '大纲已暂停：'.(ProductionFailure::detailLabel($plan->stop_reason) ?? $plan->statusLabel()), '点击“继续大纲”从断点继续，已完成的批次不会重跑。', action: 'continueOutline');
        }
        $pending = Chapter::where('novel_id', $novel->id)->where('number', '>', $novel->chapters_done)->orderBy('number')->first();
        if ($pending !== null) {
            return $this->chapterInProgress($novel, $pending);
        }
        if ($novel->chapters_done === 0) {
            return $this->result('production', '可以开始写作', '大纲已完成。点击“生成第1章”生成首章候选。', action: 'generateFirstChapter');
        }
        if ($novel->chapters_done < $novel->target_chapters) {
            return $this->result('production', '已正式提交'.$novel->chapters_done.'/'.$novel->target_chapters.'章，可以继续写', '点击“生成第'.($novel->chapters_done + 1).'章”逐章写，或点击“继续自动生成”授权后续章节。', action: 'extendProduction');
        }

        return $this->result('acceptance', '全书'.$novel->target_chapters.'章已正式提交', '可以在“成品验收”逐章评分，在“导出中心”导出正文。', url: NovelResource::getUrl('acceptance', ['record' => $novel]));
    }

    /** 尚未正式提交的章节：按正文运行与提交准备的状态给出下一步；正文工作台的一行结论复用此规则。
     * @return array{step: string, headline: string, detail: string, action: ?string, url: ?string, running: bool}
     */
    public function forChapter(Novel $novel, Chapter $chapter): array
    {
        $run = ProductionRun::coveringChapter($novel->id, $chapter->number, true);
        if ($run !== null && $run->status === 'failed') {
            $failure = ProductionFailure::describe($run->error, $run->step);

            return $this->result('production', '需要你处理：'.$failure['label'], $failure['advice'], url: NovelResource::getUrl('run', ['record' => $novel]));
        }

        return $this->chapterInProgress($novel, $chapter);
    }

    /** 按正文运行与提交准备的状态给出下一步。
     * @return array{step: string, headline: string, detail: string, action: ?string, url: ?string, running: bool}
     */
    private function chapterInProgress(Novel $novel, Chapter $chapter): array
    {
        $url = NovelResource::getUrl('content', ['record' => $novel, 'chapter' => $chapter->number]);
        $title = '第'.$chapter->number.'章';
        $body = ChapterGenerationRun::where('chapter_id', $chapter->id)->latest('id')->first();
        $submission = $chapter->current_candidate_version_id === null ? null : ChapterSubmissionRun::where('version_id', $chapter->current_candidate_version_id)->latest('id')->first();
        if ($submission !== null) {
            return match ($submission->status) {
                'pending', 'running' => $this->result('production', $title.'：正在评审与提取事件', '完成后会提示你批准提交。', url: $url, running: true),
                'awaiting_approval' => $this->result('production', $title.'：等你批准新实体并正式提交', '在正文工作台的“提交”区核对新实体后提交。', url: $url),
                'ready' => $this->result('production', $title.'：可以正式提交', '在正文工作台点击“正式提交当前章”。', url: $url),
                default => $this->result('production', $title.'：评审或提取未通过', (ProductionFailure::detailLabel($submission->stop_reason) ?? '').'。可在正文工作台查看评审意见并定点修订或重跑。', url: $url),
            };
        }
        if ($body === null || in_array($body->status, ['pending', 'running'], true)) {
            return $this->result('production', $title.'：正在生成正文', '正文、推进检查依次进行，完成后会提示你核对。', url: $url, running: true);
        }
        if ($body->stop_reason === 'awaiting_review') {
            return $this->result('production', $title.'：等你核对全文', '阅读候选正文，在正文工作台记录全文核对结论。', url: $url);
        }
        if ($body->stop_reason === 'b04_accepted') {
            return $this->result('production', $title.'：可以准备正式提交', '在正文工作台点击“准备正式提交”，系统会做一次评审和一次事件提取。', url: $url);
        }

        return $this->result('production', $title.'：正文生成停止', (ProductionFailure::detailLabel($body->stop_reason) ?? '').'。可在正文工作台查看原因并恢复。', url: $url);
    }

    /** 步骤条文字：已完成 ✓、当前 ●、未开始 ○，不只依靠颜色区分。 */
    public function stepBar(string $current): string
    {
        $passed = true;
        $parts = [];
        foreach (self::STEPS as $key => $label) {
            if ($key === $current) {
                $parts[] = '● '.$label;
                $passed = false;
            } else {
                $parts[] = ($passed ? '✓ ' : '○ ').$label;
            }
        }

        return implode('　→　', $parts);
    }

    /** 生产运行步骤的中文名称。 */
    public static function stepLabel(?string $step): string
    {
        return match ($step) {
            'setting' => '生成设定', 'decision' => '设定决策', 'outline' => '生成大纲', 'content' => '正文生成与检查',
            'submission' => '评审与事件提取', 'commit' => '正式提交', 'completed' => '已完成', default => $step ?? '无',
        };
    }

    /** 统一结果结构。
     * @return array{step: string, headline: string, detail: string, action: ?string, url: ?string, running: bool}
     */
    private function result(string $step, string $headline, string $detail, ?string $action = null, ?string $url = null, bool $running = false): array
    {
        return compact('step', 'headline', 'detail', 'action', 'url', 'running');
    }
}
