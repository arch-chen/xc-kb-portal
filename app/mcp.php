<?php
declare(strict_types=1);

/**
 * MCP（Model Context Protocol）服务端。
 * 传输：Streamable HTTP 的「无状态」用法——每个 POST 一条 JSON-RPC，直接回 JSON。
 * 鉴权：沿用 REST 的 Authorization: Bearer <API Key>，权限口径与 key_caps() 完全一致。
 *
 * 为什么不做 session：工具调用都是即时的，不需要 SSE 长连接；
 * 无状态实现能被任何反向代理/负载均衡正确处理，也省掉一堆会话过期问题。
 */

const MCP_PROTOCOL_FALLBACK = '2025-06-18';

function mcp_respond($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_result($id, array $result): void
{
    mcp_respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

function mcp_error($id, int $code, string $message, $data = null): void
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    mcp_respond(['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]);
}

/** 工具执行结果：成功给文本，失败给 isError（让模型自己转述原因，而不是整个请求失败） */
function mcp_tool_ok(string $text): void
{
    mcp_result(mcp_current_id(), ['content' => [['type' => 'text', 'text' => $text]]]);
}

function mcp_tool_fail(string $text): void
{
    mcp_result(mcp_current_id(), [
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => true,
    ]);
}

function mcp_current_id()
{
    return $GLOBALS['__mcp_id'] ?? null;
}

/** 把一条 entry 渲染成模型好读的 Markdown 片段 */
function mcp_render_entry(array $r, int $bodyLimit = 1200): string
{
    $kind = $r['kind'] === 'doc' ? '文档' : '问答';
    $title = trim((string)$r['title']) !== '' ? $r['title'] : mb_substr((string)$r['question'], 0, 60, 'UTF-8');
    $out = '### [id:' . (int)$r['id'] . '] ' . $title . "\n";
    $meta = [$kind, 'v' . (int)$r['version']];
    if (!empty($r['source_date'])) {
        $meta[] = (string)$r['source_date'];
    }
    if (!empty($r['updated_at'])) {
        $meta[] = '更新于 ' . substr((string)$r['updated_at'], 0, 10);
    }
    $out .= '_' . implode(' · ', $meta) . "_\n\n";
    if ($r['kind'] !== 'doc' && trim((string)$r['question']) !== '') {
        $out .= '**问：** ' . trim((string)$r['question']) . "\n\n";
    }
    $body = trim((string)$r['body']);
    if ($body !== '') {
        if (mb_strlen($body, 'UTF-8') > $bodyLimit) {
            $body = mb_substr($body, 0, $bodyLimit, 'UTF-8') . '…（已截断，用 kb_get 取全文）';
        }
        $out .= $body . "\n";
    }
    return $out;
}

function mcp_tools(): array
{
    $ro = ['readOnlyHint' => true, 'openWorldHint' => false, 'destructiveHint' => false];
    return [
        [
            'name'        => 'kb_search',
            'title'       => '搜索知识库',
            'description' => '在绑定的知识库里做中文全文检索。用户问到任何业务问题、规则、话术、操作步骤时，先用这个工具查资料，再基于返回内容回答；不要凭常识编。返回条目带 [id:数字]，需要全文就用 kb_get。',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => '检索词或一句自然语言问题'],
                    'limit' => ['type' => 'integer', 'description' => '返回条数，1~20，默认 8', 'minimum' => 1, 'maximum' => 20, 'default' => 8],
                    'kind'  => ['type' => 'string', 'enum' => ['qa', 'doc'], 'description' => '只查问答(qa)或只查文档(doc)，不填则都查'],
                ],
                'required' => ['query'],
            ],
            'annotations' => $ro,
        ],
        [
            'name'        => 'kb_get',
            'title'       => '读取条目全文',
            'description' => '按 id 取一条问答或文档的完整正文（搜索结果被截断、或用户要求「完整贴出来」时用）。',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '条目 id，来自 kb_search 或 kb_changes 的返回值'],
                ],
                'required' => ['id'],
            ],
            'annotations' => $ro,
        ],
        [
            'name'        => 'kb_changes',
            'title'       => '拉取增量变更',
            'description' => '按时间增量拉取本知识库的改动（含删除标记），用于把最新内容同步到对话上下文。第一次调用不传 since，之后把返回的 next_since 传回来即可。',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'since' => ['type' => 'string', 'description' => '上次返回的 next_since，形如 2026-11-20 10:00:00；不填表示从头拉'],
                    'limit' => ['type' => 'integer', 'description' => '最多返回条数，1~200，默认 100', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
                ],
            ],
            'annotations' => $ro,
        ],
        [
            'name'        => 'kb_info',
            'title'       => '查看当前知识库',
            'description' => '查看这个 Key 绑定的是哪个知识库、有多少条内容、是否具备写权限。用户问「你现在连的是哪个库」时用。',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            'annotations' => $ro,
        ],
        [
            'name'        => 'kb_upsert_qa',
            'title'       => '写入一条问答',
            'description' => '把用户确认过的结论存进知识库。按问题内容去重：同一个问题已存在时更新答案并升版本，不会产生重复条目。需要该 Key 有写权限。只在用户明确说「记下来 / 存进知识库」时调用，不要自动写入。',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => '问题，尽量用用户的原话'],
                    'body'     => ['type' => 'string', 'description' => '答案正文，支持 Markdown'],
                    'title'    => ['type' => 'string', 'description' => '标题，可不填'],
                    'source'   => ['type' => 'string', 'description' => '来源标注，例如「ChatGPT 对话」「客服群」'],
                ],
                'required' => ['question', 'body'],
            ],
            'annotations' => ['readOnlyHint' => false, 'openWorldHint' => false, 'destructiveHint' => false],
        ],
        [
            'name'        => 'kb_ai_ask',
            'title'       => '用知识库 AI 回答',
            'description' => '调用本站配置的大模型回答问题（会消耗该用户的 AI 积分，按次计费）。'
                . '和 kb_search 的区别：kb_search 只返回库里的原文，这个会真的调用模型来组织答案。'
                . '只在用户明确要求「用站里的 AI 回答 / 帮我润色思考」时用，普通查资料请用 kb_search。',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'text'     => ['type' => 'string', 'description' => '要问的问题'],
                    'model_id' => ['type' => 'integer', 'description' => '可选，不填用系统默认模型；可用 id 见 kb_ai_models'],
                ],
                'required' => ['text'],
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => true, 'destructiveHint' => false],
        ],
        [
            'name'        => 'kb_ai_polish',
            'title'       => '优化一条答案',
            'description' => '让模型把某条问答的答案整理成标准写法，返回建议文本（不会自动写回，需人工确认）。'
                . '同样按次消耗 AI 积分。',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'entry_id' => ['type' => 'integer', 'description' => '要优化的条目 id'],
                    'text'     => ['type' => 'string', 'description' => '也可以直接给一段原文，和 entry_id 二选一'],
                    'model_id' => ['type' => 'integer', 'description' => '可选，不填用默认模型'],
                ],
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => true, 'destructiveHint' => false],
        ],
    ];
}

