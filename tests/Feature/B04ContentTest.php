<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\NovelOutlinePlan;
use App\Models\NovelStage;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProgressionChecker;
use Database\Factories\NovelOutlinePlanFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 历史产物夹具与纯协议验证；新业务保护由B04ExecutionTest覆盖。 */
class B04ContentTest extends TestCase
{
    /** 隔离库协议夹具只控制结构，不代表正文文学性通过。
     * @return array<string, mixed>
     */
    public static function validResponse(Request $request, bool $met = true): array
    {
        $payload = json_decode(explode('冻结输入：', $request['input'][1]['content'][0]['text'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
        $step = CostLog::latest('id')->firstOrFail()->request_parameters['step'];
        if ($step === 'fine') {
            $data = self::fine();
        } elseif ($step === 'draft') {
            $data = ['title' => '隔离首章', 'paragraphs' => array_combine($payload['budget']['slots'], array_map(fn ($i): string => str_repeat(mb_chr(0x4E00 + $i), intdiv($payload['budget']['min'] + $payload['budget']['max'], 2)), range(0, $payload['budget']['count'] - 1)))];
        } elseif ($step === 'revise') {
            $id = array_key_first($payload['available_anchors']);
            $data = ['ranges' => [['start_id' => $id, 'end_id' => $id, 'text' => $payload['available_anchors'][$id]['text'].'隔离修订。', 'reason' => '隔离修订', 'addresses' => array_keys($payload['findings'])]]];
        } else {
            $id = array_key_first($payload['evidence_catalog']);
            $data = ['points' => array_map(fn ($point): array => ['id' => $point['id'], 'met' => $met, 'reason' => '隔离推进', 'evidence_ids' => [$id]], $payload['progression_points']),
                'hook' => ['met' => $met, 'reason' => '隔离钩子', 'evidence_ids' => [$id]], 'setting_violations' => [], 'continuity_issues' => [], 'story_tensions' => [],
                'finding_reviews' => array_map(fn ($finding): array => ['id' => $finding, 'disposition' => $met ? 'resolved' : 'unresolved', 'reason' => '隔离复核', 'evidence_ids' => [$id]], array_keys($payload['findings']))];
        }
        $response = B02SettingTest::response(PlanningJson::encode($data));
        $response['model'] = $request['model'].'-2025-04-14';

        return $response;
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function clearFixtures(): void
    {
        if (Schema::hasTable('chapter_submission_runs')) {
            DB::table('chapters')->update(['formal_version_id' => null, 'canonical_commit_id' => null, 'committed_at' => null]);
            foreach (['story_outbox_events', 'story_state_projections', 'story_events', 'canonical_commits', 'chapter_submission_validations', 'chapter_event_extractions', 'chapter_reviews', 'chapter_submission_runs', 'story_entities'] as $table) {
                DB::table($table)->delete();
            }
        }
        DB::table('chapters')->update(['current_candidate_version_id' => null, 'progression_result' => null]);
        DB::table('chapter_generation_runs')->update(['source_version_id' => null]);
        DB::table('chapter_progression_checks')->delete();
        foreach (DB::table('chapter_versions')->orderByDesc('id')->pluck('id') as $id) {
            DB::table('chapter_versions')->where('id', $id)->delete();
        }
        foreach (DB::table('chapter_generation_runs')->orderByDesc('id')->pluck('id') as $runId) {
            DB::table('chapter_generation_runs')->where('id', $runId)->delete();
        }
        DB::table('chapters')->delete();
        foreach (['novel_outline_chapters', 'novel_outline_plans', 'novel_setting_confirmations'] as $table) {
            DB::table($table)->delete();
        }
        DB::table('novels')->update(['generation_mode' => 'simulation']);
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function readyNovel(bool $fine = true, int $limit = 20): Novel
    {
        $config = (new NovelOutlinePlanFactory)->definition()['config'];
        $config['enable_fine_outline'] = $fine;
        $batch = B03OutlineTest::batch();
        $plan = NovelOutlinePlan::factory()->create(['status' => 'completed', 'next_chapter' => 11, 'config' => $config, 'roadmap' => $batch['roadmap']]);
        foreach ($batch['chapters'] as &$chapter) {
            $chapter['progression_points'][0]['id'] = 'chapter-'.$chapter['chapter_number'].'-point-1';
        }
        unset($chapter);
        $stage = NovelStage::factory()->create(['novel_id' => $plan->novel_id, 'stage' => Stage::Outline, 'status' => StageStatus::Completed, 'input' => ['outline_plan_id' => $plan->id], 'output' => ['data' => $batch]]);
        foreach ($batch['chapters'] as $chapter) {
            NovelOutlineChapter::query()->create(['outline_plan_id' => $plan->id, 'source_stage_id' => $stage->id, ...$chapter]);
        }
        $novel = $plan->novel;
        $novel->update(['current_stage' => $fine ? Stage::FineOutline : Stage::Content, 'execution_count' => 2, 'max_execution_count' => $limit,
            'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]]);

        return $novel;
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function fine(): array
    {
        return ['scenes' => [
            ['time' => '清晨', 'place' => '灯塔', 'action' => '读取来信并记录可验证信息', 'change' => '取得待查线索', 'point_ids' => ['chapter-1-point-1']],
            ['time' => '午后', 'place' => '档案室', 'action' => '逐项比对航线记录', 'change' => '辨认篡改痕迹', 'point_ids' => ['chapter-1-point-1']],
            ['time' => '傍晚', 'place' => '港口', 'action' => '凭证据追查签名', 'change' => '明确后续调查地点', 'point_ids' => []],
        ], 'hook' => '签名指向下一处调查地点', 'adjustments' => ['把验证安排在本章完成后才转入新线索'], 'hook_transition' => ['completed_state' => '已确认航线篡改', 'trigger' => '发现签名指向仓库', 'next_question' => '仓库与旧案有关联吗', 'point_ids' => ['chapter-1-point-1']]];
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function body(bool $good = true): string
    {
        return $good ? "林澈走进档案室，摊开原始航海图，一行一行核对来信的坐标。\n她发现原档被覆盖的红线，确认航线遭人篡改，拍下证据保存。\n她循着签名找到了港口仓库，决定明早向管理员查问。" : "林澈坐在灯塔里，反复想起师父。\n她望着海雾等待，仍然没有离开灯塔，也没有验证线索。";
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function check(bool $met = true): array
    {
        return ['points' => [['id' => 'chapter-1-point-1', 'met' => $met, 'reason' => $met ? '比对原档产生可验证新证据' : '只有回想和等待，没有完成比对与变化', 'evidence' => $met ? [['paragraph' => 2, 'quote' => '确认航线遭人篡改，拍下证据保存']] : []]],
            'hook' => ['met' => $met, 'reason' => $met ? '签名指向新地点，形成下一步行动' : '未形成新的调查方向', 'evidence' => $met ? [['paragraph' => 3, 'quote' => '循着签名找到了港口仓库']] : []], 'conflicts' => []];
    }

    /** 基础计数只计算正文可见非空白字符。 */
    public function test_plain_character_count(): void
    {
        $this->assertSame(2, ContentSchema::count("甲\n乙"));
    }

    /** 旧格式证据及范围保护保留为纯契约回归。 */
    public function test_revision_rejects_unknown_anchor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentSchema::revise(['edits' => [['paragraph' => 1, 'before' => '正文中不存在的原句', 'after' => '替换内容', 'reason' => '修补推进']]], self::body());
    }

    /** 旧格式证据及范围保护保留为纯契约回归。 */
    #[DataProvider('invalidChecks')]
    public function test_invalid_evidence_cannot_pass(string $kind): void
    {
        $check = self::check();
        match ($kind) {
            'quote' => $check['points'][0]['evidence'][0]['quote'] = '这句正文从未出现',
            'missing' => $check['points'] = [],
            'duplicate' => $check['points'][] = $check['points'][0],
            'paragraph' => $check['points'][0]['evidence'][0]['paragraph'] = 1,
            'unsupported' => $check['points'][0]['id'] = 'chapter-2-point-1',
        };
        $this->expectException(\InvalidArgumentException::class);
        app(ProgressionChecker::class)->validate($check, self::body(), ['chapter-1-point-1']);
    }

    /** 旧格式证据及范围保护保留为纯契约回归。 */
    public static function invalidChecks(): array
    {
        return array_combine(['quote', 'missing', 'duplicate', 'paragraph', 'unsupported'], array_map(fn ($value) => [$value], ['quote', 'missing', 'duplicate', 'paragraph', 'unsupported']));
    }

    /** 旧格式证据及范围保护保留为纯契约回归。 */
    public function test_core_conflict_blocks_even_when_points_are_met(): void
    {
        $check = self::check();
        $check['conflicts'][] = ['reason' => '测试构造的核心规则冲突', 'evidence' => [['paragraph' => 1, 'quote' => '林澈走进档案室']]];
        $this->assertSame('blocked', app(ProgressionChecker::class)->validate($check, self::body(), ['chapter-1-point-1'])['verdict']);
    }

    /** 旧格式证据及范围保护保留为纯契约回归。 */
    public function test_unicode_evidence_and_character_count(): void
    {
        $result = app(ProgressionChecker::class)->validate(self::check(), self::body(), ['chapter-1-point-1']);
        $quote = $result['points'][0]['evidence'][0];
        $this->assertSame($quote['quote'], mb_substr(self::body(), $quote['start'], $quote['end'] - $quote['start']));
        $this->assertSame(4, ContentSchema::count("# 中\n文 **字**\u{200B}数"));
    }
}
