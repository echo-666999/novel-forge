<?php

/** A03 只启用已批准的 OpenAI 文本路由，其他 SDK 配置沿用包内默认值。 */
return [
    'default' => 'openai',
    'providers' => [
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
