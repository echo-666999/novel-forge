<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** 旧数据写入时的数据库会话时区。 */
    private const LEGACY_SESSION_TIMEZONE = 'UTC';

    /** 此前数据库会话为UTC，应用写入的北京时间墙钟被当成UTC保存，所有带时区时间列都晚了8小时。
     * 连接改为按应用时区换算后，把已有值按“墙钟时间属于应用时区”重新解释为正确时刻。
     * 只处理已有账号的旧库；全新安装此前的迁移已按新会话时区写入，无需换算。
     */
    public function up(): void
    {
        $this->shift(self::LEGACY_SESSION_TIMEZONE, (string) config('app.timezone'));
    }

    /** 回滚时换算回旧的存储方式。 */
    public function down(): void
    {
        $this->shift((string) config('app.timezone'), self::LEGACY_SESSION_TIMEZONE);
    }

    /** 对当前库所有带时区时间列执行：旧值按 $from 取墙钟时间，再按 $to 解释为时刻。 */
    private function shift(string $from, string $to): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || $from === $to || ! DB::table('users')->exists()) {
            return;
        }
        $columns = DB::select("select table_name, column_name from information_schema.columns where table_schema = current_schema() and data_type = 'timestamp with time zone' order by table_name, column_name");
        foreach (collect($columns)->groupBy('table_name') as $table => $rows) {
            $sets = $rows->map(fn (object $row): string => '"'.$row->column_name.'" = ("'.$row->column_name.'" at time zone ?) at time zone ?')->implode(', ');
            $bindings = $rows->flatMap(fn (): array => [$from, $to])->all();
            DB::update('update "'.$table.'" set '.$sets, $bindings);
        }
    }
};
