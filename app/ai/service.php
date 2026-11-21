<?php
declare(strict_types=1);

/**
 * AI 业务场景：对话、答案优化、文本转 QA 草稿。
 *
 * 统一流程（谁都不许绕过）：选模型 → 权益校验 → 预扣积分 → 调网关 → 记日志 →（失败则退款）。
 * 结果一律「先给建议、由人确认后入库」，AI 不直接改知识库，避免污染版本记录。
 */

/** 可给这个用户用的模型：系统模型 + 自己的模型 */
function ai_models_for(int $uid): array
{
    return all(
        'SELECT * FROM ' . tbl('ai_models') . '
         WHERE enabled = 1 AND (COALESCE(owner_id, 0) = 0 OR owner_id = ?)
         ORDER BY owner_id, sort, id',
        [$uid]
    );
}

function ai_model_get(int $id): ?array
{
    return $id > 0 ? one('SELECT * FROM ' . tbl('ai_models') . ' WHERE id = ?', [$id]) : null;
}

/** 默认模型：系统里 sort 最小的那个 */
function ai_default_model_id(int $uid): int
{
    $rows = ai_models_for($uid);
    foreach ($rows as $r) {
        if ((int)$r['owner_id'] === 0) {
            return (int)$r['id'];
        }
    }
    return $rows ? (int)$rows[0]['id'] : 0;
}

function ai_log_call(int $uid, array $model, string $action, array $ctx, array $usage, int $credits, int $ms, string $status, string $error = ''): int
{
    return insert_row('ai_calls', [
        'user_id'    => $uid,
        'model_id'   => (int)$model['id'],
        'model_name' => mb_substr((string)$model['name'], 0, 60),
        'action'     => mb_substr($action, 0, 20),
        'group_id'   => (int)($ctx['group_id'] ?? 0),
        'entry_id'   => (int)($ctx['entry_id'] ?? 0),
        'tokens_in'  => (int)($usage['in'] ?? 0),
        'tokens_out' => (int)($usage['out'] ?? 0),
        'credits'    => $credits,
        'latency_ms' => $ms,
        'status'     => $status,
        'error'      => $error !== '' ? mb_substr($error, 0, 240) : null,
        'created_at' => now_str(),
    ]);
}

/**
 * 统一执行一次 AI 调用（含计费、日志、失败退款）。
 * 返回 ['text','usage','credits','balance','model','latency_ms','call_id']
 */
function ai_run(int $uid, int $modelId, string $action, array $messages, array $ctx = [], array $opts = []): array
{
    if (!ai_on()) {
        throw new AiError('disabled', 'AI 功能已被管理员关闭');
    }
    $user = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ? AND status = 1', [$uid]);
    if (!$user) {
        throw new AiError('forbidden', '用户不存在或已停用');
    }
    // 回答前门禁（2.2.5）：先判断所选模型是「积分扣除」还是「自定义接入」，
    // 连「没选模型 / 模型不存在」也在这里给出可操作的引导卡片。
    // 不通过就直接交回会话窗口——不预扣、不调用、不记失败日志；
    // 这一步只做只读判断，真正的扣费 / 退款仍在下面，铁律不变。
    $guide = ai_preflight($uid, $user, $modelId);
    if ($guide !== null) {
        $kind = match ($guide['code']) {
            'insufficient'      => 'insufficient',
            'no_model', 'bad_config' => 'bad_config',
            default             => 'forbidden',
        };
        throw new AiError($kind, $guide['title'] . '：' . $guide['message'], 0, $guide);
    }

    $model = ai_model_get($modelId);
    if (!$model) {
        throw new AiError('bad_config', '模型不存在，请重新选择');
    }

    $ent = ai_entitlement($uid);
    $chk = ai_can_use_model($user, $model, $ent);
    if (!$chk['ok']) {
        $kind = str_contains($chk['reason'], '积分不足') ? 'insufficient' : 'forbidden';
        $g = ai_preflight($uid, $user, $modelId) ?? [];
        throw new AiError($kind, $chk['reason'], 0, $g);
    }

    $price = $chk['free'] ? 0 : (int)$model['credits'];
    if ($price > 0) {
        $hold = ai_credit_move($uid, -$price, 'ai_call', [
            'model_id' => (int)$model['id'], 'ref_type' => 'action', 'note' => 'AI：' . $action,
        ]);
        if (!$hold['ok']) {
            $g = ai_preflight($uid, $user, $modelId) ?? [];
            throw new AiError('insufficient', $hold['error'], 0, $g);
        }
    }

    $t0 = microtime(true);
    try {
        $out = ai_chat($model, $messages, $opts);
    } catch (Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($price > 0) {
            ai_credit_move($uid, $price, 'refund', [
                'model_id' => (int)$model['id'], 'note' => '调用失败退回：' . $action,
            ]);
        }
        ai_log_call($uid, $model, $action, $ctx, ['in' => 0, 'out' => 0], 0, $ms, 'fail', $e->getMessage());
        // 模型真的调了但没成功：把「接口为什么不通」翻成人话 + 排查入口，一并交给会话窗口。
        if ($e instanceof AiError && $e->guide === []) {
            $e->withGuide(ai_guide_from_error($e, $model, $user));
        }
        throw $e;
    }

    $ms = (int)round((microtime(true) - $t0) * 1000);
    $callId = ai_log_call($uid, $model, $action, $ctx, $out['usage'], $price, (int)($out['latency_ms'] ?? $ms), 'ok');

    return [
        'text'       => (string)$out['text'],
        'usage'      => $out['usage'],
        'credits'    => $price,
        'balance'    => ai_balance($uid),
        'model'      => ai_model_public($model),
        'latency_ms' => (int)($out['latency_ms'] ?? $ms),
        'call_id'    => $callId,
    ];
}

