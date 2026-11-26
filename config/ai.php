<?php

/** 文本供应商固定注册；密钥与地址以后台“模型供应商”设置为准，此处环境变量仅作后备；嵌入检索继续使用原OpenAI路由。 */
return [
    // C04本地保守运行预算，新运行冻结后不随配置变化。
    // capacity 是旧估算（按UTF-8字节计）的上限，仅用于重建已冻结旧估算的运行。
    // token_capacity 是新估算（字节÷2.5）的上限：DeepSeek flash/v4-pro 官方窗口为1M，取40%留出估算误差与未实测供应商的余量。
    'context'   => ['capacity' => 600000, 'token_capacity' => (int) env('AI_CONTEXT_TOKEN_CAPACITY', 400000)],
    'default'   => 'openai',
    'providers' => [
        // DeepSeek使用官方SDK驱动，实际地址由适配器白名单校验。
        'deepseek' => [
            'driver' => 'deepseek',
            'key'    => env('DEEPSEEK_API_KEY'),
            'url'    => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1')
        ],
        'openai' => [
            'driver' => 'openai',
            'key'    => env('OPENAI_API_KEY'),
            'url'    => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store'  => false,
        ],
        // Claude使用SDK的Anthropic驱动（Messages协议），密钥与地址同样以后台设置为准。
        'anthropic' => [
            'driver' => 'anthropic',
            'key'    => env('ANTHROPIC_API_KEY'),
            'url'    => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],
    ],
    // 模型配置：供应商、可选性与能力在新运行创建时冻结进运行配置，旧运行不受修改影响。
    // extended_output_tokens：正文、修订、复查可用的扩展输出容量（含推理），空值表示保持8192，最大32768。
    // DeepSeek Flash 写整章初稿时推理常达1.4万至1.6万Token，16384会截断正文，故提高到32768。
    // review_reasoning：独立评审的推理强度，空值表示不开启。slot_repair：正文字数失败后按段修复。
    'models' => [
        'deepseek-flash' => [
            'label'                  => 'DeepSeek Flash',
            'provider'               => 'deepseek',
            'selectable'             => true,
            'extended_output_tokens' => 32768,
            'review_reasoning'       => 'low',
            'slot_repair'            => true
        ],
        'deepseek-v4-pro' => [
            'label'                  => 'DeepSeek V4 Pro',
            'provider'               => 'deepseek',
            'selectable'             => true,
            'extended_output_tokens' => 32768,
            'review_reasoning'       => 'low',
            'slot_repair'            => true
        ],
        // Claude 5.x：思考始终开启，按请求冻结思考强度；不接受温度参数，SDK不会发送。
        'claude-opus-5-5' => [
            'label'                  => 'Claude Opus 5.5',
            'provider'               => 'anthropic',
            'selectable'             => true,
            'extended_output_tokens' => 32768,
            'review_reasoning'       => 'low'
        ],
        'claude-sonnet-5-5' => [
            'label'                  => 'Claude Sonnet 5.5',
            'provider'               => 'anthropic',
            'selectable'             => true,
            'extended_output_tokens' => 32768,
            'review_reasoning'       => 'low'
        ],
        'claude-haiku-5-5' => [
            'label'                  => 'Claude Haiku 5.5',
            'provider'               => 'anthropic',
            'selectable'             => true,
            'extended_output_tokens' => 32768,
            'review_reasoning'       => 'low'
        ],
        'claude-fable-5-2' => [
            'label'                  => 'Claude Fable 5.2',
            'provider'               => 'anthropic',
            'selectable'             => true,
            'extended_output_tokens' => 32768,
            'review_reasoning'       => 'low'
        ],
        'gpt-6.1-terra' => [
            'label'                  => 'GPT-6.1 Terra',
            'provider'               => 'openai',
            'selectable'             => true
        ],
        'gpt-6.1-sol' => [
            'label'                  => 'GPT-6.1 Sol',
            'provider'               => 'openai',
            'selectable'             => true
        ],
        'gpt-4.1' => [
            'label'                  => 'GPT-4.1',
            'provider'               => 'openai',
            'selectable'             => true
        ],
        'gpt-4.1-mini' => [
            'label'                  => 'GPT-4.1 Mini',
            'provider'               => 'openai',
            'selectable'             => true
        ],
        // 历史验收曾返回 model_not_found，仅保留供旧记录识别，不再提供选择。
        'gpt-4' => [
            'label'                  => 'GPT-4（历史）',
            'provider'               => 'openai',
            'selectable'             => false
        ],
    ],
    // 单价必须经过核实且按实际响应模型登记；缺失时金额保持未知，不硬编码旧价格。
    // 每项需包含 version、currency、input_per_million、output_per_million；缓存另列单价。
    'costs' => ['prices' => []],
];
