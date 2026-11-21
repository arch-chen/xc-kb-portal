<?php
declare(strict_types=1);

/**
 * AI 会话门禁（2.2.5）：回答问题前，先判断用户选的是「积分扣除」还是「自定义接入模型」。
 *
 * 两条路径：
 *   credits（系统模型，owner_id = 0）→ 先看积分余额，不够就拦下，给出「充值 / 切换自定义模型」入口；
 *   custom （我的模型，owner_id > 0）→ 不扣积分，检查套餐与接口配置；接口不通时把报错原因翻成人话。
 *
 * 这里只做判断与文案：不扣分、不调模型、不写库。真正的扣费 / 退款仍在 ai_run() 里，
 * 铁律「先扣后调、失败全额退」不变。返回的 guide 是给会话窗口渲染「引导卡片」的固定结构，
 * 前端不猜字段，直接照着画。
 */

/** 模型模式：custom = 用户自定义接入；credits = 系统模型按积分扣 */
function ai_model_mode(array $model): string
{
    return (int)($model['owner_id'] ?? 0) > 0 ? 'custom' : 'credits';
}

/** 站内链接一律走 u()，避免手写 ?r= 漏掉 base_path */
function ai_guide_link(string $label, string $route, bool $primary = false): array
{
    return ['label' => $label, 'url' => u($route), 'primary' => $primary];
}

/** 想切换到另一种模式时，找一个可用的目标模型（用于卡片上的「一键切换」） */
function ai_switch_target(int $uid, string $want): ?array
{
    foreach (ai_models_for($uid) as $m) {
        if (ai_model_mode($m) === $want && (int)$m['enabled'] === 1) {
            return ['id' => (int)$m['id'], 'name' => (string)$m['name'], 'mode' => $want];
        }
    }
    return null;
}

/** 统一 guide 结构（字段固定，前端照着渲染） */
function ai_guide(string $mode, string $code, string $title, string $message, array $actions = [], array $opt = []): array
{
    return array_merge([
        'mode'    => $mode,
        'code'    => $code,
        'title'   => $title,
        'message' => $message,
        'reason'  => '',
        'hints'   => [],
        'actions' => $actions,
        'switch'  => null,
        'balance' => 0,
        'price'   => 0,
    ], $opt);
}

/**
 * 回答前的前置判断。
 * 返回 null = 放行；否则返回 guide，控制器应直接把卡片回给会话窗口，不再调用模型、不扣积分。
 */
function ai_preflight(int $uid, array $user, int $modelId): ?array
{
    $model = ai_model_get($modelId);
    if (!$model) {
        return ai_guide('credits', 'no_model', '还没有可用的模型',
            '当前没有选中任何模型。让管理员在「管理后台 → AI 与套餐」里添加系统模型；或者开通套餐后，在「我的模型」里接入自己的接口。',
            [ai_guide_link('接入自定义模型', 'ai_models', true), ai_guide_link('充值 / 套餐', 'recharge')]);
    }

    $mode    = ai_model_mode($model);
    $ent     = ai_entitlement($uid);
    $balance = (int)$ent['credits'];
    $price   = (int)$model['credits'];

    $chk = ai_can_use_model($user, $model, $ent);
    if (!$chk['ok']) {
        $reason = (string)$chk['reason'];

        // ① 积分扣除：余额不够 → 充值 / 切换自定义接入模型
        if (str_contains($reason, '积分不足')) {
            return ai_guide('credits', 'insufficient', '积分不足，暂时无法回答',
                '你当前选择的是「积分扣除」（系统模型），本次需要 ' . $price . ' 积分，而账户余额只有 ' . $balance
                . ' 积分。充值后可以继续；也可以改用你自己的模型接口，不消耗积分。',
                [
                    ai_guide_link('去充值 / 买套餐', 'recharge', true),
                    ai_guide_link('接入自定义模型', 'ai_models'),
                ],
                [
                    'switch'  => ai_switch_target($uid, 'custom'),
                    'balance' => $balance,
                    'price'   => $price,
                    'hints'   => [
                        '充值到账后直接重新发送刚才的问题即可，不用新建对话。',
                        '自定义接入模型属于月付套餐功能：开通套餐后在「我的模型」里填接口地址、模型名和 API Key。',
                    ],
                ]);
        }

        // ② 自定义接入模型：需要月付套餐
        if (str_contains($reason, '月付套餐') || str_contains($reason, '请先开通套餐')) {
            return ai_guide('custom', 'no_plan', '自定义模型需要先开通套餐',
                '「我的模型」是月付套餐功能，当前账户没有有效套餐。开通后即可用自己的接口对话，不扣积分；也可以先切回系统模型按积分使用。',
                [ai_guide_link('去开通套餐', 'recharge', true), ai_guide_link('我的模型', 'ai_models')],
                [
                    'switch' => ai_switch_target($uid, 'credits'),
                    'hints'  => ['月付套餐有效期内可添加若干自定义模型，可添加数量以套餐为准。'],
                ]);
        }

        if (str_contains($reason, '不包含系统模型')) {
            return ai_guide('credits', 'plan_no_system', '当前套餐不包含系统模型',
                '你的套餐只允许使用自己的模型。请切换到一个自定义接入模型，或到「我的模型」里新增一个。',
                [ai_guide_link('我的模型', 'ai_models', true), ai_guide_link('充值 / 套餐', 'recharge')],
                ['switch' => ai_switch_target($uid, 'custom')]);
        }

        if (str_contains($reason, '调用次数已用完')) {
            return ai_guide($mode, 'daily_limit', '今天的调用次数用完了',
                $reason . '。明天会自动恢复；升级套餐可提高每日上限。',
                [ai_guide_link('查看套餐', 'recharge', true)]);
        }

        // 停用 / 不是自己的模型 / 其它权益问题
        return ai_guide($mode, 'forbidden', '这个模型现在不能用',
            $reason . '。可以在「我的模型」里换一个，或联系管理员。',
            [ai_guide_link('我的模型', 'ai_models', true), ai_guide_link('账单', 'recharge')]);
    }

    // ② 自定义接入模型：接口配置必须齐全，别等到真调用了才报 401 / 404
    if ($mode === 'custom') {
        $miss = [];
        if (trim((string)($model['base_url'] ?? '')) === '') {
            $miss[] = '接口地址（Base URL）';
        }
        if (trim((string)($model['model_key'] ?? '')) === '') {
            $miss[] = '模型名（Model ID）';
        }
        if (ai_model_secret($model) === '') {
            $miss[] = 'API Key';
        }
        if ($miss) {
            return ai_guide('custom', 'bad_config', '自定义模型还没配置完整',
                '你选择的自定义接入模型缺少：' . implode('、', $miss) . '。补齐后再对话；也可以点「测试连接」先确认接口能不能通。',
                [ai_guide_link('去「我的模型」补全', 'ai_models', true), ai_guide_link('充值 / 套餐', 'recharge')],
                [
                    'hints'  => [
                        '接口地址填到 /chat/completions 之前那一段（OpenAI 兼容），模型名要和服务商控制台一致。',
                        '密钥被浏览器自动填充成星号会导致 401，保存时请重新粘贴一次真实 Key。',
                    ],
                    'switch' => ai_switch_target($uid, 'credits'),
                ]);
        }
    }

    return null; // 放行：交给 ai_run() 走「预扣 → 调用 → 记日志 → 失败退款」
}

