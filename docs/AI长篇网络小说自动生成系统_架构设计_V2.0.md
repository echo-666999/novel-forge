# AI 长篇网络小说自动生成系统

**架构设计文档 V2.0**

对应需求基线：PRD V1.3  
设计日期：2026年11月2日  
目标：单人可独立实现、运行与维护的长篇网络小说自动生成工作台

---

## 1. 文档说明与设计目标

### 1.1 文档目的

本文件定义系统在技术实现层面的整体架构、核心组件职责、数据流、关键约束落地方式，作为开发、联调与后续演进的统一依据。

### 1.2 设计目标

| 目标 | 说明 |
|------|------|
| 一次跑通 | 默认路径下输入创意后可自动完成完整流程，无需人工逐步干预 |
| 反循环 | 禁止开放式任务拆分，采用固定阶段状态机，所有循环有硬上限 |
| 情节可推进 | 大纲强制输出推进点，正文强制检查，防止原地打转 |
| 状态可信 | 正式剧情仅通过 Canonical Commit 进入，可回放、可追溯 |
| 可观测 | 每个阶段输入、输出、成本、执行次数在后台完整可查 |
| 可恢复 | 任意失败可从有效检查点继续，不污染已提交事实 |
| 单人友好 | 技术栈成熟、依赖少、Filament 快速出后台 |

### 1.3 对应 PRD 硬约束落地

- 固定阶段状态机 → Pipeline + StageExecutor
- 强制情节推进点 → Outline / FineOutline / Content 阶段强制字段 + ProgressionChecker
- 执行次数硬上限 → Novel.execution_count + 全局监控
- 成本只记录不阻断 → CostTracker 仅记账
- Canonical Commit → 独立原子提交服务
- 故事状态引擎 → Event Log + State Projection

---

## 2. 架构原则

1. **固定阶段优于动态规划**  
   阶段集合预先定义，运行时不得由 LLM 自行增加阶段或子任务树。

2. **正式事实与候选严格分离**  
   草稿、评审、候选版本不得直接成为正式状态；只有 Canonical Commit 成功后才写入正式事实。

3. **同步核心，异步辅助**  
   提交事务内只做状态与事件落库；索引、向量、摘要更新走 Outbox 异步。

4. **失败局部化**  
   重试与 AI 修复仅作用于当前阶段或当前章节，禁止全局重规划。

5. **一切可追溯**  
   每次 LLM 调用、每次状态变更、每次提交均保留完整输入输出与版本信息。

6. **成本透明但不阻断**  
   所有 Token 与费用实时记录，预算耗尽只暂停新请求，不回滚已有产物。

---

## 3. 高层架构

```
┌─────────────────────────────────────────────────────────────────┐
│                        Filament 5 Admin                         │
│  (项目列表 / 工作台 / 流水线 / 状态账本 / Prompt / 成本 / 导出)      │
└────────────────────────────┬────────────────────────────────────┘
                             │ 调用应用服务（不直接写正式剧情）
┌────────────────────────────▼────────────────────────────────────┐
│                     Application Services                        │
│  NovelPipelineService │ StageExecutors │ ProgressionChecker     │
│  StoryStateService    │ CanonicalCommitService │ ContextBuilder │
│  AiRepairService      │ CostTracker │ PromptManager │ ExportSvc  │
└────────────┬───────────────────────────────┬────────────────────┘
             │                               │
┌────────────▼──────────────┐   ┌────────────▼────────────────────┐
│     Domain Models         │   │      Infrastructure             │
│  Novel / Chapter / Scene  │   │  Laravel AI SDK / Queue / Redis  │
│  NovelStage / Event      │   │  PostgreSQL 18 + pgvector        │
│  Foreshadow / Memory      │   │  Outbox / File Storage           │
│  PromptTemplate / Route   │   │                                 │
└───────────────────────────┘   └─────────────────────────────────┘
```

**部署形态（首期）**：  
单应用（Web + Filament） + 独立 Queue Worker + PostgreSQL + Redis + 私有文件存储。

---

## 4. 领域划分与核心概念

