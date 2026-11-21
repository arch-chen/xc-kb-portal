<?php
declare(strict_types=1);

/**
 * OpenAPI 3.0 描述文件，给 ChatGPT 的 GPT Actions 用。
 *
 * 用法：在自定义 GPT 的 Action 里选「Import from URL」，填
 *   https://你的域名/openapi.php
 * 认证选 API Key → Auth Type: Bearer → 粘贴 kb_ 开头的 Key。
 *
 * 默认 mode=query，servers.url 为 https://域名/api.php?path=/v1，
 * 这样拼接后正好是 api.php?path=/v1/search，任何服务器都能用，不依赖伪静态。
 * 如果你的站点开了 rewrite，也可以 ?mode=path 走干净的 /api/v1/search。
 */

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '系统尚未安装']);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

$base = base_url();
$mode = (($_GET['mode'] ?? 'query') === 'path') ? 'path' : 'query';
$prefix = $mode === 'path' ? '/api/v1' : '';
$serverUrl = $mode === 'path' ? $base : $base . '/api.php?path=/v1';

$okSchema = [
    'type'       => 'object',
    'properties' => [
        'ok'   => ['type' => 'boolean'],
        'data' => ['type' => 'object', 'description' => '具体结果，结构见上方说明'],
        'meta' => ['type' => 'object', 'additionalProperties' => true],
    ],
];

$json = 'application/json';
$ok = ['description' => '成功', 'content' => [$json => ['schema' => $okSchema]]];

$paths = [];

// ---- 搜索 ----
$paths[$prefix . '/search'] = [
    'get' => [
        'operationId' => 'searchKnowledge',
        'summary'     => '搜索知识库',
        'description' => 'Use this when 用户问到任何业务问题、规则、话术、操作步骤、历史结论。中文全文检索，返回匹配条目（含 id、问题、答案摘要、来源日期）。先用它查资料，再基于返回内容回答，不要凭常识编造；没有命中就如实说知识库没有收录。',
        'parameters'  => [
            ['name' => 'q', 'in' => 'query', 'required' => true, 'description' => '检索词或一句自然语言问题',
                'schema' => ['type' => 'string']],
            ['name' => 'limit', 'in' => 'query', 'required' => false, 'description' => '返回条数，默认 10，最大 50',
                'schema' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50]],
            ['name' => 'kind', 'in' => 'query', 'required' => false, 'description' => '只查问答(qa)或文档(doc)，不填则都查',
                'schema' => ['type' => 'string', 'enum' => ['qa', 'doc']]],
        ],
        'responses' => ['200' => $ok],
    ],
    'post' => [
        'operationId' => 'searchKnowledgePost',
        'summary'     => '搜索知识库（POST，长问题用）',
        'description' => '和 searchKnowledge 等价，但检索词放在 JSON body 里。问题较长、含换行或特殊符号时用它，避免 URL 编码问题。',
        'requestBody' => [
            'required' => true,
            'content'  => [$json => ['schema' => [
                'type'       => 'object',
                'required'   => ['q'],
                'properties' => [
                    'q'     => ['type' => 'string', 'description' => '检索词或自然语言问题'],
                    'limit' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
                    'kind'  => ['type' => 'string', 'enum' => ['qa', 'doc']],
                ],
            ]]],
        ],
        'responses' => ['200' => $ok],
    ],
];

// ---- 条目列表 ----
$paths[$prefix . '/entries'] = [
    'get' => [
        'operationId' => 'listEntries',
        'summary'     => '浏览知识库条目',
        'description' => 'Use this when 用户想看这个库最近有什么、或者要做整体梳理。按更新时间倒序分页返回，不含正文全文的检索排序。',
        'parameters'  => [
            ['name' => 'limit', 'in' => 'query', 'required' => false, 'description' => '每页条数，默认 20，最大 100',
                'schema' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100]],
            ['name' => 'offset', 'in' => 'query', 'required' => false, 'description' => '偏移量',
                'schema' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]],
            ['name' => 'kind', 'in' => 'query', 'required' => false, 'description' => '只看问答或只看文档',
                'schema' => ['type' => 'string', 'enum' => ['qa', 'doc']]],
        ],
        'responses' => ['200' => $ok],
    ],
];

