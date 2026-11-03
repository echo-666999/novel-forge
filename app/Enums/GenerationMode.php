<?php

namespace App\Enums;

/** 固定生成路径，旧书和默认新书继续使用模拟模式。 */
enum GenerationMode: string
{
    /** 全流程离线模拟。 */
    case Simulation = 'simulation';

    /** 先生成真实设定候选，大纲仅经作者确认后显式启动。 */
    case SettingReal = 'setting_real';

    /** 返回作者可理解的模式名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Simulation => '模拟流程（不调用模型）',
            self::SettingReal => '真实设定（调用模型）',
        };
    }
}
