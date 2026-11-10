<?php

/** 文本供应商固定注册；密钥只从本机环境读取，嵌入检索继续使用原OpenAI路由。 */
return [
    // C04本地保守运行预算，不声明供应商模型窗口；新运行冻结后不随配置变化。
    'context' => ['capacity' => 262144],
    'default' => 'openai',
    'providers' => [
        // DeepSeek使用官方SDK驱动，实际地址由适配器白名单校验。
        'deepseek' => ['driver' => 'deepseek', 'key' => env('DEEPSEEK_API_KEY'), 'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1')],
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => false,
        ],
    ],
    // 模型配置：供应商、可选性与能力在新运行创建时冻结进运行配置，旧运行不受修改影响。
    // extended_output_tokens：正文、修订、复查可用的扩展输出容量（含推理），空值表示保持8192。
    // review_reasoning：独立评审的推理强度，空值表示不开启。slot_repair：正文字数失败后按段修复。
    'models' => [
        'deepseek-flash' => ['label' => 'DeepSeek Flash', 'provider' => 'deepseek', 'selectable' => true, 'extended_output_tokens' => 16384, 'review_reasoning' => 'low', 'slot_repair' => true],
        'deepseek-v4-pro' => ['label' => 'DeepSeek V4 Pro', 'provider' => 'deepseek', 'selectable' => true, 'extended_output_tokens' => 16384, 'review_reasoning' => 'low', 'slot_repair' => true],
        'gpt-6.1-terra' => ['label' => 'GPT-6.1 Terra', 'provider' => 'openai', 'selectable' => true],
        'gpt-6.1-sol' => ['label' => 'GPT-6.1 Sol', 'provider' => 'openai', 'selectable' => true],
        'gpt-4.1' => ['label' => 'GPT-4.1', 'provider' => 'openai', 'selectable' => true],
        'gpt-4.1-mini' => ['label' => 'GPT-4.1 Mini', 'provider' => 'openai', 'selectable' => true],
        // 历史验收曾返回 model_not_found，仅保留供旧记录识别，不再提供选择。
        'gpt-4' => ['label' => 'GPT-4（历史）', 'provider' => 'openai', 'selectable' => false],
    ],
    // 单价必须经过核实且按实际响应模型登记；缺失时金额保持未知，不硬编码旧价格。
    // 每项需包含 version、currency、input_per_million、output_per_million；缓存另列单价。
    'costs' => ['prices' => []],
];