/* ---------------- 提示词（可在后台 settings 覆盖） ---------------- */

function ai_prompt(string $code): array
{
    $defaults = [
        'chat' => [
            'system' => '你是跨境电商（Wayfair 平台）运营助手，服务对象是新手卖家。'
                . '回答要具体、可执行，给数字和步骤；不确定的地方明确说「需要确认」，不要编造平台政策。'
                . '涉及费用、时效、政策时提醒对方以平台后台为准。',
            'user' => '',
        ],
        'polish' => [
            'system' => '你是 Wayfair 群知识库的资深编辑。任务是把群里口语化、碎片化的问答，'
                . '整理成准确、可复用的标准答案。要求：\n'
                . '1. 不改变原意，不新增没有依据的信息；原文缺失的关键参数用「（待补充：…）」标出。\n'
                . '2. 结论先行，再给步骤或原因；能分点时用 Markdown 列表。\n'
                . '3. 保留具体的数字、时效、政策名称；删掉语气词、寒暄、重复。\n'
                . '4. 严格按这三段输出，不要加别的小标题：\n'
                . '## 优化后答案\n## 改动说明\n## 仍需确认',
            'user' => '',
        ],
        'extract' => [
            'system' => '你是知识库录入助手。从给定的群聊片段里，抽出可以独立成条的问答对。'
                . '规则：\n'
                . '1. 只抽「一个问题 + 一个明确回答」，纯提问没回答的不要抽，闲聊不要抽。\n'
                . '2. 问题改写成完整、可检索的一句话（不要「这个」「那个」这类指代）；回答保留原有关键信息与数字。\n'
                . '3. 原文没有的信息绝不补充。\n'
                . '4. 段落后提到的问题若回答在前文，也算一对。\n'
                . '5. 只输出 JSON，不要任何解释文字，格式：'
                . '{"items":[{"question":"...","answer":"..."}]}',
            'user' => '',
        ],
    ];
    $d = $defaults[$code] ?? ['system' => '', 'user' => ''];
    $sys = (string)setting_get('ai_prompt_' . $code . '_system', '');
    if (trim($sys) !== '') {
        $d['system'] = $sys;
    }
    // 对话场景始终带边界：自定义人设也甩不掉，避免它变成通用聊天机器人
    if ($code === 'chat') {
        $d['system'] .= "\n\n" . ai_chat_boundary();
    }
    return $d;
}

/**
 * 对话场景的回答边界。
 * 这个页面没有工具，所以只讲系统用法 + 知识库内容两类，其余礼貌拒绝。
 */
