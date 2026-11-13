<?php

namespace App\Services\Ai;

use App\Services\Pipeline\PlanningJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 管理模板版本和发布指针；运行只消费创建时冻结的正文。 */
final class PromptTemplateManager
{
    /** 可编辑阶段白名单；输出结构与业务硬门禁仍由程序契约管理。 */
    public const SCOPES = ['common' => '公共', 'setting' => '设定', 'decision' => '设定决策', 'outline' => '大纲', 'fine' => '细纲', 'draft' => '正文', 'revise' => '修订', 'check' => '正文检查', 'review' => '提交评审', 'extract' => '状态提取'];

    /** 保存总是创建新草稿，历史正文从不原地更新。 */
    public function draft(string $scope, string $body, string $notes, int $actor): int
    {
        validator(compact('scope', 'body', 'notes'), ['scope' => ['required', 'in:'.implode(',', array_keys([...self::SCOPES, ...BuiltinPromptTemplates::scopes()]))], 'body' => ['required', 'string', 'max:12000'], 'notes' => ['required', 'string', 'max:2000']])->validate();

        return DB::table('prompt_template_versions')->insertGetId(['scope' => $scope, 'body' => $body, 'notes' => $notes, 'language' => 'zh-CN', 'status' => 'draft', 'content_hash' => PlanningJson::hash([$scope, $body]), 'created_by' => $actor, 'created_at' => now()]);
    }

