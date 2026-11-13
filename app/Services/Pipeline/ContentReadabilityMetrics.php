<?php

namespace App\Services\Pipeline;

/** 正文可读性本地指标：每章计算并展示，只在明显偏离小说写法的极端值上触发修订；阈值依据历史正文分布设定，不替代模型评审。 */
final class ContentReadabilityMetrics
{
    public const VERSION = 'b04-readability-v1';

    /** 直接对白占全文可见字数的下限；历史上的流程记录式正文为零到百分之五。 */
    public const MIN_DIALOGUE_SHARE = 0.05;

    /** 每千字流程词上限；场景化正文约八，流程记录式正文常在二十以上。 */
    public const MAX_PROCESS_DENSITY = 20.0;

    /** 流程记录式写法的高频词。 */
    private const PROCESS_WORDS = '核对|比对|登记|编号|栏|封存|注明|记录|签字|交接|备注|核验|归档|清点|台账';

    /** 引出转述而非直接引语的说话动词，用于估计转述对白的多少。 */
    private const REPORTING_VERBS = '说|问|告诉|提醒|叮嘱|催|答应|解释|交代';

    /** 计算本章指标；只读正文，不改动任何文本。
     * @return array{version: string, characters: int, dialogue_share: float, process_density: float, disclaimers: int, reported_speech: int}
     */
    public static function measure(string $body): array
    {
        $characters = ContentSchema::count($body);
        // 引号内含句读才算说出口的话，引用的栏目、标题等短词不计入对白。
        preg_match_all('/“[^”]*[，。！？…；：][^”]*”/u', $body, $quotes);
        $dialogue = array_sum(array_map(fn (string $quote): int => ContentSchema::count($quote), $quotes[0]));
        // 去掉引语后再找“说/问……”，剩下的多是转述；只作估计，供观察趋势。
        $withoutQuotes = preg_replace('/“[^”]*”/u', '', $body);

        return [
            'version' => self::VERSION,
            'characters' => $characters,
            'dialogue_share' => $characters === 0 ? 0.0 : round($dialogue / $characters, 3),
            'process_density' => $characters === 0 ? 0.0 : round(1000 * preg_match_all('/'.self::PROCESS_WORDS.'/u', $body) / $characters, 1),
            'disclaimers' => preg_match_all('/没有?[^。！？，“”]{0,12}[，、]?也没/u', $body),
            'reported_speech' => preg_match_all('/(?:'.self::REPORTING_VERBS.')(?:了一句|了声|着|道)?[^。！？：:“”\n]{2,}/u', $withoutQuotes),
        ];
    }

    /** 只有极端值才成为需修订的问题，并写明实测值、阈值和改法。
     * @param  array{dialogue_share: float, process_density: float}  $metrics
     * @return list<array<string, mixed>>
     */
    public static function issues(array $metrics): array
    {
        $issues = [];
        if ($metrics['dialogue_share'] < self::MIN_DIALOGUE_SHARE) {
            $issues[] = ['type' => 'readability_dialogue', 'actual' => $metrics['dialogue_share'], 'threshold' => self::MIN_DIALOGUE_SHARE,
                'reason' => '直接对白只占全文'.round(100 * $metrics['dialogue_share'], 1).'%，低于'.round(100 * self::MIN_DIALOGUE_SHARE).'%：把关键交流从转述改写为人物之间的直接对白，让对白承载冲突和情绪'];
        }
        if ($metrics['process_density'] > self::MAX_PROCESS_DENSITY) {
            $issues[] = ['type' => 'readability_process', 'actual' => $metrics['process_density'], 'threshold' => self::MAX_PROCESS_DENSITY,
                'reason' => '核对、登记、编号等流程词每千字'.$metrics['process_density'].'个，超过'.self::MAX_PROCESS_DENSITY.'：把逐项罗列的流程压缩为一两句，只保留对人物和剧情有后果的那一步，腾出篇幅写人物反应与对白'];
        }

        return $issues;
    }
}
