<?php

namespace Database\Seeders;

use App\Models\NovelSettingConfirmation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** 仅供隔离测试预置大纲界面夹具，未注册到默认业务播种器。 */
class B03PlanningSeeder extends Seeder
{
    /** 非测试环境明确拒绝，避免将合成确认记录放进实际小说。 */
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('B03 合成规划播种器仅允许在隔离测试环境执行');
        }
        $connection = DB::connection();
        $isolated = $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        if ($connection->getDriverName() === 'pgsql') {
            $identity = DB::selectOne("select current_database() as name, shobj_description(oid,'pg_database') as purpose from pg_database where datname=current_database()");
            $isolated = $identity->name === 'novel_forge_app_b03_test' && $identity->purpose === 'novel-forge B03 isolated tests';
        }
        if (! $isolated) {
            throw new LogicException('B03 合成数据只能写入已核对的专用测试库');
        }
        NovelSettingConfirmation::factory()->create();
    }
}