    /** 变量验证与本地确定性渲染回归；明确记录零次真实请求。 */
    public function test(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $version = DB::table('prompt_template_versions')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! in_array($version->status, ['draft', 'tested'], true)) {
                throw ValidationException::withMessages(['template' => '历史版本只读，请复制为新草稿。']);
            }
            $rendered = $this->preview($version->scope, $version->body);
            $report = ['passed' => true, 'kind' => 'local-variable-render-regression', 'content_hash' => $version->content_hash, 'sample' => $rendered, 'real_ai_requests' => 0, 'tested_at' => now()->toIso8601String()];
            DB::table('prompt_template_versions')->where('id', $id)->update(['status' => 'tested', 'test_report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

            return $report;
        });
    }

    /** 发布或回滚使用同一锁和预期指针，防止后台并发覆盖他人发布。 */
    public function activate(int $id, ?int $expected, int $actor, bool $rollback = false): void
    {
        DB::transaction(function () use ($id, $expected, $actor, $rollback): void {
            $version = DB::table('prompt_template_versions')->where('id', $id)->firstOrFail();
            $route = DB::table('prompt_template_routes')->where('scope', $version->scope)->lockForUpdate()->firstOrFail();
            $version = DB::table('prompt_template_versions')->where('id', $id)->lockForUpdate()->firstOrFail();
            $report = json_decode($version->test_report ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if ($route->version_id !== $expected || ! in_array($version->status, $rollback ? ['retired'] : ['tested'], true)
                || ($report['passed'] ?? false) !== true || ($report['content_hash'] ?? null) !== PlanningJson::hash([$version->scope, $version->body])) {
                throw ValidationException::withMessages(['template' => '发布指针已变化，或版本未通过本地回归；请刷新后重试。']);
            }
            if ($rollback && ! DB::table('prompt_template_events')->where('scope', $version->scope)->where('to_version_id', $id)->exists()) {
                throw ValidationException::withMessages(['template' => '只能回滚到曾发布的历史版本。']);
            }
            if ($expected !== null) {
                DB::table('prompt_template_versions')->where('id', $expected)->update(['status' => 'retired']);
            }
            DB::table('prompt_template_versions')->where('id', $id)->update(['status' => 'published']);
            DB::table('prompt_template_routes')->where('scope', $version->scope)->update(['version_id' => $id]);
            $this->event($version->scope, $expected, $id, $rollback ? 'rollback' : 'publish', $actor);
        });
    }

    /** 退役只影响未来新小说，已冻结运行仍保留原正文。 */
    public function retire(string $scope, int $expected, int $actor): void
    {
        DB::transaction(function () use ($scope, $expected, $actor): void {
            $route = DB::table('prompt_template_routes')->where('scope', $scope)->lockForUpdate()->firstOrFail();
            if ($route->version_id !== $expected) {
                throw ValidationException::withMessages(['template' => '发布指针已变化，请刷新。']);
            }
            DB::table('prompt_template_versions')->where('id', $expected)->update(['status' => 'retired']);
            DB::table('prompt_template_routes')->where('scope', $scope)->update(['version_id' => null]);
            $this->event($scope, $expected, null, 'retire', $actor);
        });
    }

    /** 单条查询取得一致发布集合，完整正文随新小说运行保存。 */
    public function snapshot(): array
    {
        $items = [];
        foreach (DB::table('prompt_template_routes as r')->join('prompt_template_versions as v', 'v.id', '=', 'r.version_id')->orderBy('r.scope')->get(['v.id', 'v.scope', 'v.body', 'v.content_hash']) as $version) {
            if ($version->content_hash !== PlanningJson::hash([$version->scope, $version->body])) {
                throw ValidationException::withMessages(['template' => '发布模板摘要不一致，拒绝启动。']);
            }
            $items[$version->scope] = (array) $version;
        }

        return ['version' => 'c06-v1', 'items' => $items, 'hash' => PlanningJson::hash($items)];
    }

    /** 仅读冻结快照；旧运行缺少快照时不会回读当前发布路由。 */
    public function instructions(?array $snapshot, string $scope, array $variables): string
    {
        if ($snapshot === null) {
            return '';
        }
        if (($snapshot['version'] ?? null) !== 'c06-v1' || ($snapshot['hash'] ?? null) !== PlanningJson::hash($snapshot['items'] ?? [])) {
            throw ValidationException::withMessages(['template' => '运行模板快照摘要不一致。']);
        }
        $parts = [];
        foreach (['common', $scope] as $key) {
            if (isset($snapshot['items'][$key])) {
                $parts[] = $this->render($snapshot['items'][$key]['body'], [...$variables, 'stage' => $scope]);
            }
        }

        return $parts === [] ? '' : "\n补充创作要求（不得覆盖程序输出契约和硬约束）：\n".implode("\n", $parts);
    }

    /** 仅替换白名单变量，不执行 Blade、PHP 或递归模板求值。 */
    /** 用固定样例变量渲染模板，供本地回归与编辑页预览共用；内置固定文本原样返回，变量错误抛出校验异常。 */
    public function preview(string $scope, string $body): string
    {
        return str_starts_with($scope, 'builtin_') ? $body : $this->render($body, ['title' => '验收样例书名', 'genre' => '现实生活', 'stage' => $scope]);
    }

    private function render(string $body, array $variables): string
    {
        $result = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $match) use ($variables): string {
            if (! array_key_exists($match[1], $variables)) {
                throw ValidationException::withMessages(['template' => '未知模板变量：'.$match[1]]);
            }

            return (string) $variables[$match[1]];
        }, $body);
        // 先检查源模板，变量值作为纯文本插入，不再解释其中的双花括号。
        if (str_contains(preg_replace('/\{\{\s*(title|genre|stage)\s*\}\}/', '', $body), '{{') || str_contains(preg_replace('/\{\{\s*(title|genre|stage)\s*\}\}/', '', $body), '}}')) {
            throw ValidationException::withMessages(['template' => '变量格式无效，仅支持 title、genre、stage。']);
        }

        return $result;
    }

    /** 记录发布切换事实，不删除旧版本。 */
    private function event(string $scope, ?int $from, ?int $to, string $action, int $actor): void
    {
        DB::table('prompt_template_events')->insert(['scope' => $scope, 'from_version_id' => $from, 'to_version_id' => $to, 'action' => $action, 'actor_id' => $actor, 'created_at' => now()]);
    }
}
