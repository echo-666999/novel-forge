<?php

namespace App\Services\Ai;

use App\Services\Search\EmbeddingService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** 从供应商官网价格页（Markdown 版本）读取单价，转换为登记表单字段；只读取白名单地址，结果须由作者核对后才登记。 */
final class OfficialPriceFetcher
{
    /** 各供应商的官方价格页：Markdown 版本表格列名完整，比网页更稳定。 */
    public const SOURCES = [
        'deepseek' => 'https://api-docs.deepseek.com/quick_start/pricing.md',
        'openai' => 'https://developers.openai.com/api/docs/pricing.md',
        'anthropic' => 'https://platform.claude.com/docs/en/about-claude/pricing.md',
    ];

    /** 不分长短上下文时的空字段，保证切换模型时清掉上一次获取的值。 */
    private const NO_LONG_CONTEXT = ['long_context_threshold' => null, 'long_input_per_million' => null, 'long_cache_read_per_million' => null, 'long_cache_write_per_million' => null, 'long_output_per_million' => null];

    /** 读取一个模型的官方单价。
     * @return array{fields: array<string, string|null>, notes: list<string>}
     *
     * @throws RuntimeException 网络失败、页面结构变化或找不到该模型时，说明原因，作者可改为手工填写。
     */
    public function fetch(string $model): array
    {
        $provider = $model === EmbeddingService::MODEL ? 'openai' : ModelProfiles::provider($model);
        $url = self::SOURCES[$provider] ?? throw new RuntimeException('暂不支持自动获取'.$provider.'的单价，请手工填写。');
        try {
            $response = Http::timeout(20)->connectTimeout(10)->accept('text/markdown')->get($url);
        } catch (Throwable) {
            throw new RuntimeException('无法连接官网价格页（'.$url.'），请稍后重试或手工填写。');
        }
        if (! $response->successful()) {
            throw new RuntimeException('官网价格页返回 HTTP '.$response->status().'，请稍后重试或手工填写。');
        }
        $result = match ($provider) {
            'deepseek' => $this->deepSeek($response->body(), $model),
            'anthropic' => $this->anthropic($response->body(), $model),
            default => $this->openAi($response->body(), $model),
        };
        $result['fields']['source'] = $url.' 自动获取于 '.now()->toDateString().'；'.implode('', $result['notes']);
        $result['fields']['verified_on'] = now()->toDateString();

        return $result;
    }