### 4.1 有界上下文

| 上下文 | 职责 |
|--------|------|
| 规划上下文 | 创意解析、蓝图、五层结构、结局契约 |
| 生产上下文 | 场景/章节生成、评审、修订、推进检查 |
| 故事状态上下文 | 事件日志、状态投影、Canonical Commit |
| 记忆与检索上下文 | 摘要、向量、中文混合检索、上下文组装 |
| 伏笔上下文 | 埋设、强化、到期、回收 |
| 运行与治理上下文 | 流水线、检查点、费用、Prompt、供应商路由 |

### 4.2 五层故事结构（保持 PRD）

```
Novel
 └── Volume
      └── StoryArc
           └── Chapter
                └── Scene
```

跨卷长线通过 Novel 级 Thread 管理，由多个卷内 Arc 承接。

### 4.3 正式状态 vs 候选

- **候选（Candidate）**：生成中的正文、事件提取结果、评审结果，可被丢弃或覆盖。
- **正式（Canonical）**：仅通过 Canonical Commit 写入的事件与状态投影，不可原地修改，只能通过新事件演进。

---

## 5. 核心组件设计

### 5.1 固定阶段状态机（NovelPipelineService）

**阶段集合（固定，不可运行时扩展）**：

```
setting → outline → fine_outline(optional) → content → polish → export
```

正文阶段内部按章节顺序有限循环，是唯一允许的循环。

**关键字段（Novel）**：
- `current_stage`
- `execution_count` / `max_execution_count`
- `status`（pending / running / paused / completed / error）
- `progress` / `chapters_done` / `chapters_total`

**推进规则**：
- 阶段成功 → 自动进入下一阶段
- 阶段失败 → 停在当前阶段，等待 AI 修复或人工干预
- `execution_count >= max_execution_count` → 强制暂停

### 5.2 StageExecutor 体系

每个阶段对应一个 Executor，实现统一接口：

```php
interface StageExecutorInterface
{
    public function execute(Novel $novel, NovelStage $stage): StageResult;
}
```

| Executor | 核心职责 |
|----------|----------|
| SettingExecutor | 产出结构化设定（世界观、人物卡、基调） |
| OutlineExecutor | 产出分章大纲 + **强制推进点** |
| FineOutlineExecutor | 细化每章执行蓝图，继承推进点 |
| ContentExecutor | 按章生成正文 + 调用 ProgressionChecker |
| PolishExecutor | 一致性检查 + 轻度润色 |
| ExportExecutor | 拼接与导出 |

**反循环保障**：Executor 内部重试次数有硬上限，失败只返回 StageResult::failed，不创建新任务树。

### 5.3 情节推进检查（ProgressionChecker）

输入：
- 本章强制推进点（来自大纲/细纲）
- 本章正文
- 前 1～3 章摘要（可选向量）

检查逻辑：
1. 规则层：推进点关键词/结构是否覆盖
2. 向量层（辅助）：与前几章 summary 的相似度是否过高
3. 可选轻量 LLM 判断

输出：
```json
{
  "passed": false,
  "missing_points": ["..."],
  "similarity": 0.87,
  "reason": "..."
}
```

推进不足 → 有限重试（默认 ≤ 2）→ 仍失败则标记章节失败，等待修复/人工。

### 5.4 故事状态引擎

**双轨存储**：
- `story_events`：不可变事件日志（append-only）
- `story_state_projections`：可重建的当前/历史投影

**事实类别**：客观已确认 / 人物相信 / 传闻 / 推测 / 谎言 / 作者隐藏真相。

**状态变更流程**：
1. 从正文提取 proposed events + proposed patch
2. 确定性验证（实体、物品唯一、死亡后行动、能力边界、知情途径等）
3. 模型辅助语义检查（不作为最终裁定）
4. 通过后进入 Canonical Commit

### 5.5 Canonical Commit Service

**提交单位**：章。

**原子步骤**（同一数据库事务）：
1. 锁定小说提交游标与目标章
2. 校验幂等键与 expected_state_version
3. 重验评审有效性、事件、补丁
4. 写入提交记录、正式版本指针、事件、状态投影、伏笔变化
5. 写入 Outbox
6. 提交事务

