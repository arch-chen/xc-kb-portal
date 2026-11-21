<?php
/**
 * 导航分区与页内子菜单（2.1.0）
 *
 * 左侧栏只保留「主干」入口：分组、AI 工作台、账户设置、管理后台。
 * 账户相关的页（资料 / 账单 / API Key）收进「账户设置」页上方的横向子菜单；
 * 后台所有页收进「管理后台」页上方的横向子菜单。
 * 这样左侧不会越挂越长，同一个东西也不会出现两个入口。
 */

/** 路由属于哪个分区：'account' | 'admin' | '' */
function nav_section(string $route): string
{
    if (in_array($route, ['account', 'recharge', 'ai', 'ai_models', 'keys'], true)) {
        return 'account';
    }
    if (strncmp($route, 'admin', 5) === 0) {
        return 'admin';
    }
    return '';
}

/**
 * 分区的横向子菜单：['items' => [[key,label,url]], 'cur' => 当前项 key]
 */
function nav_sub_tabs(string $route): array
{
    $sec = nav_section($route);
    if ($sec === 'account') {
        // AI 助手 / 我的模型 是「账单」里的二级标签，归属也记在账单上
        $cur = in_array($route, ['recharge', 'ai', 'ai_models'], true) ? 'recharge' : $route;
        return ['cur' => $cur, 'items' => [
            ['account',  '账号资料',     u('account')],
            ['recharge', '账单',         u('recharge')],
            ['keys',     '我的 API Key', u('keys')],
        ]];
    }
    if ($sec === 'admin') {
        $items = [
            ['admin',          '概览',      u('admin')],
            ['admin_users',    '用户',      u('admin_users')],
            ['admin_groups',   '分组',      u('admin_groups')],
            ['admin_perm',     '授权',      u('admin_perm')],
            ['admin_content',  '内容',      u('admin_content')],
            ['admin_import',   '导入',      u('admin_import')],
            ['admin_keys',     'API Key',   u('admin_keys')],
            ['admin_files',    '文件存储',  u('admin_files')],
            ['admin_ai',       'AI 与套餐', u('admin_ai')],
            ['admin_pay',      '支付',      u('admin_pay')],
            ['admin_mail',     '邮件',      u('admin_mail')],
            ['admin_system',   '系统与升级', u('admin_system')],
        ];
        return ['cur' => $route, 'items' => $items];
    }
    return ['cur' => '', 'items' => []];
}

/** 渲染横向子菜单（没有子菜单时返回空串） */
function nav_subnav(string $route): string
{
    $t = nav_sub_tabs($route);
    if (!$t['items']) {
        return '';
    }
    $h = '<nav class="subnav" aria-label="页面切换">';
    foreach ($t['items'] as $it) {
        $on = $it[0] === $t['cur'] ? ' class="on"' : '';
        $h .= '<a' . $on . ' href="' . e($it[2]) . '">' . e($it[1]) . '</a>';
    }
    return $h . '</nav>';
}

/**
 * 账单内的二级标签（chips）：充值与订单 / AI 助手 / 我的模型
 * 「AI 助手」放在账单里，和充值、订单从同一个入口进来。
 */
function nav_bill_tabs(string $cur): string
{
    $items = [
        ['recharge',  '充值与订单', u('recharge')],
        ['ai',        'AI 助手',    u('ai')],
        ['ai_models', '我的模型',   u('ai_models')],
    ];
    $h = '<div class="chips">';
    foreach ($items as $it) {
        $on = $it[0] === $cur ? ' on' : '';
        $h .= '<a class="chip' . $on . '" href="' . e($it[2]) . '">' . e($it[1]) . '</a>';
    }
    return $h . '</div>';
}

/**
 * 全站常驻的「AI 工作台」会话侧栏（2.2.2）。
 *
 * 首页 / 分组页 / 工作台页左栏都同时显示「我的分组」和这块对话列表，
 * 但主区仍按路由各自渲染——只是菜单栏合并，页面本身不合并。
 * 会话数据在这里统一查，视图不再各自塞 $GLOBALS['side_panel']。
 * 工作台页由 agent.php 内嵌脚本接管交互；其它页面由 assets/agent-side.js 接管，
 * 所以「＋ 新对话 / 改名 / 删除」在知识库页面也能直接用。
 */
function nav_agent_side(int $uid, int $runId = 0): string
{
    if ($uid <= 0 || !ai_on()) {
        return '';
    }
    // 没有可用模型时不显示（与 AI 工作台页面保持一致，免得点了没反应）
    if (!ai_models_for($uid)) {
        return '';
    }
    $runs = agent_runs_list($uid, 40);
    $csrf = csrf_token();

    $h = '<div class="side-section side-agent" data-agent-side="1">';
    $h .= '<div class="side-title side-agent-head">'
        . '<a class="side-agent-link" href="' . e(u('agent')) . '">AI 工作台 <span aria-hidden="true">↗</span></a>'
        . '<span class="side-count">' . count($runs) . '</span>'
        . '</div>';
    $h .= '<button type="button" class="btn primary block side-new" id="agNew"'
        . ' data-csrf="' . e($csrf) . '">＋ 新对话</button>';
    $h .= '<nav class="agent-runs" id="agRuns" data-csrf="' . e($csrf) . '">';
    foreach ($runs as $r) {
        $on = (int)$r['id'] === $runId ? ' on' : '';
        $h .= '<div class="agent-run-row' . $on . '" data-id="' . (int)$r['id'] . '"'
            . ' data-title="' . e((string)$r['title']) . '">'
            . '<a class="agent-run" href="' . e(u('agent', ['id' => (int)$r['id']])) . '">'
            . '<span class="agent-run-t">' . e((string)$r['title']) . '</span>'
            . '<span class="agent-run-m">' . e(mb_substr((string)$r['updated_at'], 5, 11, 'UTF-8'))
            . ' · ' . (int)$r['steps'] . ' 步</span>'
            . '</a>'
            . '<button type="button" class="agent-run-more" aria-label="会话操作" title="重命名 / 删除">⋯</button>'
            . '</div>';
    }
    if (!$runs) {
        $h .= '<div class="side-empty">还没有对话，点上面的「＋ 新对话」开始</div>';
    }
    $h .= '</nav></div>';
    return $h;
}
