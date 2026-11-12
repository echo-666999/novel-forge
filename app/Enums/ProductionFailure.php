<?php

namespace App\Enums;

/** 自动生产运行的停止原因：统一中文说明、建议动作及是否允许自动恢复一次。 */
enum ProductionFailure: string
{
    /** 作者请求暂停。 */
    case UserPaused = 'USER_PAUSED';

    /** 设定问题自动决策失败。 */
    case SettingDecisionFailed = 'SETTING_DECISION_FAILED';

    /** 设定决策答案数量与问题不一致。 */
    case SettingAnswerCountMismatch = 'SETTING_ANSWER_COUNT_MISMATCH';

    /** 设定结构校验失败。 */
    case SettingInvalid = 'ValidationException';

    /** 大纲批次停止，详情为大纲停止原因。 */
    case OutlineStopped = 'OUTLINE_STOPPED';

    /** 正文运行停止，详情为正文停止原因。 */
    case ContentStopped = 'CONTENT_STOPPED';

    /** 正文段落在句中截断。 */
    case ContentParagraphIncomplete = 'CONTENT_PARAGRAPH_INCOMPLETE';

    /** 评审与事件提取停止，详情为提交准备停止原因。 */
    case SubmissionStopped = 'SUBMISSION_STOPPED';

    /** 按评审定点修订后又未通过推进或钩子检查：章纲要求与已提交正文互相否决，需作者修订章纲。 */
    case OutlineCanonConflict = 'OUTLINE_CANON_CONFLICT';

    /** 领取超时后已完成本地对账。 */
    case InterruptedReconciled = 'INTERRUPTED_RECONCILED';

    /** 队列消费者中断，需要对账。 */
    case WorkerInterrupted = 'WORKER_INTERRUPTED_REQUIRES_RECONCILIATION';

    /** 队列投递失败，尚未发送请求。 */
    case DispatchFailed = 'DISPATCH_FAILED';

    /** 运行步骤不在固定流程内。 */
    case UnknownStep = 'UNKNOWN_AUTOMATION_STEP';

    /** 其他程序错误。 */
    case Other = 'OTHER';

    /** 明确失败且换一次请求可能成功的错误，每种错误在同一章最多自动恢复一次。 */
    private const AUTOMATIC = [
        'CONTENT_STOPPED:invalid_schema', 'CONTENT_STOPPED:provider_failed', 'CONTENT_STOPPED:retry_limit', 'CONTENT_PARAGRAPH_INCOMPLETE',
        'SUBMISSION_STOPPED:EVENT_VALIDATION_BLOCKED', 'SUBMISSION_STOPPED:INVALID_RESPONSE', 'SETTING_DECISION_FAILED',
    ];

    /** 详情码的中文说明；未列出的详情显示原码。 */
    private const DETAILS = [
        'invalid_schema' => '模型返回的结构或证据引用无效', 'provider_failed' => '模型服务明确返回失败', 'retry_limit' => '推进修订次数已用完',
        'execution_limit' => '执行额度已用完', 'unknown' => '调用结果未知，可能已计费', 'length_invalid' => '正文字数不在冻结范围内',
        'blocked' => '检查发现与核心设定冲突', 'progression_insufficient' => '独立检查判定情节推进或章末钩子不足',
        'body_quality_insufficient' => '有限预算内未能修好篇幅或写作问题', 'reading_failed' => '全文核对未通过',
        'dispatch_failed' => '队列投递失败', 'batch_failed' => '大纲批次生成失败', 'queue_interrupted' => '队列任务中断',
        'user_paused' => '作者已暂停', 'REVIEW_NOT_PASSED' => '独立评审未通过', 'EVENT_VALIDATION_BLOCKED' => '事件提取未通过确定性校验',
        'INVALID_RESPONSE' => '评审或提取响应无效', 'UNKNOWN_RESPONSE' => '评审或提取结果未知，可能已计费',
        'budget_exhausted' => '预算不足或未登记单价，已在请求前停止', 'BUDGET_EXHAUSTED' => '预算不足或未登记单价，已在请求前停止',
        'context_capacity' => '上下文超出容量，已在请求前停止', 'CONTEXT_CAPACITY' => '上下文超出容量，已在请求前停止', 'PROVIDER_FAILED' => '模型服务明确返回失败',
        'insufficient_balance' => '模型服务账户余额不足（HTTP 402）', 'INSUFFICIENT_BALANCE' => '模型服务账户余额不足（HTTP 402）',
    ];