失败返回明确错误码（STALE_STATE、REVIEW_STALE、UNAPPROVED_ENTITY 等）。

### 5.6 上下文组装（ContextBuilder）

组装顺序（不可随意调整优先级）：
1. 系统任务与输出契约
2. 不可丢失的硬约束
3. 本章/场景计划（含强制推进点）
4. 当前正式状态
5. 相关人物知识与关系
6. 到期伏笔与终局约束
7. 上一场景结尾 + 最近章节摘要
8. 相关长期记忆
9. 文风样例与负面清单

硬约束与到期必回收项**不可裁剪**。超限时优先去重与压缩低相关记忆，仍超限则阻塞或拆分。

### 5.7 Prompt 管理

- 公共模板片段（角色身份、全局硬约束、禁止输出字段）
- 阶段专属模板
- 运行时拼接 + 变量注入
- 版本：draft → tested → published → retired
- 已发布版本不可原地覆盖；运行启动时冻结所用版本

### 5.8 AI 修复（AiRepairService）

- 仅针对**当前失败的 NovelStage 或 Chapter**
- 输入：错误信息 + 原始 input + 阶段目标
- 输出：修复后的 output 或修正后的 prompt
- 修复本身也有次数上限，超过转人工
- **禁止**修复逻辑再派生新阶段或全局重规划

### 5.9 成本追踪（CostTracker）

- 每次 LLM 调用记录 input/output/cache/reasoning tokens 与费用
- 累加到 Novel 与 Stage
- **任何情况下不得因费用上涨而自动停止或回滚已有产物**

---

## 6. 数据架构

### 6.1 核心表（简化）

```
novels
novel_stages          -- 阶段执行记录（完整 input/output）
chapters
scenes
story_events          -- 不可变事件日志
story_state_projections
foreshadows
memories
prompt_templates
prompt_template_versions
model_providers
model_routes
cost_logs
repair_logs
generation_runs
outbox_messages
```

### 6.2 向量与检索

- `chapters.embedding` / `memories.embedding`（pgvector）
- 中文全文：tsvector + GIN（zhparser 或 pg_jieba）
- 模糊：pg_trgm
- 检索时强制按 novel_id + 正式基线 + 章位上限 + 角色可见性过滤

### 6.3 关键索引建议

- novels(status, updated_at)
- novel_stages(novel_id, stage, status)
- chapters(novel_id, number) UNIQUE
- story_events(novel_id, chapter_number, created_at)
- foreshadows(novel_id, status, target_chapter)
- cost_logs(novel_id, created_at)

---

## 7. 队列与任务设计

### 7.1 主要 Job

| Job | 职责 | 队列建议 |
|-----|------|----------|
| RunStageJob | 执行单个阶段 | high |
| GenerateChapterJob | 生成单章正文 + 推进检查 | default |
| CanonicalCommitJob | 正式提交（或同步调用） | high |
| BuildMemoryIndexJob | 摘要与向量更新 | low |
| ExportNovelJob | 导出文件 | low |
| WatchdogJob | 检测僵尸任务与超时 | system |

### 7.2 可靠性要求

- 所有写正式状态的操作必须幂等（idempotency_key）
- 使用 Outbox 保证「状态已提交 → 后续索引必触发」
- Worker 崩溃后可通过检查点从失败阶段续跑
- 全局与单阶段执行次数双重限制

---

## 8. Filament 后台结构

### 8.1 导航分组建议

```
创作
  - 小说列表 / 工作台
  - 蓝图与规划
  - 正文与生产
  - 评审与提交
  - 故事状态与事件
  - 伏笔账本
  - 记忆与检索

治理
  - Prompt 模板
  - 模型与路由
  - 文风与评审策略
  - 成本与预算

系统
  - 导出中心
  - 验收工作台
  - 审计与健康
  - 全局设置
```

### 8.2 核心页面与服务对应

