<?php

namespace Tests\Unit;

use App\Services\Pipeline\SubmissionPresenter;
use PHPUnit\Framework\TestCase;

/** 评审与提取结果整理为可读分区：实体引用换成名称、字段码换成中文、阻塞问题在前。 */
class SubmissionPresenterTest extends TestCase
{
    /** 评审、事件、状态变化、伏笔、新实体、验证与回执都转成可读内容，不含原始键名。 */
    public function test_present_resolves_references_and_labels(): void
    {
        $quote = ['paragraph' => 2, 'quote' => '她把钥匙放进物证袋。', 'start' => 0, 'end' => 10];
        $review = ['verdict' => 'needs_revision', 'summary' => '结构完整。', 'findings' => [
            ['kind' => 'literary', 'severity' => 'advisory', 'reason' => '措辞略强。', 'evidence' => [$quote]],
            ['kind' => 'continuity', 'severity' => 'blocking', 'reason' => '钥匙位置前后矛盾。', 'evidence' => [$quote]],
        ]];
        $extraction = [
            'events' => [
                ['key' => 'event-2', 'narrative_order' => 2, 'summary' => '第二件事', 'event_type' => 'location_change', 'fact_category' => 'objective_confirmed', 'time_scope' => 'present', 'actor_ref' => 'entity-7', 'target_refs' => ['new-1'], 'evidence' => [$quote]],
                ['key' => 'event-1', 'narrative_order' => 1, 'summary' => '第一件事', 'event_type' => 'statement', 'fact_category' => 'rumor', 'time_scope' => 'memory', 'actor_ref' => 'entity-7', 'target_refs' => [], 'evidence' => []],
            ],
            'patch' => [['property' => 'location', 'entity_ref' => 'entity-7', 'before_ref' => 'entity-8', 'value_ref' => 'new-1', 'value_text' => '', 'fact_category' => 'objective_confirmed']],
            'foreshadows' => [['foreshadow_id' => 10, 'action' => 'payoff', 'explanation' => '钥匙对上原始封记。', 'evidence' => [$quote]]],
            'proposed_entities' => [['key' => 'new-1', 'core' => false, 'name' => '档案馆门厅', 'type' => 'location', 'aliases' => ['门厅'], 'description' => '大门外的门厅。', 'evidence' => [$quote]]],
        ];
        $validation = ['verdict' => 'pass', 'errors' => [], 'approval_required' => ['new-1']];
        $commit = ['origin' => 'submission', 'approval_mode' => 'delegated_policy', 'event_count' => 2, 'chapters_done' => 3, 'state_version' => 3, 'previous_state_version' => 2, 'version_id' => 55, 'body_hash' => 'abc'];

        $view = SubmissionPresenter::present($review, $extraction, $validation, $commit, [7 => '沈禾', 8 => '修复室'], [10 => '铜钥匙']);

        $this->assertFalse($view['review']['passed']);
        $this->assertSame('需要修订', $view['review']['verdict']);
        $this->assertTrue($view['review']['findings'][0]['blocking']);
        $this->assertSame('连贯性', $view['review']['findings'][0]['kind']);
        $this->assertSame([['paragraph' => 2, 'quote' => '她把钥匙放进物证袋。']], $view['review']['findings'][0]['quotes']);

        $events = $view['extraction']['events'];
        $this->assertSame(['第一件事', '第二件事'], array_column($events, 'summary'));
        $this->assertSame(['陈述', '传闻', '回忆', '沈禾'], [$events[0]['type'], $events[0]['category'], $events[0]['time'], $events[0]['actor']]);
        $this->assertSame(['档案馆门厅（新实体）'], $events[1]['targets']);
        $this->assertSame(['entity' => '沈禾', 'property' => '位置', 'before' => '修复室', 'after' => '档案馆门厅（新实体）', 'category' => '客观确认'], $view['extraction']['changes'][0]);
        $this->assertSame(['铜钥匙', '回收'], [$view['extraction']['foreshadows'][0]['title'], $view['extraction']['foreshadows'][0]['action']]);
        $this->assertSame('档案馆门厅（地点，新实体）：大门外的门厅。；别名：门厅', $view['extraction']['entities'][0]['label']);

        $this->assertTrue($view['validation']['passed']);
        $this->assertSame(['档案馆门厅（新实体）'], $view['validation']['approvals']);
        $commitRows = array_column($view['commit'], 'value', 'label');
        $this->assertSame('自动授权策略（非作者逐章批准）', $commitRows['审批方式']);
        $this->assertSame('2 → 3', $commitRows['故事状态版本']);

        $empty = SubmissionPresenter::present(null, null, null, null, [], []);
        $this->assertSame(['review' => null, 'extraction' => null, 'validation' => null, 'commit' => null], $empty);
    }
}