function mcp_call_tool(string $name, array $args, array $caps, array $user): void
{
    $gid = (int)$caps['group_id'];

    if ($name === 'kb_info') {
        $g = group_by_id($gid);
        $text = "知识库：" . ($g['name'] ?? '（不存在）') . "\n"
            . '条目数：' . ($g ? group_entry_count($gid) : 0) . "\n"
            . '当前 Key 权限：' . ($caps['read'] ? '读' : '') . ($caps['read'] && $caps['write'] ? ' + ' : '')
            . ($caps['write'] ? '写' : '') . "\n"
            . '服务版本：' . APP_VERSION;
        mcp_tool_ok($text);
    }

    if ($name === 'kb_search') {
        if (!$caps['read']) {
            mcp_tool_fail('这个 Key 没有读权限，无法检索。');
        }
        $q = trim((string)($args['query'] ?? ''));
        if ($q === '') {
            mcp_tool_fail('query 不能为空。');
        }
        $limit = (int)($args['limit'] ?? 8);
        $limit = max(1, min(20, $limit));
        $kindRaw = (string)($args['kind'] ?? '');
        $kind = in_array($kindRaw, ['qa', 'doc'], true) ? $kindRaw : '';
        $res = search_entries($q, [$gid], $kind, $limit, 0);
        if (!$res['items']) {
            mcp_tool_ok('没有检索到相关内容。可以换个说法再试，或直接告诉用户「知识库里没有」。');
        }
        $out = '共命中 ' . (int)$res['total'] . " 条，显示前 " . count($res['items']) . " 条：\n\n";
        foreach ($res['items'] as $it) {
            $out .= mcp_render_entry($it['entry'], 600) . "\n";
        }
        mcp_tool_ok($out);
    }

    if ($name === 'kb_get') {
        if (!$caps['read']) {
            mcp_tool_fail('这个 Key 没有读权限。');
        }
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            mcp_tool_fail('id 必须是正整数。');
        }
        $row = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ? AND status = 1', [$id]);
        if (!$row) {
            mcp_tool_fail('id=' . $id . ' 的条目不存在或已删除。');
        }
        if ((int)$row['group_id'] !== $gid) {
            mcp_tool_fail('id=' . $id . ' 不在当前 Key 绑定的知识库里。');
        }
        $text = mcp_render_entry($row, 100000);
        if ($row['kind'] === 'doc') {
            $links = doc_entries($id);
            if ($links) {
                $text .= "\n---\n关联问答：\n";
                foreach ($links as $l) {
                    $text .= '- [id:' . (int)$l['id'] . '] ' . trim((string)$l['question']) . "\n";
                }
            }
        }
        mcp_tool_ok($text);
    }

    if ($name === 'kb_changes') {
        if (!$caps['read']) {
            mcp_tool_fail('这个 Key 没有读权限。');
        }
        $since = trim((string)($args['since'] ?? ''));
        $limit = max(1, min(200, (int)($args['limit'] ?? 100)));
        $sql = 'SELECT * FROM ' . tbl('entries') . ' WHERE group_id = ?';
        $p = [$gid];
        if ($since !== '') {
            $sql .= ' AND updated_at > ?';
            $p[] = $since;
        }
        $rows = all($sql . ' ORDER BY updated_at ASC, id ASC LIMIT ' . $limit, $p);
        $last = $since;
        $lines = [];
        foreach ($rows as $r) {
            $last = (string)$r['updated_at'];
            $flag = (int)$r['status'] === 0 ? '（已删除）' : '';
            $lines[] = '- [id:' . (int)$r['id'] . '] ' . ($r['kind'] === 'doc' ? '文档' : '问答')
                . ' · v' . (int)$r['version'] . ' · ' . $r['updated_at'] . $flag
                . ' :: ' . mb_substr(trim((string)($r['title'] ?: $r['question'])), 0, 80, 'UTF-8');
        }
        $text = $rows
            ? ($since !== '' ? "自 " . $since . " 起，" : "从最早记录起，") . "共 " . count($rows) . " 条变更：\n\n"
                . implode("\n", $lines) . "\n\nnext_since: " . $last
            : '没有新的变更。' . ($since !== '' ? "\n\nnext_since: " . $since : '');
        mcp_tool_ok($text);
    }

    if ($name === 'kb_upsert_qa') {
        if (!$caps['write']) {
            mcp_tool_fail('这个 Key 是只读的，不能写入。需要写入请在「我的 API Key」里重建一个勾了「写入」的 Key。');
        }
        $question = trim((string)($args['question'] ?? ''));
        $body = (string)($args['body'] ?? '');
        if ($question === '' || trim($body) === '') {
            mcp_tool_fail('question 和 body 都不能为空。');
        }
        $it = [
            'kind'     => 'qa',
            'question' => $question,
            'body'     => $body,
            'title'    => trim((string)($args['title'] ?? '')),
            'source'   => trim((string)($args['source'] ?? '')) ?: 'MCP',
        ];
        $r = upsert_qa($gid, $it, (int)$user['id'], 'upsert');
        $label = ['created' => '已新增', 'updated' => '已更新', 'skipped' => '内容与原来一致，已跳过'][$r['action']] ?? $r['action'];
        audit('mcp_upsert_qa', 'id=' . $r['id'] . ' action=' . $r['action']);
        mcp_tool_ok($label . '：id=' . (int)$r['id'] . '，当前版本 v' . (int)$r['version'] . '。');
    }

    if ($name === 'kb_ai_ask' || $name === 'kb_ai_polish') {
        if (!ai_on()) {
            mcp_tool_fail('站点没有开启 AI 功能。');
        }
        $text = trim((string)($args['text'] ?? ''));
        $mid = (int)($args['model_id'] ?? 0);
        if ($mid <= 0) {
            $mid = ai_default_model_id((int)$user['id']);
        }
        if ($mid <= 0) {
            mcp_tool_fail('站点还没有配置任何 AI 模型，请让管理员先加一个。');
        }
        try {
            if ($name === 'kb_ai_ask') {
                if ($text === '') {
                    mcp_tool_fail('text 不能为空。');
                }
                $r = ai_chat_flow((int)$user['id'], $mid, $text);
                mcp_tool_ok($r['text'] . "\n\n---\n模型：" . $r['model']['name']
                    . '；本次消耗 ' . (int)$r['credits'] . ' 积分；余额 ' . (int)$r['balance']);
            }
            $r = ai_polish_answer((int)$user['id'], $mid, [
                'entry_id' => (int)($args['entry_id'] ?? 0),
                'text'     => $text,
            ]);
            mcp_tool_ok($r['text'] . "\n\n---\n模型：" . $r['model']['name']
                . '；本次消耗 ' . (int)$r['credits'] . ' 积分；余额 ' . (int)$r['balance']
                . "\n这只是建议，没有写回知识库；用户确认后可在网页或用 kb_upsert_qa 保存。");
        } catch (Throwable $e) {
            mcp_tool_fail('AI 调用失败：' . $e->getMessage());
        }
    }

    mcp_tool_fail('未知工具：' . $name);
}