    /** DeepSeek：模型按列排列，单价分缓存命中、未命中与输出，各有高峰价与闲时价；闲时倍率由两者相除得出，高峰时段取自脚注。
     * @return array{fields: array<string, string|null>, notes: list<string>}
     */
    public function deepSeek(string $markdown, string $model): array
    {
        $rows = self::tableRows($markdown);
        $header = $rows[0] ?? [];
        $column = collect($header)->search(fn (string $cell): bool => self::normalizeName($cell) === $model);
        if ($column === false) {
            throw new RuntimeException('官网价格表中没有找到 '.$model.'，可能已更名，请手工填写。');
        }
        $prices = [];
        foreach ($rows as $row) {
            if (! str_starts_with($row[0] ?? '', 'PRICING') || ! isset($row[1], $row[2], $row[$column])) {
                continue;
            }
            $kind = match (true) {
                str_contains($row[1], 'CACHE HIT') => 'cache_read',
                str_contains($row[1], 'CACHE MISS') => 'input',
                str_contains($row[1], 'OUTPUT') => 'output',
                default => null,
            };
            $period = match (trim($row[2])) {
                'PEAK' => 'peak',
                'OFF-PEAK' => 'off_peak',
                default => null,
            };
            if ($kind !== null && $period !== null) {
                $prices[$period][$kind] = self::money($row[$column]);
            }
        }
        $peak = $prices['peak'] ?? [];
        if (! isset($peak['input'], $peak['output'], $peak['cache_read'])) {
            throw new RuntimeException('官网价格表结构已变化，未能读出 '.$model.' 的高峰价，请手工填写。');
        }
        $notes = ['缓存命中 / 未命中 / 输出均取高峰价。'];
        $fields = ['currency' => $peak['input']['currency'], 'input_per_million' => $peak['input']['amount'], 'cache_read_per_million' => $peak['cache_read']['amount'],
            'output_per_million' => $peak['output']['amount'], 'cache_write_per_million' => null, 'off_peak_ratio' => null, 'peak_hours_utc' => null, ...self::NO_LONG_CONTEXT];
        $ratios = collect(['input', 'cache_read', 'output'])->map(fn (string $kind): ?string => isset($prices['off_peak'][$kind]) && bccomp($peak[$kind]['amount'], '0', 8) > 0
            ? rtrim(rtrim(bcdiv($prices['off_peak'][$kind]['amount'], $peak[$kind]['amount'], 8), '0'), '.') : null)->unique()->values();
        preg_match('/Peak hours are (.+?) UTC/i', $markdown, $peakText);
        preg_match_all('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $peakText[1] ?? '', $windows, PREG_SET_ORDER);
        if ($ratios->count() === 1 && $ratios[0] !== null && $windows !== []) {
            $fields['off_peak_ratio'] = $ratios[0];
            $fields['peak_hours_utc'] = collect($windows)->map(fn (array $window): string => $window[1].'-'.$window[2])->implode(',');
            $notes[] = '闲时价为高峰价的'.$ratios[0].'倍，高峰时段（UTC）'.$fields['peak_hours_utc'].'。';
            if (! preg_match('/Monday through Friday/i', $markdown)) {
                $notes[] = '官网未写明高峰只在工作日，本系统按工作日计，请核对。';
            }
            if (preg_match('/holidays/i', $markdown)) {
                $notes[] = '官网中国法定节假日全天按闲时价，本系统节假日按高峰计，会略高估。';
            }
        } else {
            $notes[] = '未能读出一致的闲时倍率或高峰时段，请核对后手工填写。';
        }

        return ['fields' => $fields, 'notes' => $notes];
    }

    /** OpenAI：先找标准档（Standard）价格表，再找专用模型表（如嵌入）；长上下文价与阈值取自同一行和页面说明。
     * @return array{fields: array<string, string|null>, notes: list<string>}
     */
    public function openAi(string $markdown, string $model): array
    {
        $sections = preg_split('/^### /m', $markdown) ?: [];
        $standard = collect($sections)->first(fn (string $section): bool => str_starts_with($section, 'Standard pricing data'));
        $found = $standard === null ? null : self::findRow((string) $standard, $model);
        $table = '标准档（Standard）';
        if ($found === null) {
            $table = '专用模型表';
            foreach ($sections as $section) {
                if (str_contains($section, '| Category | Model |') && ($found = self::findRow($section, $model)) !== null) {
                    break;
                }
            }
        }
        if ($found === null) {
            throw new RuntimeException('官网标准档价格表中没有找到 '.$model.'，可能已更名，请手工填写。');
        }
        $columns = ['input' => ['input', 'short context input'], 'output' => ['output', 'short context output'], 'cache_read' => ['cached input', 'short context cached input'],
            'cache_write' => ['30m write', 'short context 30m write'], 'long_input' => ['long context input'], 'long_output' => ['long context output'],
            'long_cache_read' => ['long context cached input'], 'long_cache_write' => ['long context 30m write']];
        $values = [];
        foreach ($columns as $key => $names) {
            foreach ($names as $name) {
                if (array_key_exists($name, $found) && $found[$name] !== null) {
                    $values[$key] = self::money($found[$name]);
                }
            }
        }
        if (! isset($values['input'])) {
            throw new RuntimeException('官网价格表结构已变化，未能读出 '.$model.' 的输入价，请手工填写。');
        }
        $notes = ['取'.$table.'价格。'];
        if (! isset($values['output'])) {
            $notes[] = '官网未列输出价（如嵌入模型），输出按0计。';
        }
        $fields = ['currency' => $values['input']['currency'], 'input_per_million' => $values['input']['amount'], 'cache_read_per_million' => $values['cache_read']['amount'] ?? null,
            'output_per_million' => $values['output']['amount'] ?? '0', 'cache_write_per_million' => $values['cache_write']['amount'] ?? null,
            'off_peak_ratio' => null, 'peak_hours_utc' => null, ...self::NO_LONG_CONTEXT];
        if (isset($fields['cache_write_per_million'])) {
            $notes[] = '缓存写入取默认30分钟档；出现12小时写入时金额记为未知。';
        }
        preg_match('/Long context:\s*>\s*(\d+)K input tokens/i', $markdown, $threshold);
        if (isset($values['long_input'], $values['long_output'], $threshold[1])) {
            $fields = [...$fields, 'long_context_threshold' => (string) ((int) $threshold[1] * 1000), 'long_input_per_million' => $values['long_input']['amount'],
                'long_cache_read_per_million' => $values['long_cache_read']['amount'] ?? null, 'long_cache_write_per_million' => $values['long_cache_write']['amount'] ?? null,
                'long_output_per_million' => $values['long_output']['amount']];
            $notes[] = '输入超过'.$threshold[1].'K Token按长上下文价。';
        }

        return ['fields' => $fields, 'notes' => $notes];
    }

    /** Claude：“Model pricing”表按模型名称成行（如 claude-opus-5-5 对应 Claude Opus 5.5）；同一模型分短长提示两行时，第二行为长上下文价，阈值取自行内说明。
     * @return array{fields: array<string, string|null>, notes: list<string>}
     */
    public function anthropic(string $markdown, string $model): array
    {
        $section = preg_split('/^## /m', $markdown) ?: [];
        $table = collect($section)->first(fn (string $part): bool => str_starts_with($part, 'Model pricing'));
        if (! preg_match('/\Aclaude-([a-z]+)-(\d+(?:-\d+)*)\z/', $model, $parts)) {
            throw new RuntimeException('无法识别 Claude 模型名 '.$model.'，请手工填写。');
        }
        $name = 'claude '.$parts[1].' '.str_replace('-', '.', $parts[2]);
        $rows = [];
        $header = null;
        foreach (preg_split('/\R/', (string) $table) ?: [] as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = array_map(fn (string $cell): string => trim(preg_replace('/<sup>.*?<\/sup>|\*/', '', $cell) ?? $cell), self::cells($line));
            if (collect($cells)->every(fn (string $cell): bool => preg_match('/\A:?-+:?\z/', $cell) === 1)) {
                continue;
            }
            if ($header === null) {
                $header = array_map('mb_strtolower', $cells);

                continue;
            }
            $label = mb_strtolower(trim(preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $cells[0]) ?? $cells[0]));
            if (count($cells) === count($header) && ($label === $name || str_starts_with($label, $name.' ('))) {
                $rows[] = ['label' => $label, 'cells' => array_combine($header, $cells)];
            }
        }
        if ($rows === []) {
            throw new RuntimeException('官网价格表中没有找到 '.$model.'，可能已更名，请手工填写。');
        }
        $price = function (array $row, string $column) use ($model): array {
            $cell = $row['cells'][$column] ?? throw new RuntimeException('官网价格表结构已变化，未能读出 '.$model.' 的'.$column.'，请手工填写。');

            return self::money(trim(str_ireplace(['/ mtok', 'usd'], '', $cell)));
        };
        $short = $rows[0];
        $fields = ['currency' => $price($short, 'base input tokens')['currency'], 'input_per_million' => $price($short, 'base input tokens')['amount'],
            'cache_read_per_million' => $price($short, 'cache hits and refreshes')['amount'], 'output_per_million' => $price($short, 'output tokens')['amount'],
            'cache_write_per_million' => $price($short, '5m cache writes')['amount'], 'off_peak_ratio' => null, 'peak_hours_utc' => null, ...self::NO_LONG_CONTEXT];
        $notes = ['取官网标准价（全球路由）；缓存写入取5分钟档，出现1小时缓存写入时金额记为未知。'];
        $long = collect($rows)->first(fn (array $row): bool => preg_match('/over ([\d,]+) tokens/', $row['label']) === 1);
        if ($long !== null && preg_match('/over ([\d,]+) tokens/', $long['label'], $threshold)) {
            $fields = [...$fields, 'long_context_threshold' => str_replace(',', '', $threshold[1]), 'long_input_per_million' => $price($long, 'base input tokens')['amount'],
                'long_cache_read_per_million' => $price($long, 'cache hits and refreshes')['amount'], 'long_cache_write_per_million' => $price($long, '5m cache writes')['amount'],
                'long_output_per_million' => $price($long, 'output tokens')['amount']];
            $notes[] = '提示超过'.number_format((int) $fields['long_context_threshold']).' Token（含缓存）按长提示价。';
        }

        return ['fields' => $fields, 'notes' => $notes];
    }