    /** 单个停止原因码的中文说明；未登记的码原样返回，空值返回空。 */
    public static function detailLabel(?string $code): ?string
    {
        return $code === null || $code === '' ? null : (self::DETAILS[$code] ?? $code);
    }

    /** 由运行保存的错误串识别类别；带冒号的错误按前缀归类。 */
    public static function fromError(?string $error): ?self
    {
        if ($error === null || $error === '') {
            return null;
        }

        return self::tryFrom(explode(':', $error, 2)[0]) ?? self::Other;
    }

    /** 是否允许自动恢复一次；与原自动恢复白名单保持一致。 */
    public static function isAutomaticallyRecoverable(?string $error, ?string $step): bool
    {
        return in_array($error, self::AUTOMATIC, true)
            || ($step === 'setting' && $error === self::SettingInvalid->value)
            || ($step === 'outline' && $error === 'OUTLINE_STOPPED:batch_failed');
    }

    /** 作者可读的说明与建议动作，原始错误码另行保留在技术详情中。
     * @return array{label: string, advice: string}
     */
    public static function describe(?string $error, ?string $step = null): array
    {
        $case = self::fromError($error);
        if ($case === null) {
            return ['label' => '无', 'advice' => ''];
        }
        $detail = str_contains((string) $error, ':') ? explode(':', (string) $error, 2)[1] : null;
        $detailLabel = $detail === null ? '' : '：'.(self::DETAILS[$detail] ?? $detail);
        $label = match ($case) {
            self::UserPaused => '你已暂停运行',
            self::SettingDecisionFailed => '设定问题的自动决策失败',
            self::SettingAnswerCountMismatch => '设定决策的答案数量与问题不一致',
            self::SettingInvalid => '设定结构或必填内容不完整',
            self::OutlineStopped => '大纲生成停止'.$detailLabel,
            self::ContentStopped => '正文生成停止'.$detailLabel,
            self::ContentParagraphIncomplete => '正文有段落在句中截断',
            self::SubmissionStopped => '评审与事件提取停止'.$detailLabel,
            self::OutlineCanonConflict => '章纲要求与已提交正文冲突：按评审修订后仍未通过推进检查',
            self::InterruptedReconciled => '运行超时中断，已完成本地对账（在途请求记为结果未知）',
            self::WorkerInterrupted => '队列消费者中断，需要对账后恢复',
            self::DispatchFailed => '任务投递失败，尚未发送模型请求',
            self::UnknownStep => '运行步骤异常',
            self::Other => '程序错误（'.$error.'）',
        };
        $advice = match (true) {
            self::isAutomaticallyRecoverable($error, $step) => '系统会针对这类明确失败自动恢复一次；再次失败时请点击“恢复自动生成”。',
            $case === self::UserPaused => '确认后点击“恢复自动生成”从断点继续。',
            $case === self::OutlineCanonConflict => '评审要求的改法与本章章纲（推进点或钩子）互相否决，继续重试只会消耗请求。请到「大纲规划」修订本章章纲，再点击“恢复自动生成”并选择整章重写。',
            in_array($detail, ['insufficient_balance', 'INSUFFICIENT_BALANCE'], true) => '请到模型服务商后台充值，然后点击“恢复自动生成”从当前步骤继续；这次失败不占用自动恢复次数。',
            in_array($detail, ['context_capacity', 'CONTEXT_CAPACITY'], true) => '本次请求在发送前因上下文超限被拦下，未产生费用。点击“恢复自动生成”并选择整章重写，会按当前上下文协议重新冻结前文。',
            $detail === 'REVIEW_NOT_PASSED' => '点击“恢复自动生成”会按评审意见做一次定点修订，再重新评审。',
            in_array($detail, ['unknown', 'UNKNOWN_RESPONSE'], true) || in_array($case, [self::WorkerInterrupted, self::InterruptedReconciled], true) => '先在成本明细核对未知请求，再勾选“已核查未知响应”后恢复。',
            $detail === 'execution_limit' => '在恢复时确认提高本小说执行上限并填写原因。',
            in_array($detail, ['budget_exhausted', 'BUDGET_EXHAUSTED'], true) => '在工作台“调整预算”提高预算，或在“模型单价”登记该模型的单价后，点击“恢复自动生成”。',
            default => '查看正文与评审详情后，点击“恢复自动生成”从断点继续；必要时选择整章重写或更换模型。',
        };

        return ['label' => $label, 'advice' => $advice];
    }
}
