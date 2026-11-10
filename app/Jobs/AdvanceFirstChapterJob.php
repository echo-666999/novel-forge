<?php

namespace App\Jobs;

/**
 * 已改名为 AdvanceProductionRunJob；保留旧类名只为让升级前已入队的消息仍能反序列化执行。
 *
 * @deprecated 新代码请使用 AdvanceProductionRunJob。
 */
class AdvanceFirstChapterJob extends AdvanceProductionRunJob {}
