<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Models\NovelStage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/** 只读查询指定小说的阶段尝试，不触发执行、恢复、重试或历史回填。 */
#[Signature('app:show-novel-stages {novel : 小说ID} {--stage-id= : 仅显示该小说的一次阶段尝试} {--json : 美化输出完整JSON}')]
#[Description('读取阶段输入输出、费用与耗时；查询成功退出0，参数或记录错误退出1')]
class ShowNovelStages extends Command
{
    /** 完整输出持久化快照，空历史返回空列表，故障不泄露异常消息或凭据。 */
    public function handle(): int
    {
        try {
            $novelId = $this->positiveId($this->argument('novel'));
            $rawStageId = $this->option('stage-id');
            // 显式传入空选项也必须失败，不能意外退化为查询全部阶段。
            if ($rawStageId === null && $this->input->hasParameterOption('--stage-id', true)) {
                throw new InvalidArgumentException('阶段ID不能为空');
            }
            $stageId = $rawStageId === null ? null : $this->positiveId($rawStageId);
            $novel = Novel::query()->findOrFail($novelId);
            $query = $novel->stages()->orderBy('id');
            if ($stageId !== null) {
                // 在所属小说范围内查找，禁止误读其他小说的阶段。
                $query->whereKey($stageId)->firstOrFail();
            }
            $records = $query->get()->map(static fn (NovelStage $stage): array => $stage->toExecutionRecord())->all();
            $report = ['novel_id' => $novelId, 'count' => count($records), 'stages' => $records];
            $code = self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $report = ['error' => $exception->getMessage()];
            $code = self::FAILURE;
        } catch (ModelNotFoundException) {
            $report = ['error' => '小说或所属阶段记录不存在'];
            $code = self::FAILURE;
        } catch (Throwable) {
            $report = ['error' => '阶段记录读取失败，请检查数据库连接与记录格式。'];
            $code = self::FAILURE;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | ($this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return $code;
    }

    /** 严格校验正整数标识，防止无效参数退化成全量查询。 */
    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('小说ID和阶段ID必须为正整数');
        }

        return $id;
    }
}
