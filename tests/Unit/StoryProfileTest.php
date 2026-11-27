<?php

namespace Tests\Unit;

use App\Services\Pipeline\StoryProfile;
use PHPUnit\Framework\TestCase;

/** 题材与文风的整理、渲染和正文要求。 */
class StoryProfileTest extends TestCase
{
    /** 整理只保留已知字段，去空白和空项，权重夹在0至100，未知幽默程度归为无。 */
    public function test_normalize_drops_unknown_fields_and_clamps(): void
    {
        $genre = StoryProfile::normalize('genre', ['core_promise' => ' 越过越好 ', 'payoff_types' => [['name' => '打脸', 'weight' => 150], ['name' => ' ', 'weight' => 5]], 'taboos' => ['圣母', ''], 'extra' => 'x']);
        $this->assertSame('越过越好', $genre['core_promise']);
        $this->assertSame([['name' => '打脸', 'weight' => 100]], $genre['payoff_types']);
        $this->assertSame(['圣母'], $genre['taboos']);
        $this->assertArrayNotHasKey('extra', $genre);
        $this->assertSame('none', StoryProfile::normalize('style', ['humor_level' => 'max'])['humor_level']);
    }

    /** 写法要求按字段渲染；作者手写的要求优先。 */
    public function test_fragment_renders_fields_unless_overridden(): void
    {
        $style = StoryProfile::normalize('style', ['narrator_attitude' => '吐槽', 'humor_level' => 'high', 'humor_devices' => ['反差'], 'banned_words' => ['空气凝固']]);
        $this->assertSame('叙述口吻：吐槽；幽默程度高，手法以反差为主；不用这些词：空气凝固。', StoryProfile::fragment($style));
        $this->assertSame('只写短句。', StoryProfile::fragment([...$style, 'draft_fragment' => '只写短句。']));
    }

    /** 正文要求带文风名称、示范说明、读者承诺与禁忌；正文资料只带示范段落和题材要点。 */
    public function test_instructions_and_payload(): void
    {
        $profile = ['version' => StoryProfile::VERSION,
            'genre' => ['key' => 'era', 'name' => '年代', 'version' => 1, 'content' => StoryProfile::normalize('genre', ['core_promise' => '日子越过越红火。', 'taboos' => ['受辱不反击']])],
            'style' => ['key' => 'x', 'name' => '泼辣', 'version' => 1, 'content' => StoryProfile::normalize('style', ['narrator_attitude' => '大姐讲古', 'samples' => ['示范一。']])]];
        $text = StoryProfile::instructions($profile);
        $this->assertStringContainsString('本书文风「泼辣」：叙述口吻：大姐讲古。', $text);
        $this->assertStringContainsString('不照搬其中的人物、情节和句子', $text);
        $this->assertStringContainsString('给读者的承诺是日子越过越红火。', $text);
        $this->assertStringContainsString('题材禁忌：受辱不反击。', $text);
        $this->assertSame(['name' => '泼辣', 'samples' => ['示范一。']], StoryProfile::bodyPayload($profile)['style']);
        $this->assertSame('', StoryProfile::instructions(['version' => StoryProfile::VERSION]));
    }
}
