<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentExecutionContract;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Feature\B04ContentTest;

/** 冻结场景状态的运行：细纲给出每场结束时的在场人物与物件持有，正文据此自检。 */
class SceneStateTest extends TestCase
{
    private const POLICY = ['min_characters' => 3000, 'max_characters' => 4000];

    /** 场景状态完整时通过，并原样保留；旧运行不要求场景状态。 */
    public function test_scene_state_is_required_only_for_frozen_runs(): void
    {
        $fine = B04ContentTest::fine();
        $this->assertSame($fine['scenes'][1]['props'], ContentExecutionContract::fine($fine, ['chapter-1-point-1'], self::POLICY, true)['scenes'][1]['props']);

        $legacy = $fine;
        foreach ($legacy['scenes'] as &$scene) {
            unset($scene['present'], $scene['props']);
        }
        unset($scene);
        $this->assertCount(3, ContentExecutionContract::fine($legacy, ['chapter-1-point-1'], self::POLICY)['scenes']);
        $this->expectExceptionMessage('细纲场景缺少present');
        ContentExecutionContract::fine($legacy, ['chapter-1-point-1'], self::POLICY, true);
    }

    /** 不完整或结构错误的场景状态一律拒绝。
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidStates(): array
    {
        return ['empty_present' => [['present' => []]], 'blank_name' => [['present' => [' ']]],
            'prop_missing_holder' => [['props' => [['item' => '来信']]]], 'prop_extra_key' => [['props' => [['item' => '来信', 'holder' => '林澈', 'note' => '多余']]]],
            'only_present' => [['props' => null]]];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('invalidStates')]
    public function test_malformed_scene_state_is_rejected(array $override): void
    {
        $fine = B04ContentTest::fine();
        $fine['scenes'][0] = [...$fine['scenes'][0], ...$override];
        if (array_key_exists('props', $override) && $override['props'] === null) {
            unset($fine['scenes'][0]['props']);
        }
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::fine($fine, ['chapter-1-point-1'], self::POLICY, true);
    }

    /** Schema与指令只对冻结场景状态的运行追加，正文首稿附带三项自检。 */
    public function test_schema_and_instructions_follow_the_run_flag(): void
    {
        $range = ContentExecutionContract::sceneRange(self::POLICY);
        $scene = fn (array $context): array => ContentExecutionContract::schema(new JsonSchemaTypeFactory, 'fine', ['scene_range' => $range, ...$context])['scenes']->toArray()['items'];
        $this->assertContains('present', $scene(['scene_state' => true])['required']);
        $this->assertContains('props', $scene(['scene_state' => true])['required']);
        $this->assertNotContains('present', $scene([])['required']);

        $this->assertStringContainsString('present列出场景结束时仍在场的人物', ContentExecutionContract::outputInstructions('fine', ['scene_range' => $range, 'scene_state' => true]));
        $this->assertStringNotContainsString('present列出', ContentExecutionContract::outputInstructions('fine', ['scene_range' => $range]));
        $budget = ['slots' => ['scene_1', 'scene_2'], 'min' => 1000, 'max' => 1200];
        $this->assertStringContainsString('写完每个场景后对照slot_plan自检三件事', ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'scene_state' => true]));
        $this->assertStringNotContainsString('自检三件事', ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]));
    }
}
