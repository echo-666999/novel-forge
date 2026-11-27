<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 题材包与文风预设：后台可增删、可停用的列表，建书时整份复制进小说，之后修改不影响已建的书。
 * 内置项是初始数据（开发验证避开"现实生活""现实悬疑"），示范段落为原创，作者可在后台改写。
 */
return new class extends Migration
{
    /** 内置题材包。 */
    private const GENRES = [
        'era' => ['年代', '物资紧、规矩多的年代里，凭本事和眼光把日子过红火', [
            'core_promise' => '在物资紧、规矩多的年代里，主角凭本事和眼光把日子过红火，让看不起他的人一个个闭嘴。',
            'payoff_types' => [['name' => '打脸极品亲戚', 'weight' => 30], ['name' => '赚钱致富', 'weight' => 25], ['name' => '护短', 'weight' => 15], ['name' => '甜', 'weight' => 15], ['name' => '眼界碾压', 'weight' => 15]],
            'beat_template' => '受压 → 反击小胜 → 更大的麻烦 → 借势翻盘 → 站上新台阶',
            'world_rules_hints' => '物价、票证、政策节点大致合理即可，不必精确到月；人情、面子、单位和街坊是主要的压力来源。',
            'archetypes' => ['极品亲戚', '刀子嘴豆腐心的长辈', '闷声干大事的搭档', '眼红的同龄人', '讲理的老干部'],
            'taboos' => ['主角圣母，受辱后长期不反击', '金手指没有代价', '用政策条文和审批流程推动情节'],
            'default_style' => 'colloquial',
        ]],
        'rebirth' => ['重生', '带着前世记忆重来，前世吃过的亏一个个讨回来', [
            'core_promise' => '带着前世的记忆和遗憾重来一次，前世吃过的亏这辈子一个个讨回来，护住前世没护住的人。',
            'payoff_types' => [['name' => '打脸仇人', 'weight' => 30], ['name' => '先知先觉布局', 'weight' => 25], ['name' => '弥补遗憾', 'weight' => 20], ['name' => '赚钱翻身', 'weight' => 15], ['name' => '身份揭晓', 'weight' => 10]],
            'beat_template' => '重生醒来、确认处境 → 抢在前世悲剧前出手 → 改变带来新麻烦 → 用前世记忆反制 → 改写命运节点',
            'world_rules_hints' => '前世记忆是最大的优势，也是最大的变数：改动越多，记忆越不可靠。',
            'archetypes' => ['前世害过主角的人', '前世错过的人', '前世被辜负的亲人', '不按前世剧本走的新变数'],
            'taboos' => ['重生后被同一个坑坑两次', '前世记忆无所不知、没有变数', '只顾复仇，没有生活'],
            'default_style' => 'colloquial',
        ]],
        'transmigration' => ['穿越', '现代人掉进陌生世界，用见识和脑子站稳脚跟', [
            'core_promise' => '现代人掉进陌生世界，用现代的见识和脑子在这里站稳脚跟，越混越好。',
            'payoff_types' => [['name' => '现代见识降维', 'weight' => 25], ['name' => '打脸', 'weight' => 25], ['name' => '身份逆袭', 'weight' => 20], ['name' => '搞钱', 'weight' => 15], ['name' => '反差笑点', 'weight' => 15]],
            'beat_template' => '穿来、处境危急 → 用现代见识化解第一关 → 名声引来更大的麻烦 → 借势结盟 → 登上更高的舞台',
            'world_rules_hints' => '现代知识要落地：做得出来、有成本、会被模仿；新旧观念的冲突是笑点和矛盾的来源。',
            'archetypes' => ['原身留下的烂摊子', '看不起主角的上位者', '被主角吸引的聪明人', '守旧的长辈', '看热闹的街坊'],
            'taboos' => ['现代知识没有成本、立刻见效', '古人个个是傻子', '主角说教'],
            'default_style' => 'humorous',
        ]],
        'history' => ['历史', '在真实的历史大势里，凭见识和胆量撬动大事', [
            'core_promise' => '在真实的历史大势里，主角凭见识和胆量改变身边人的命运，甚至撬动大事。',
            'payoff_types' => [['name' => '预判大势', 'weight' => 25], ['name' => '朝堂斗智', 'weight' => 25], ['name' => '打脸权贵', 'weight' => 20], ['name' => '收服人才', 'weight' => 15], ['name' => '家国情义', 'weight' => 15]],
            'beat_template' => '小人物入局 → 借一件小事显露本事 → 卷入更大的权力漩涡 → 借大势翻盘 → 登上更高的台阶',
            'world_rules_hints' => '大事件、年号、官制和史实大致对得上，细节可以虚构；不要大段科普。',
            'archetypes' => ['多疑的上位者', '老谋深算的对手', '忠心但莽撞的伙伴', '看透一切的老人'],
            'taboos' => ['大段史料科普', '历史名人降智', '用现代价值观说教'],
            'default_style' => 'humorous',
        ]],
        'alt_history' => ['架空', '自洽的虚构王朝里，从底层一路往上走', [
            'core_promise' => '在一个自洽的虚构王朝或世界里，主角从底层一路往上走，规矩由他来改。',
            'payoff_types' => [['name' => '升官晋级', 'weight' => 25], ['name' => '打脸', 'weight' => 25], ['name' => '智斗', 'weight' => 20], ['name' => '收服人才', 'weight' => 15], ['name' => '情义', 'weight' => 15]],
            'beat_template' => '底层受压 → 小试身手 → 被更大的势力盯上 → 以智破局 → 晋升新层级',
            'world_rules_hints' => '制度可以虚构，但要前后一致：钱怎么来、官怎么升、谁说了算，先定好再写。',
            'archetypes' => ['刁难的顶头上司', '背后撑腰的大人物', '机灵的跟班', '有野心的同僚', '嘴硬心软的长辈'],
            'taboos' => ['设定随用随改', '主角靠运气不靠本事', '用公文流程代替冲突'],
            'default_style' => 'colloquial',
        ]],
        'xuanhuan' => ['玄幻', '低谷起步一步步变强，看到更大的世界', [
            'core_promise' => '从废柴或低谷起步，一步步变强，把曾经压在头上的人踩下去，看到更大的世界。',
            'payoff_types' => [['name' => '升级突破', 'weight' => 30], ['name' => '打脸', 'weight' => 25], ['name' => '越级战斗', 'weight' => 20], ['name' => '奇遇宝物', 'weight' => 15], ['name' => '身份揭晓', 'weight' => 10]],
            'beat_template' => '被看不起 → 得到机缘、第一次反击 → 卷入更强的势力 → 越级一战成名 → 进入更大的地图',
            'world_rules_hints' => '修炼等级和力量代价先定清楚；越级要有理由；地图逐步展开。',
            'archetypes' => ['嚣张的天才', '护短的师父', '心机深的同门', '神秘的引路人', '一路同行的伙伴'],
            'taboos' => ['等级体系前后矛盾', '主角无代价碾压一切', '战斗只报招式名'],
            'default_style' => 'passionate',
        ]],
    ];

    /** 内置文风预设。 */
    private const STYLES = [
        'humorous' => ['幽默风趣', '爱吐槽的叙述者，反差和误会制造笑点', [
            'narrator_attitude' => '爱吐槽的旁观者，替读者说出心里话',
            'sentence_rhythm' => '短句为主，长句铺垫、短句抖包袱；段落偏短',
            'dialogue_share' => '35%–55%',
            'humor_level' => 'high',
            'humor_devices' => ['反差', '吐槽', '误会', '夸张', '一本正经地胡说'],
            'emotion_expression' => '外放，情绪写在脸上和嘴上',
            'vocabulary' => '口语、俏皮话、生活化的比喻；不堆网络梗',
            'banned_words' => ['空气凝固', '嘴角上扬', '眼底闪过'],
            'samples' => [
                '王掌柜的算盘打得噼啪响，响到一半停了。他抬头看看林小满，又低头看看账，像是头一回发现这本账会咬人。',
                '"我这人最讲道理。"林小满把凳子往门口一横，"所以今天这道理，您不讲完别想走。"',
            ],
            'draft_fragment' => '',
        ]],
        'rigorous' => ['严谨冷峻', '冷静旁观，不评价只呈现，关键处一刀见血', [
            'narrator_attitude' => '冷静的旁观者，不评价，只呈现',
            'sentence_rhythm' => '句子干净利落，少修饰；关键处用短句收住',
            'dialogue_share' => '25%–40%',
            'humor_level' => 'low',
            'humor_devices' => ['冷幽默'],
            'emotion_expression' => '克制，情绪藏在动作和选择里，但关键时刻必须爆发一次',
            'vocabulary' => '书面但不文绉绉，名词准确，动词有力',
            'banned_words' => ['空气凝固', '心里一沉', '深吸一口气'],
            'samples' => [
                '他没进门。账本摊在门槛上，一页一页翻过去。翻到第三页，对面那人的手开始抖。',
                '"你可以不认。"她把茶杯放下，"账在我这儿，人证在后院，你认不认，都一样。"',
            ],
            'draft_fragment' => '',
        ]],
        'colloquial' => ['生活口语 · 泼辣', '烟火气十足，吵架响亮、哭笑痛快', [
            'narrator_attitude' => '邻居大姐讲故事，带着烟火气和态度',
            'sentence_rhythm' => '短句和口语句式为主，长短交错；少用书面连接词',
            'dialogue_share' => '40%–55%',
            'humor_level' => 'mid',
            'humor_devices' => ['俏皮话', '斗嘴', '夸张'],
            'emotion_expression' => '外放，吵架要吵得响亮，哭笑都痛快',
            'vocabulary' => '市井口语，方言词适量；生活物件写具体',
            'banned_words' => ['空气凝固', '嘴角上扬', '指尖'],
            'samples' => [
                '"三婶，这钱您揣兜里一年了，兜不嫌沉，我都替它嫌闷。"她往门框上一靠，瓜子壳吐得又准又脆。',
                '院里一下子炸了锅。二大娘手里的鞋底子都忘了纳，伸长脖子往这边瞅，生怕漏了一句。',
            ],
            'draft_fragment' => '',
        ]],
        'passionate' => ['热血燃', '节奏紧，蓄力到位，爆发时一口气推到顶', [
            'narrator_attitude' => '热血的说书人，节奏紧，关键时刻推一把',
            'sentence_rhythm' => '动作场面短句连发，蓄力段稍长；高潮段一气呵成',
            'dialogue_share' => '30%–45%',
            'humor_level' => 'low',
            'humor_devices' => ['嘴硬反差'],
            'emotion_expression' => '外放，愤怒和斗志写足',
            'vocabulary' => '动词密集、画面感强；招式和力量要写出效果，不只报名字',
            'banned_words' => ['空气凝固', '眼底闪过'],
            'samples' => [
                '第三拳落下去的时候，擂台边的木栏先裂了。看台上没人说话，所有人都在等那个瘦小的身影倒下，可他偏偏又站了起来。',
                '"就这？"少年抹掉嘴角的血，往前踏了一步，"再来。"',
            ],
            'draft_fragment' => '',
        ]],
        'sweet' => ['轻松甜宠', '轻快温暖，心动藏在小细节里', [
            'narrator_attitude' => '温柔带笑的旁观者',
            'sentence_rhythm' => '轻快，段落短，节奏松弛',
            'dialogue_share' => '40%–60%',
            'humor_level' => 'mid',
            'humor_devices' => ['误会', '反差', '斗嘴'],
            'emotion_expression' => '外放而轻，心动写在小动作和小细节里',
            'vocabulary' => '日常、温暖；避免油腻台词和霸总腔',
            'banned_words' => ['嘴角上扬', '邪魅', '女人，你成功引起了我的注意'],
            'samples' => [
                '他把伞往她那边又偏了偏，自己半边肩膀湿透了，嘴上还在嫌她走得慢。',
                '"我没等你。"他说。可桌上那碗面，明明是刚端上来的，还冒着热气。',
            ],
            'draft_fragment' => '',
        ]],
        'classical' => ['古风雅致', '沉稳带古意，含蓄而有锋芒', [
            'narrator_attitude' => '沉稳的说书人，带一点古意',
            'sentence_rhythm' => '长短句相间，偶用对仗，不堆辞藻',
            'dialogue_share' => '30%–45%',
            'humor_level' => 'low',
            'humor_devices' => ['机锋', '冷幽默'],
            'emotion_expression' => '含蓄但不压抑，关键处一句话见情绪',
            'vocabulary' => '半文半白，称谓、器物有古风；不用现代词',
            'banned_words' => ['空气凝固', 'OK', '搞定'],
            'samples' => [
                '檐下的雨落了一夜，晨起时阶前积了薄薄一层青苔。她撑伞出门，裙角沾了水，也不在意。',
                '"大人既说下官越矩，"他拱手，"那这桩案子，便请大人亲自来审。"',
            ],
            'draft_fragment' => '',
        ]],
    ];

    public function up(): void
    {
        Schema::create('story_presets', function (Blueprint $table): void {
            $table->comment('题材包与文风预设');
            $table->id()->comment('主键');
            $table->string('kind', 16)->comment('类别：genre题材包，style文风预设');
            $table->string('key', 64)->comment('同类内唯一标识');
            $table->string('name', 64)->comment('名称');
            $table->string('description', 255)->nullable()->comment('一句话说明');
            $table->jsonb('content')->comment('结构化内容');
            $table->boolean('is_builtin')->default(false)->comment('是否内置，内置项只能停用不能删除');
            $table->unsignedInteger('version')->default(1)->comment('版本号，每次修改加一');
            $table->timestampTz('disabled_at')->nullable()->comment('停用时间');
            $table->timestampsTz();
            $table->unique(['kind', 'key']);
        });
        $now = now();
        $rows = [];
        foreach (['genre' => self::GENRES, 'style' => self::STYLES] as $kind => $presets) {
            foreach ($presets as $key => [$name, $description, $content]) {
                $rows[] = ['kind' => $kind, 'key' => $key, 'name' => $name, 'description' => $description, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'is_builtin' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('story_presets')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('story_presets');
    }
};
