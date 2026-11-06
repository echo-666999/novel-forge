<?php

namespace App\Console\Commands;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\NovelPipelineService;
use App\Services\Pipeline\StageExecutorInterface;
use App\Services\Pipeline\StageExecutorRegistry;
use App\Services\Pipeline\StageResult;
use App\Settings\GeneralSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 新建独立合成小说验证 A05；每次调用保留结果，不接管或删除既有小说。 */
#[Signature('app:verify-novel-pipeline {--max-execution-count= : 本次合成小说的执行上限} {--disable-fine-outline : 本次空跑关闭细纲} {--json : 输出完整 JSON 结果} {--fail-stage= : 仅在本次新建模拟小说中制造指定阶段失败}')]
#[Description('使用固定假数据验证阶段流转；完成退出0，额度暂停退出2，失败退出1')]
class VerifyNovelPipeline extends Command
{
    /** 创建一条有明确标识的模拟小说，输出落库记录及停止原因。 */
    public function handle(NovelPipelineService $pipeline): int
    {
        $novel = null;
        try {
            $raw = $this->option('max-execution-count');
            $limit = $raw === null ? app(GeneralSettings::class)->max_execution_count : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if (! is_int($limit) || $limit < 1) {
                throw new InvalidArgumentException('执行上限必须为1至2147483647的整数');
            }
            // 只为新建的合成小说注入失败，不提供修改已有小说的入口。
            $failure = $this->option('fail-stage');
            if ($failure !== null) {
                $stage = Stage::tryFrom($failure);
                if ($stage === null) {
                    throw new InvalidArgumentException('失败阶段必须是已定义的阶段编号');
                }
                $pipeline = new NovelPipelineService(new StageExecutorRegistry(new class($stage) implements StageExecutorInterface
                {
                    /** 本次合成验收唯一需要失败的阶段。 */
                    public function __construct(private Stage $failure) {}

                    /** 其他阶段仍为固定假数据，绝不访问供应商。 */
                    public function execute(Novel $novel, NovelStage $stage): StageResult
                    {
                        return $stage->stage === $this->failure
                            ? StageResult::failed('B08受控阶段失败：'.$stage->stage->label(), ['partial' => '保留的模拟局部产物'])
                            : (new FakeStageExecutor)->execute($novel, $stage);
                    }
                }));
            }
            $novel = Novel::query()->create(['title' => '[A05验收] '.Str::uuid7(), 'genre' => '系统验收', 'premise_raw' => '固定假数据状态机验收，不代表小说作品。', 'target_chapters' => 1, 'max_execution_count' => $limit]);
            $result = $pipeline->run($novel, $this->option('disable-fine-outline') ? false : null);
            $report = [...$result->toArray(), 'stages' => $novel->stages()->orderBy('id')->get()->map(static fn (NovelStage $stage): array => $stage->toExecutionRecord())->all()];
            $code = match (true) {
                $result->status === NovelStatus::Completed => 0,
                $result->status === NovelStatus::Paused && $result->reason === 'execution_limit' => 2,
                default => 1,
            };
        } catch (InvalidArgumentException $exception) {
            $report = ['simulation' => true, 'novel_id' => $novel?->id, 'error' => $exception->getMessage()];
            $code = 1;
        } catch (Throwable $exception) {
            $report = ['simulation' => true, 'novel_id' => $novel?->id, 'error' => '模拟验收未完成，请检查本地数据库与阶段状态。', 'error_category' => class_basename($exception)];
            $code = 1;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | ($this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return $code;
    }
}
