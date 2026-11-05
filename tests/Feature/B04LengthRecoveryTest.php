<?php

namespace Tests\Feature;

use App\Services\Pipeline\ContentSchema;
use Tests\TestCase;

/** 历史产物夹具与纯协议验证；新业务保护由B04ExecutionTest覆盖。 */
class B04LengthRecoveryTest extends TestCase
{
    /** 构造固定插入夹具，验证旧产物解析。 */
    private function insertion(int $characters, string $seed = '调查行动线索'): array
    {
        return ['insertions' => [['paragraph' => 3, 'anchor' => explode("\n", B04ContentTest::body())[2],
            'text' => mb_substr(str_repeat($seed, (int) ceil($characters / mb_strlen($seed))), 0, $characters), 'reason' => '隔离测试验证精确字符增量']]];
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public function test_duplicate_insertion_is_rejected(): void
    {
        $patch = $this->insertion(10);
        $patch['insertions'][] = $patch['insertions'][0];
        $this->expectException(\InvalidArgumentException::class);
        ContentSchema::expand($patch, B04ContentTest::body());
    }
}