function mcp_handle(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id, Accept');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Expose-Headers: Mcp-Session-Id');

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    // 规范里 GET 用于开 SSE 流；我们不提供流式，明确告知只接受 POST
    if ($method !== 'POST') {
        header('Allow: POST, OPTIONS');
        mcp_respond([
            'jsonrpc' => '2.0', 'id' => null,
            'error'   => ['code' => -32000, 'message' => 'MCP 端点只接受 POST（JSON-RPC 2.0）'],
        ], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        mcp_error(null, -32700, '请求体不是合法 JSON');
    }

    if (!mcp_enabled()) {
        mcp_error($payload['id'] ?? null, -32004, '本站未开放 MCP 接入，需要管理员在「系统与升级」里打开');
    }

    // 先鉴权：缺 Key 时给 401 + WWW-Authenticate，客户端才知道要带凭证
    $auth = api_auth();
    if (!$auth) {
        header('WWW-Authenticate: Bearer realm="kb-portal", error="invalid_token"');
        mcp_respond([
            'jsonrpc' => '2.0', 'id' => $payload['id'] ?? null,
            'error'   => ['code' => -32001, 'message' => '未授权：请在请求头带上 Authorization: Bearer <你的 API Key>'],
        ], 401);
    }
    [$key, $user] = $auth;
    $caps = key_caps($key, $user);
    if (!$caps['read'] && !$caps['write']) {
        mcp_error($payload['id'] ?? null, -32003, '该 API Key 已失去此分组的权限，请重新授权');
    }

    $batch = array_is_list($payload) ? $payload : [$payload];
    $out = [];
    foreach ($batch as $msg) {
        if (!is_array($msg) || !isset($msg['method'])) {
            $out[] = ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null,
                'error' => ['code' => -32600, 'message' => '无效请求']];
            continue;
        }
        $id = $msg['id'] ?? null;
        $rpcMethod = (string)$msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        // 通知（无 id）不需要回复
        if ($id === null && str_starts_with($rpcMethod, 'notifications/')) {
            continue;
        }
        $GLOBALS['__mcp_id'] = $id;

        switch ($rpcMethod) {
            case 'initialize':
                $pv = (string)($params['protocolVersion'] ?? '');
                $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'protocolVersion' => $pv !== '' ? $pv : MCP_PROTOCOL_FALLBACK,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'kb-portal', 'title' => app_name(), 'version' => APP_VERSION],
                    'instructions'    => '这是「' . app_name() . '」知识库。回答业务问题前先用 kb_search 检索，'
                        . '基于返回内容作答并注明 [id:x]；找不到就直说知识库没有，不要编。'
                        . ($caps['write'] ? '用户明确要求记录结论时，可用 kb_upsert_qa 写入。' : '当前为只读权限，不能写入。'),
                ]];
                break;

            case 'ping':
                $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object)[]];
                break;

            case 'tools/list':
                $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => mcp_tools()]];
                break;

            case 'tools/call':
                $name = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                // 工具内部用 mcp_result 直接落盘输出，所以这里捕获不到返回值
                mcp_call_tool($name, $args, $caps, $user);
                break;

            case 'resources/list':
                $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
                break;

            case 'prompts/list':
                $out[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
                break;

            default:
                $out[] = ['jsonrpc' => '2.0', 'id' => $id,
                    'error' => ['code' => -32601, 'message' => '不支持的方法：' . $rpcMethod]];
        }
    }

    if (!$out) {
        http_response_code(202);
        exit;
    }
    mcp_respond(count($out) === 1 && !array_is_list($payload) ? $out[0] : $out);
}
