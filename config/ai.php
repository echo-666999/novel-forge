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
    // 单价必须经过核实且按实际响应模型登记；缺失时金额保持未知，不硬编码旧价格。
    // 每项需包含 version、currency、input_per_million、output_per_million；缓存另列单价。
    'costs' => ['prices' => []],
];
