<?php

namespace App\Enums;

/** 固定生成路径，旧书和默认新书继续使用模拟模式。 */
enum GenerationMode: string
{
    /** 全流程离线模拟。 */
    case Simulation = 'simulation';

    /** 只生成真实设定候选，完成后等待大纲实现。 */
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
