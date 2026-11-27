<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 内置题材包补上"开篇必备交代"并升一版；内容里已有该项的不覆盖。已建书冻结的副本不受影响。 */
return new class extends Migration
{
    /** 各内置题材包的开篇必备交代项。 */
    private const CHECKLISTS = [
        'era' => ['主角的出身、家境和眼下的日子', '这个年代的具体样貌（物价、票证、工作单位、街坊）', '主角想改变什么、靠什么改变'],
        'rebirth' => ['前世怎么走到结局', '前世最大的遗憾和吃过的亏', '重生回到哪个时间节点、眼前马上要发生什么', '带回来的经验、手艺或先知', '这一世要做什么、不做什么'],
        'transmigration' => ['穿越前是谁、过着什么日子', '怎么穿来的', '原身是谁、留下什么烂摊子', '主角手里的优势（金手指或现代知识）及其局限', '穿来后的第一个危机', '现代观念与当地观念的第一个冲突', '主角想要什么'],
        'history' => ['主角的身份、官职或出身，以及眼下的处境', '所处的朝代、年号和时代大势', '主角凭什么入局（见识、本事或机缘）'],
        'alt_history' => ['主角的身份和眼下的处境', '历史在哪里开始不同', '主角想改变什么、凭什么改变'],
        'xuanhuan' => ['主角的出身和眼下的处境', '这个世界的力量体系（只交代当下用得着的部分）', '主角的金手指或机缘', '主角最想要什么、最大的阻碍是谁'],
    ];

    public function up(): void
    {
        foreach (self::CHECKLISTS as $key => $checklist) {
            $row = DB::table('story_presets')->where('kind', 'genre')->where('key', $key)->where('is_builtin', true)->first();
            if ($row === null) {
                continue;
            }
            $content = json_decode($row->content, true);
            if (! empty($content['opening_checklist'])) {
                continue;
            }
            $content['opening_checklist'] = $checklist;
            DB::table('story_presets')->where('id', $row->id)->update(['content' => json_encode($content, JSON_UNESCAPED_UNICODE), 'version' => $row->version + 1, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::CHECKLISTS) as $key) {
            $row = DB::table('story_presets')->where('kind', 'genre')->where('key', $key)->where('is_builtin', true)->first();
            if ($row === null) {
                continue;
            }
            $content = json_decode($row->content, true);
            if (($content['opening_checklist'] ?? null) !== self::CHECKLISTS[$key]) {
                continue;
            }
            unset($content['opening_checklist']);
            DB::table('story_presets')->where('id', $row->id)->update(['content' => json_encode($content, JSON_UNESCAPED_UNICODE), 'version' => max(1, $row->version - 1)]);
        }
    }
};
