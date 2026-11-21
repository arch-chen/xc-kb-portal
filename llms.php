<?php
declare(strict_types=1);

/**
 * /llms.txt —— 给 Agent 读的纯文本入口索引（llms.txt 约定）。
 * 只讲「有哪些入口、怎么鉴权、能做什么」，不铺细节；细节在 ?r=docs。
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=600');

if (!is_file(__DIR__ . '/config.php')) {
    echo "# XC知识库系统\n\n系统尚未安装，接口不可用。\n";
    exit;
}

require __DIR__ . '/app/bootstrap.php';

$base = base_url();
$api  = $base . '/api.php?path=';

$lines = [];
$lines[] = '# ' . app_name() . ' · Agent 接入说明';
$lines[] = '';
$lines[] = '站点: ' . $base;
$lines[] = '版本: ' . APP_VERSION;
$lines[] = '完整文档: ' . $base . '/index.php?r=docs';
$lines[] = '';
$lines[] = '## 这是什么';
$lines[] = '';
$lines[] = '中文知识库服务。一个 API Key 绑定一个分组（知识库），读、写分开授权，';
$lines[] = '请求体里写 group_id 无效——分组由 Key 决定，跨组返回 403。';
$lines[] = '';
$lines[] = '## 鉴权';
$lines[] = '';
$lines[] = 'Authorization: Bearer kb_xxxx      （或 X-Api-Key: kb_xxxx）';
$lines[] = 'Key 在站点「我的 API Key」页面创建，只在创建时显示一次。';
$lines[] = '';
$lines[] = '## REST（Base: ' . $api . '）';
$lines[] = '';
$lines[] = 'GET    /v1/health                     无需鉴权，健康检查';
$lines[] = 'GET    /v1/me                         当前 Key 身份、分组、权限、条目数';
$lines[] = 'GET    /v1/groups                     绑定的分组（只有一个）';
$lines[] = 'GET    /v1/search?q=&limit=&offset=&kind=     中文全文检索，limit 1~50 默认10';
$lines[] = 'POST   /v1/search                     同上的 JSON 版 {q,limit,offset,kind}，长问题推荐';
$lines[] = 'GET    /v1/entries?limit=&offset=&kind=       分页遍历，limit 1~100 默认20';
$lines[] = 'GET    /v1/entries/{id}               取全文；doc 额外返回 links';
$lines[] = 'PUT    /v1/entries/{id}               更新（每次升版本并快照旧值）';
$lines[] = 'DELETE /v1/entries/{id}               软删除';
$lines[] = 'GET    /v1/entries/{id}/versions      版本历史（含当前，is_current=true）';
$lines[] = 'GET    /v1/changes?since=&limit=      增量变更含删除标记，limit 1~500 默认200';
$lines[] = 'GET    /v1/export?after=&limit=&kind= 全量分页导出，按 id 升序，limit 1~500';
$lines[] = 'GET    /v1/docs/{id}                  文档视图：正文 + items（收录的问答）';
$lines[] = 'POST   /v1/entries                    幂等写入 {mode:"upsert"|"create", items:[...]} ≤200 条';
$lines[] = 'GET    /v1/attachments?entry_id=&limit=&offset=  文件列表，返回 download 与 markdown 引用';
$lines[] = 'POST   /v1/attachments                上传：multipart file/files[] 或 JSON {name,data(base64)}';
$lines[] = 'GET    /v1/attachments/{id}           文件元信息';
$lines[] = 'GET    /v1/attachments/{id}/download  取文件字节（同一个 Key 即可，无需签名 URL）';
$lines[] = '';
$lines[] = '条目字段: id, group_id, kind(qa|doc), title, question, body, source, source_date, version, updated_at, created_at';
$lines[] = '写入字段: kind, question, body, title, source, source_date；doc 更新必须带 id，否则会新增副本。';
$lines[] = '';
$lines[] = '## 响应';
$lines[] = '';
$lines[] = '成功: {"ok":true,"data":...,"meta":{...}}';
$lines[] = '失败: {"ok":false,"error":{"code":"unauthorized","message":"..."}}';
$lines[] = '错误码: unauthorized(401) forbidden(403) readonly(403) bad_request(400) not_found(404) server_error(500)';
$lines[] = '时间格式 YYYY-MM-DD HH:MM:SS，UTF-8。';
$lines[] = '';
$lines[] = '## MCP';
$lines[] = '';
$lines[] = '端点: ' . $base . '/mcp.php';
$lines[] = '协议: JSON-RPC 2.0 over HTTP，无状态 Streamable HTTP（每 POST 一条 JSON，回 JSON，无 SSE、无 session）。';
$lines[] = '只接受 POST；鉴权同样用 Bearer Key。';
$lines[] = '工具:';
$lines[] = '  kb_search(query, limit=8 [1~20], kind?)   检索，返回带 [id:数字] 的 Markdown';
$lines[] = '  kb_get(id)                                取全文';
$lines[] = '  kb_changes(since?, limit=100 [1~200])     增量变更';
$lines[] = '  kb_info()                                 当前库信息与权限';
$lines[] = '  kb_upsert_qa(question, body, title?, source?)  按问题去重写入（需写权限）';
$lines[] = '工具内部失败返回 {content:[...], isError:true}，不是 JSON-RPC error。';
$lines[] = '';
$lines[] = '## OpenAPI';
$lines[] = '';
$lines[] = '描述文件: ' . $base . '/openapi.php';
$lines[] = '加 ?mode=path 则输出 ' . $base . '/api/v1 形式（需服务器配 rewrite）。';
$lines[] = '';
$lines[] = '## 约定';
$lines[] = '';
$lines[] = '- 写入幂等：按问题指纹去重，答案没变返回 skipped，变了 updated 且 version+1。';
$lines[] = '- 增量同步：用 meta.next_since 作为下次 since，循环到 meta.has_more=false。';
$lines[] = '- 批量写入整批一个事务，失败整体回滚。';
$lines[] = '- 检索是中文二字组倒排，整句自然语言通常比两字关键词更准。';
$lines[] = '';

echo implode("\n", $lines);