function ai_chat_boundary(): string
{
    return "【回答范围 · 硬边界】\n"
        . "只回答两类问题：\n"
        . "A. 本知识库系统的用法：怎么检索、建分组、写入、导入导出、配权限、用 API / MCP、积分与套餐怎么算、支付与退款怎么处理。\n"
        . "B. 知识库里已有的内容（下面会给你检索结果）。\n"
        . "其他话题（写代码、算数学题、翻译、写文案、追新闻、闲聊、别的平台怎么用、医疗法律投资建议）一律不展开："
        . "说明「这里只处理知识库相关的事」，给两三个能做的例子，然后结束。别先答完再补一句不该答。\n\n"
        . "【业务问题只能依据检索结果】\n"
        . "只要问题涉及具体业务知识、平台政策、数字、时效，就只能用下面检索到的原文作答。"
        . "不许用你自己的训练知识补答案，也不许编平台规则。检索结果为空或不够，就直说「知识库里还没有这方面的内容」，"
        . "并说明查了什么关键词、建议采集哪个页面补进来。\n"
        . "引用时写清分组名和条目 id；多条内容互相冲突就把差异摆出来，不要自己选一个。\n"
        . "用户问到他没有读权限的分组，直接说没有权限，不要猜。\n\n"
        . "【讲系统能力别臆造】\n"
        . "积分单价、模型价格、每日次数、限额这类会变的数字不要猜，让用户去「充值 / 套餐」「我的模型」页看或问管理员。";
}

/**
 * 给对话场景做一次知识库检索，拼成提示词块。
 * 检索范围 = 当前账号能读的全部分组（自己的 + 别人授权的），这决定了它到底能答哪些内容。
 *
 * @return array{block:string,groups:int,hits:int}
 */
function ai_chat_context(int $uid, string $query, int $limit = 5): array
{
    $gids = [];
    $names = [];
    foreach (visible_groups($uid) as $g) {
        $gids[] = (int)$g['id'];
        $names[(int)$g['id']] = (string)$g['name'];
    }
    if (!$gids) {
        return ['block' => "【知识库检索结果】当前账号没有任何可读分组，检索范围为空。涉及知识库内容的问题一律回答「你的账号还没有可读的知识库分组」。", 'groups' => 0, 'hits' => 0];
    }

    $q = trim($query);
    $items = [];
    if ($q !== '') {
        $res = search_entries($q, $gids, '', max(1, min($limit, 8)), 0);
        foreach ((array)($res['items'] ?? []) as $it) {
            $e = (array)($it['entry'] ?? $it);
            if (!empty($e['id'])) {
                $items[] = $e;
            }
        }
    }

    $head = '【知识库检索结果】检索范围：当前账号可读的 ' . count($gids) . ' 个分组（含他人授权给你的）。'
        . '关键词「' . mb_substr($q, 0, 60, 'UTF-8') . '」命中 ' . count($items) . ' 条。';
    if (!$items) {
        return [
            'block' => $head . "\n没有任何命中。涉及具体业务知识时，直接说知识库里没有，不要用你自己的知识补答。",
            'groups' => count($gids),
            'hits'   => 0,
        ];
    }

    $lines = [$head, '下面是原文节选，只能据此回答；没写到的内容就说「知识库里没有」，不要推断。'];
    foreach ($items as $i => $e) {
        $title = trim((string)($e['question'] ?? ''));
        if ($title === '') {
            $title = (string)($e['title'] ?? '');
        }
        $body = preg_replace('/\s+/u', ' ', (string)($e['body'] ?? '')) ?? '';
        $lines[] = '--- [' . ($i + 1) . '] 条目 #' . (int)$e['id']
            . ' ｜ 分组：' . ($names[(int)($e['group_id'] ?? 0)] ?? '未知')
            . "\n问题：" . mb_substr($title, 0, 200, 'UTF-8')
            . "\n正文：" . mb_substr($body, 0, 900, 'UTF-8');
    }
    return ['block' => implode("\n\n", $lines), 'groups' => count($gids), 'hits' => count($items)];
}

/** 输入长度护栏 */
function ai_check_input(string $text, string $what = '内容'): string
{
    $text = trim($text);
    if ($text === '') {
        throw new AiError('bad_config', $what . '不能为空');
    }
    $max = ai_max_input();
    if (mb_strlen($text) > $max) {
        throw new AiError('bad_config', $what . '太长了（' . mb_strlen($text) . ' 字），单次上限 ' . $max . ' 字，请分段处理');
    }
    return $text;
}

/* ---------------- 三个场景 ---------------- */