/**
 * 模型真的调了但没成功 → 带「具体报错原因」的引导卡片。
 * 自定义接入模型：把 network / auth / timeout / rate / bad_config 的错误翻成可操作的排查建议；
 * 系统模型：说明积分已退回，并给出切换自定义模型的入口。
 */
function ai_guide_from_error(Throwable $e, array $model, array $user): array
{
    $mode   = ai_model_mode($model);
    $reason = $e->getMessage();
    $kind   = ($e instanceof AiError) ? $e->kind : 'error';
    $uid    = (int)($user['id'] ?? 0);

    if ($mode === 'custom') {
        $hints = [
            '到「我的模型」点该模型的「测试」，能最快定位是地址、密钥、模型名还是网络的问题。',
            'OpenAI 兼容接口请确认协议选的是「OpenAI 兼容」；Anthropic / Gemini 要选对应协议。',
            '如果是中转 / 自建服务，检查域名能否解析、端口是否开放、证书是否正规。',
        ];
        $lead = match ($kind) {
            'auth'         => 'HTTP 401/403：API Key 无效或没权限，确认 Key 没被改写、账户有该模型权限。',
            'rate'         => 'HTTP 429：上游限流或额度用尽，稍后重试或去服务商后台查额度。',
            'timeout'      => '超时：地址可能是通的，但上游迟迟不返回。换个模型，或把「超时」调小让它快速失败。',
            'bad_config'   => 'HTTP 404 / 配置错误：接口地址或模型名不对，对照服务商文档核对。',
            'bad_response' => '模型返回了无法解析的内容：多半是协议选错，或上游返回了错误 JSON。',
            'network'      => '网络层没连通：按下面的原因看是域名解析、TCP、TLS 还是上游主动断开。',
            default        => '接口没有返回有效回答，按下面几条逐项排查。',
        };
        array_unshift($hints, $lead);

        return ai_guide('custom', 'call_failed', '自定义模型接口没连上',
            '系统已经按你接入的模型发起请求，但没有拿到有效回答。下面是接口返回的报错原因：',
            [ai_guide_link('去「我的模型」检查 / 测试', 'ai_models', true), ai_guide_link('充值 / 套餐', 'recharge')],
            [
                'reason' => $reason,
                'hints'  => $hints,
                'switch' => ai_switch_target($uid, 'credits'),
            ]);
    }

    // 系统模型（积分扣除）调用失败：ai_run 已把预扣的积分全额退回
    return ai_guide('credits', 'call_failed', '系统模型暂时没应答',
        '这次调用没有成功，预扣的积分已经全额退回。可以稍后重试，或改用你自己的模型接口。',
        [ai_guide_link('接入自定义模型', 'ai_models', true), ai_guide_link('账单 / 套餐', 'recharge')],
        [
            'reason' => $reason,
            'hints'  => [
                '系统模型由管理员配置，若持续失败请把下面的报错原因发给管理员。',
                '重试不会产生额外扣费：失败自动退回，只有成功才扣积分。',
            ],
            'switch' => ai_switch_target($uid, 'custom'),
        ]);
}
