<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Pipeline\ForeshadowLedger;
use App\Services\Pipeline\ProductionRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** 与后台共用的首章及前十章自动生成入口，支持显式恢复及同步验收。 */
class RunFirstChapter extends Command
{
    /** 新建必须提供创意文件及明确授权；恢复复用原小说。 */
    protected $signature = 'app:run-first-chapter {--chapters=1 : 本次新建授权一章或前十章} {--input= : 创意JSON文件} {--author= : 授权用户编号} {--authorize-automatic-commit : 授权设定决策、实体采纳和指定范围正式提交} {--run= : 已有自动流程编号} {--retry : 恢复失败断点} {--confirm-unknown : 接受历史未知请求费用风险} {--model= : 新流程或正文/提交恢复使用的模型，不改全局设置} {--rewrite : 正文恢复时授权新版本整章重写，保留旧正文} {--sync : 当前进程顺序执行直到完成或失败} {--novel= : 续写的已有小说编号} {--extend= : 续写授权章数（1至10）} {--reason= : 续写授权原因} {--foreshadows= : 新建时在第1章生成前登记的伏笔契约JSON文件} {--budget= : 新建时的本书金额预算} {--currency=CNY : 预算币种}';

    /** Artisan中文说明。 */
    protected $description = '自动生成并正式提交新小说首章或前十章，或为已有小说续写后续章节；保留授权、断点及请求次数';

    /** CLI不补写故事，所有产物均通过应用服务与正式门禁生成。 */
    public function handle(ProductionRunService $service): int
    {
        $author = User::findOrFail((int) $this->option('author'));
        if ($this->option('extend') !== null) {
            if (! ctype_digit((string) $this->option('novel')) || ! ctype_digit((string) $this->option('extend')) || ! filled($this->option('reason'))) {
                $this->error('续写必须提供 --novel、--extend 章数及 --reason');

                return self::FAILURE;
            }
            $run = $service->extend((int) $this->option('novel'), $author, (int) $this->option('extend'), $this->option('model'), (string) $this->option('reason'), false);
        } elseif ($this->option('run')) {
            $run = ProductionRun::findOrFail((int) $this->option('run'));
            $run->assertDelegation($run->novel_id, $author->id);
            if ($this->option('retry')) {
                $run = $service->retry($run->id, $author, (bool) $this->option('confirm-unknown'), (bool) $this->option('rewrite'), $this->option('model'));
            }
        } else {
            $path = $this->option('input');
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                $this->error('新建自动流程必须提供可读的 --input 创意JSON文件');

                return self::FAILURE;
            }
            $input = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if ($this->option('budget') !== null) {
                $input = [...$input, 'budget_amount' => (string) $this->option('budget'), 'budget_currency' => (string) $this->option('currency')];
            }
            $plans = null;
            if ($this->option('foreshadows') !== null) {
                $file = (string) $this->option('foreshadows');
                if (! is_file($file) || ! is_readable($file)) {
                    $this->error('伏笔契约文件不可读');

                    return self::FAILURE;
                }
                $plans = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            }
            // 先建书不投递，登记完伏笔契约后再投递，保证契约在第1章生成前冻结。
            $run = $service->start($input, $author, (bool) $this->option('authorize-automatic-commit'), false, $this->option('model'), (int) $this->option('chapters'));
            foreach ($plans ?? [] as $plan) {
                $ledger = app(ForeshadowLedger::class)->plan(Novel::findOrFail($run->novel_id), $author, $plan['title'], $plan['description'], (int) $plan['plant_chapter'], (int) $plan['target_chapter'], (string) Str::uuid7(), $plan['contract'] ?? []);
                $this->line('已登记伏笔 #'.$ledger->id.' '.$ledger->title.'（第'.$ledger->plant_chapter.'章埋设，回收窗口第'.$ledger->payoff_from.'～'.$ledger->payoff_to.'章'.($ledger->must_payoff ? '，必回收' : '').'）');
            }
        }
        $this->line('自动流程 '.$run->id.' / 小说 '.$run->novel_id);
        if ($this->option('sync')) {
            while ($run->status === 'pending') {
                $run = $service->advance($run->id);
                $this->line('第'.($run->artifacts['chapter_number'] ?? 1).'章 / '.$run->step.' / '.$run->status.' / AI请求 '.$service->requestCount($run).($run->error ? ' / '.$run->error : ''));
            }
        } elseif ($run->status === 'pending') {
            $service->dispatch($run);
            $run->refresh();
        }

        return in_array($run->status, ['failed', 'running'], true) ? self::FAILURE : self::SUCCESS;
    }
}