    /** 在一段 Markdown 中找模型所在行，返回“小写列名 => 单元格”（“-”为空）。
     * @return array<string, string|null>|null
     */
    private static function findRow(string $section, string $model): ?array
    {
        $header = null;
        foreach (preg_split('/\R/', $section) ?: [] as $line) {
            if (! str_starts_with(trim($line), '|')) {
                $header = null;

                continue;
            }
            $cells = self::cells($line);
            if (collect($cells)->every(fn (string $cell): bool => preg_match('/\A:?-+:?\z/', $cell) === 1)) {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn (string $cell): string => mb_strtolower($cell), $cells);

                continue;
            }
            $nameColumn = array_search('model', $header, true);
            if ($nameColumn !== false && self::normalizeName($cells[$nameColumn] ?? '') === $model && count($cells) === count($header)) {
                return array_combine($header, array_map(fn (string $cell): ?string => $cell === '-' || $cell === '' ? null : $cell, $cells));
            }
        }

        return null;
    }

    /** 文档中所有表格行的单元格。
     * @return list<list<string>>
     */
    private static function tableRows(string $markdown): array
    {
        return collect(preg_split('/\R/', $markdown) ?: [])->filter(fn (string $line): bool => str_starts_with(trim($line), '|'))
            ->map(fn (string $line): array => self::cells($line))
            ->reject(fn (array $cells): bool => collect($cells)->every(fn (string $cell): bool => preg_match('/\A:?-+:?\z/', $cell) === 1))->values()->all();
    }

    /** 拆分一行 Markdown 表格。
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        return array_map('trim', explode('|', trim(trim($line), '|')));
    }

    /** 模型名去掉脚注编号、链接、上下文长度与弃用标记。 */
    private static function normalizeName(string $cell): string
    {
        $name = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $cell) ?? $cell;
        $name = preg_replace('/\s*\((?:\d+|<[^)]*)\)\s*$|\s*Deprecated\s*$/i', '', trim($name)) ?? $name;

        return mb_strtolower(trim($name));
    }

    /** 解析“$0.30”“¥2”这类金额。
     * @return array{currency: string, amount: string}
     */
    private static function money(string $cell): array
    {
        if (! preg_match('/\A([$¥])\s*(\d{1,8}(?:\.\d{1,8})?)\z/u', trim($cell), $match)) {
            throw new RuntimeException('官网价格格式无法识别（'.$cell.'），请手工填写。');
        }

        return ['currency' => $match[1] === '$' ? 'USD' : 'CNY', 'amount' => $match[2]];
    }
}