| 页面 | 主要服务 |
|------|----------|
| 小说工作台 / 流水线 | NovelPipelineService |
| 运行详情与恢复台 | Pipeline + Checkpoint |
| 故事状态与事件账本 | StoryStateService |
| 提交与基线管理 | CanonicalCommitService |
| 上下文检查器 | ContextBuilder |
| Prompt 模板与发布 | PromptManager |
| 成本账本 | CostTracker |

Filament Action 只调用应用服务，禁止在页面中直接写正式事件或状态。

---

## 9. LLM 集成架构

```
StageExecutor
    → PromptManager.resolve(stage, variables)
    → ContextBuilder.build(...)
    → Laravel AI SDK (统一入口)
    → Provider Adapter (OpenAI / Anthropic / DeepSeek ...)
    → 记录 CostLog + 原始响应
    → 解析 Structured Output
    → ProgressionChecker / State Extractor
```

- 所有请求保存实际使用的 model、route_version、prompt_version
- 支持 Fake Provider 用于离线回归
- 结构化输出失败时有限次格式修复，不得凭空补剧情字段

---

## 10. 关键流程（简图）

### 10.1 正常生成一章

```
ContentExecutor
  → 组装上下文（含推进点）
  → 调用 LLM 生成正文
  → ProgressionChecker
      ├─ 通过 → 提取事件 → 评审 → Canonical Commit
      └─ 不足 → 有限重试 → 仍失败则暂停该章
```

### 10.2 正式提交

```
CanonicalCommitService.commit(chapter_version_id, review_id, expected_state_version)
  → 事务 + 锁
  → 校验 + 重验
  → 写事件 / 投影 / 伏笔变化
  → Outbox
  → Commit
```

### 10.3 失败恢复

```
检测到失败阶段
  → 展示错误 + 已有产物 + 费用
  → 选项：AI 修复（当前阶段） / 重跑 / 编辑输入后继续 / 跳过 / 人工介入
  → 修复成功后从该阶段继续，不重头开始
```

---

## 11. 安全、观测与部署

### 11.1 安全

- 供应商密钥加密存储，前端仅显示末几位
- Prompt 与用户内容隔离，防止注入管理动作
- 文件上传校验与非公开存储
- 供应商地址白名单，防 SSRF

### 11.2 观测

- 全链路 trace_id
- 指标：队列等待、阶段耗时、提交冲突、执行次数、伏笔逾期、预算使用率
- 日志与原始响应分离存储

### 11.3 部署建议（首期）

- 应用与 Worker 分离
- PostgreSQL 18（含 pgvector + 中文分词扩展）
- Redis
- 每日备份 + 定期隔离恢复演练

---

## 12. 演进路线（与 PRD 实施阶段对齐）

| 阶段 | 架构重点 |
|------|----------|
| A 技术验证 | 依赖锁定、pgvector/分词验证、AI SDK 连通、固定状态机骨架 |
| B MVP 闭环 | Pipeline + ContentExecutor + 简单状态 + Canonical Commit + 基础 Filament |
| C P0 长篇 | 伏笔、完整状态引擎、混合检索、全部 P0 后台、百章验收 |
| D 稳定发布 | 观测、备份恢复、性能基线、安全加固 |
| E P1/P2 | 导出增强、可视化、评测体系 |

---

## 13. 架构决策记录（ADR 摘要）

| 决策 | 选择 | 原因 |
|------|------|------|
| 阶段编排 | 固定状态机，而非 Agent 动态规划 | 彻底解决历史循环失控问题 |
| 正式事实 | Event Sourcing 风格 + 投影 | 可回放、可追溯、防污染 |
| 提交 | 章级 Canonical Commit | 平衡粒度与一致性 |
| 后台 | Filament 5 | 单人高效交付 |
| 向量 | pgvector 同库 | 减少运维组件，满足当前规模 |
| 成本 | 只记录不阻断 | 符合产品原则 |

---

**文档结束**

本架构设计 V2.0 完整对齐 PRD V1.3 的硬性约束与能力要求，可作为开发实施的直接输入。后续若领域模型或阶段划分有重大调整，应同步更新本文档并保留变更记录。