// ---- 写入 ----
$paths[$prefix . '/entries']['post'] = [
    'operationId' => 'upsertEntry',
    'summary'     => '写入一条问答',
    'description' => 'Use this only when 用户明确要求「记下来 / 存进知识库 / 更新这条」。按问题内容去重：同一个问题已存在时更新答案并升版本，不会产生重复条目。需要该 Key 有写权限，只读 Key 会返回 403。不要自动写入。',
    'requestBody' => [
        'required' => true,
        'content'  => [$json => ['schema' => [
            'type'       => 'object',
            'required'   => ['question', 'body'],
            'properties' => [
                'kind'        => ['type' => 'string', 'enum' => ['qa', 'doc'], 'default' => 'qa'],
                'question'    => ['type' => 'string', 'description' => '问题，尽量用用户原话'],
                'body'        => ['type' => 'string', 'description' => '答案正文，支持 Markdown'],
                'title'       => ['type' => 'string', 'description' => '标题，可不填'],
                'source'      => ['type' => 'string', 'description' => '来源标注，例如 ChatGPT 对话'],
                'source_date' => ['type' => 'string', 'description' => '内容对应的日期，YYYY-MM-DD'],
            ],
        ]]],
    ],
    'responses' => [
        '200' => ['description' => '写入结果，data.created / updated / skipped 表示三态计数',
            'content' => [$json => ['schema' => $okSchema]]],
        '403' => ['description' => '该 Key 只读'],
    ],
];

// ---- 单条详情 ----
$paths[$prefix . '/entries/{id}'] = [
    'get' => [
        'operationId' => 'getEntry',
        'summary'     => '读取条目全文',
        'description' => 'Use this when 搜索结果被截断、或用户要求「把完整内容贴出来」。返回正文全文；文档类型还会带上关联的问答列表。',
        'parameters'  => [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => '条目 id',
                'schema' => ['type' => 'integer']],
        ],
        'responses' => [
            '200' => $ok,
            '404' => ['description' => '条目不存在'],
        ],
    ],
];

// ---- 增量 ----
$paths[$prefix . '/changes'] = [
    'get' => [
        'operationId' => 'listChanges',
        'summary'     => '拉取增量变更',
        'description' => 'Use this when 需要把最近新增或修改的内容同步进对话。传上次返回的 next_since，就能只拿到之后的变化（含删除标记）。',
        'parameters'  => [
            ['name' => 'since', 'in' => 'query', 'required' => false, 'description' => '上次的 next_since，形如 2026-11-20 10:00:00',
                'schema' => ['type' => 'string']],
            ['name' => 'limit', 'in' => 'query', 'required' => false, 'description' => '最多返回条数，默认 200，最大 500',
                'schema' => ['type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 500]],
        ],
        'responses' => ['200' => $ok],
    ],
];

// ---- 身份 ----
$paths[$prefix . '/me'] = [
    'get' => [
        'operationId' => 'whoAmI',
        'summary'     => '当前 Key 对应的知识库与权限',
        'description' => 'Use this first when 用户问「你连的是哪个知识库」「你能写吗」。返回绑定的知识库名称、条目数、读写权限和服务版本。',
        'responses' => ['200' => $ok],
    ],
];

$spec = [
    'openapi' => '3.0.3',
    'info'    => [
        'title'       => app_name() . ' 知识库接口',
        'version'     => APP_VERSION,
        'description' => '给 ChatGPT / 其他 Agent 读取（可选写入）知识库的接口。'
            . '一个 API Key 只对应一个知识库，读写权限在创建 Key 时勾选。',
    ],
    'servers' => [['url' => $serverUrl, 'description' => $mode === 'path' ? '伪静态模式' : '通用模式（不依赖 rewrite）']],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => '在「我的 API Key」页面复制的 kb_ 开头的 Key'],
        ],
    ],
    'security' => [['bearerAuth' => []]],
    'paths'    => $paths,
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
