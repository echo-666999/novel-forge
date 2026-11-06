<?php

namespace Tests\Feature;

use App\Models\ChapterEventExtraction;
use App\Models\ChapterReview;
use App\Models\CostLog;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\SubmissionContract;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** B07通过真实服务入口验证结构门禁与评审溯源，供应商HTTP仅使用隔离夹具。 */
class B07ReviewTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移项目表和设置，避免测试加载供应商扩展迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 复用现有真实SDK路径的HTTP夹具，禁止意外外网请求。 */
    protected function setUp(): void
    {
        parent::setUp();
        ChapterSubmissionTest::fakeProvider();
    }

    /** 清理仅限已核实的内存测试库，保持外键检查开启。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 正常候选通过独立评审；回执绑定正文、原始响应、完整提示与费用记录。 */
    public function test_valid_body_has_traceable_immutable_review(): void
    {
        [$version, $operator] = ChapterSubmissionTest::candidate();
        $service = app(ChapterSubmissionService::class);
        $run = $service->prepare($version->id, $operator, 0, 'B07正常候选验收', dispatch: false);
        $service->execute($run->id, $run->invocation_id, false);
        $review = ChapterReview::sole();
        $log = CostLog::findOrFail($review->cost_log_id);
        $this->assertSame('pass', $review->verdict);
        $this->assertSame($version->body_hash, $review->body_hash);
        $this->assertSame($run->id, $log->request_parameters['submission_run_id']);
        $this->assertSame('review', $log->request_parameters['purpose']);
        $this->assertSame($review->result_hash, PlanningJson::hash(SubmissionContract::review(ContentSchema::decode($log->response_text), $version->body)));
        $audit = $log->request_parameters['prompt_audit'];
        $this->assertSame(hash('sha256', $audit['system_prompt']), $audit['system_hash']);
        $this->assertSame(hash('sha256', $audit['user_prompt']), $audit['user_hash']);
        $this->assertSame(PlanningJson::hash($audit['response_schema']), $audit['schema_hash']);
        foreach ($review->result['evidence'] as $evidence) {
            $this->assertSame($evidence['quote'], mb_substr($version->body, $evidence['start'], $evidence['end'] - $evidence['start']));
        }
        $this->assertSame(1, $run->fresh()->requests_used);
        $this->assertSame('extract', $run->fresh()->step);
        $this->assertSame(0, ChapterEventExtraction::count());
        Http::assertSentCount(4);
        $this->expectException(LogicException::class);
        $review->update(['verdict' => 'reject']);
    }

    /** 异常正文即使携带先前通过标志，仍在付费评审前被B07结构门禁阻塞。 */
    #[DataProvider('invalidBodies')]
    public function test_invalid_body_stops_before_review_request(string $body, string $issue): void
    {
        [$version, $operator] = ChapterSubmissionTest::candidate();
        // 仅在隔离库同步异常输入的摘要，确保命中结构门禁而非提前命中来源失效检查。
        $hash = hash('sha256', $body);
        DB::table('chapter_versions')->where('id', $version->id)->update(['body' => $body, 'body_hash' => $hash, 'character_count' => ContentSchema::count($body)]);
        $progression = $version->chapter->progression_result;
        $progression['body_hash'] = $hash;
        DB::table('chapter_progression_checks')->where('id', $progression['check_id'])->update(['body_hash' => $hash]);
        DB::table('chapters')->where('id', $version->chapter_id)->update(['progression_result' => PlanningJson::encode($progression)]);
        $service = app(ChapterSubmissionService::class);
        $run = $service->prepare($version->id, $operator, 0, 'B07异常结构验收', dispatch: false);
        $beforeCount = $run->novel->execution_count;
        $service->execute($run->id, $run->invocation_id, false);
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringStartsWith('STRUCTURE_BLOCKED:', $run->stop_reason);
        $this->assertContains($issue, explode(',', substr($run->stop_reason, strlen('STRUCTURE_BLOCKED:'))));
        $this->assertSame(0, $run->requests_used);
        $this->assertSame($beforeCount, $run->novel->fresh()->execution_count);
        $this->assertSame(0, ChapterReview::count());
        $this->assertSame(0, CostLog::whereNotNull('request_parameters->submission_run_id')->count());
        Http::assertSentCount(3);
    }

    /** 覆盖占位、截断、仅闭引号、长度上下界，不把测试正文用于真实文学验收。
     * @return array<string, array{string, string}>
     */
    public static function invalidBodies(): array
    {
        $prefix = str_repeat('灯光映着海面。', 5);

        return ['占位符' => [$prefix.'正文待补充。', 'placeholder'], '截断' => [$prefix.'她的话说到这里', 'possible_truncation'],
            '闭引号掩盖截断' => [$prefix.'她的话说到这里”', 'possible_truncation'], '过短' => ['灯灭了。', 'length'],
            '过长' => [str_repeat('灯', 301).'。', 'length']];
    }

    /** 正常的句末中文或英文引号不应被误判为截断。 */
    public function test_terminal_punctuation_with_closing_quotes_is_allowed(): void
    {
        foreach (['她说：“别动！”', '她说："别动！"', '她说：「别动！」', '她停下脚步。'] as $body) {
            $this->assertSame([], SubmissionContract::structuralIssues($body, ['min_characters' => 1, 'max_characters' => 100]));
        }
    }

    /** 供应商声明的最大问题数必须在本地复验，不能完全信任远端Schema。 */
    public function test_review_rejects_excessive_findings(): void
    {
        $finding = ['kind' => 'literary', 'severity' => 'advisory', 'reason' => '重复问题', 'evidence_ids' => ['p1']];
        $this->expectException(InvalidArgumentException::class);
        SubmissionContract::review(['review' => ['verdict' => 'pass', 'summary' => '超出契约数量', 'evidence_ids' => ['p1'], 'findings' => array_fill(0, 21, $finding)]], '她停下脚步。');
    }
}