/** 通用对话（先检索知识库，再让模型基于检索结果回答） */
function ai_chat_flow(int $uid, int $modelId, string $text, array $history = [], array $ctx = []): array
{
    $p = ai_prompt('chat');
    $messages = [['role' => 'system', 'content' => $p['system']]];
    $count = 0;
    foreach ($history as $h) {
        if ($count >= 10) {
            break;
        }
        $role = (string)($h['role'] ?? 'user');
        $c = trim((string)($h['content'] ?? ''));
        if ($c === '' || !in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        // 上一轮的检索块不用再带给模型：只留用户真正问的那句，省 token 也免得新旧结果打架
        if (str_contains($c, '【知识库检索结果】')) {
            $pos = strpos($c, '【用户问题】');
            if ($pos !== false) {
                $c = trim(substr($c, $pos + strlen('【用户问题】')));
            }
        }
        $messages[] = ['role' => $role, 'content' => mb_substr($c, 0, 4000)];
        $count++;
    }

    $question = ai_check_input($text, '问题');
    $kb = ai_chat_context($uid, $question, 5);
    $user = $kb['block'] . "\n\n【用户问题】\n" . $question;
    if ($kb['hits'] === 0) {
        $user .= "\n\n（提示：这次检索没有命中。若问题涉及具体业务知识，就直说知识库里没有；若问的是本系统用法，正常回答。）";
    }
    $messages[] = ['role' => 'user', 'content' => $user];

    $out = ai_run($uid, $modelId, 'chat', $messages, $ctx);
    $out['kb'] = ['groups' => $kb['groups'], 'hits' => $kb['hits']];
    return $out;
}

/**
 * 优化一条答案。
 * $in: ['entry_id'=>int, 'text'=>string] 二选一，entry_id 优先。
 */
function ai_polish_answer(int $uid, int $modelId, array $in): array
{
    $entryId = (int)($in['entry_id'] ?? 0);
    $question = trim((string)($in['question'] ?? ''));
    $answer = trim((string)($in['text'] ?? ''));
    $ctx = ['entry_id' => $entryId];

    if ($entryId > 0) {
        $e = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ? AND status = 1', [$entryId]);
        if (!$e) {
            throw new AiError('bad_config', '条目不存在或已删除');
        }
        if (!can_read_group($uid, (int)$e['group_id'])) {
            throw new AiError('forbidden', '你没有这个分组的权限');
        }
        $ctx['group_id'] = (int)$e['group_id'];
        $question = (string)$e['question'];
        $answer = (string)$e['body'];
    }

    $answer = ai_check_input($answer, '答案原文');
    $p = ai_prompt('polish');
    $user = "问题：\n" . ($question !== '' ? $question : '（无）') . "\n\n答案原文：\n" . $answer;
    $out = ai_run($uid, $modelId, 'polish', [
        ['role' => 'system', 'content' => $p['system']],
        ['role' => 'user', 'content' => $user],
    ], $ctx, ['max_tokens' => 3000]);

    $out['input'] = ['question' => $question, 'answer' => $answer];
    return $out;
}

/**
 * 群聊片段 → QA 草稿。
 * $in: ['text'=>string, 'max'=>int]
 */
function ai_extract_qa(int $uid, int $modelId, array $in): array
{
    $text = ai_check_input((string)($in['text'] ?? ''), '群聊内容');
    $max = (int)($in['max'] ?? 8);
    $max = $max > 0 ? min($max, 20) : 8;
    $p = ai_prompt('extract');
    $out = ai_run($uid, $modelId, 'extract', [
        ['role' => 'system', 'content' => $p['system']],
        ['role' => 'user', 'content' => "最多抽 {$max} 条。群聊片段：\n\n" . $text],
    ], [], ['max_tokens' => 4000, 'temperature' => 0.1]);

    $data = ai_extract_json($out['text']);
    $items = [];
    foreach ((array)($data['items'] ?? []) as $it) {
        $q = trim((string)($it['question'] ?? ''));
        $a = trim((string)($it['answer'] ?? ''));
        if ($q === '' || $a === '') {
            continue;
        }
        $items[] = ['question' => mb_substr($q, 0, 500), 'answer' => $a];
        if (count($items) >= $max) {
            break;
        }
    }
    $out['items'] = $items;
    $out['parse_ok'] = $items !== [];
    return $out;
}
