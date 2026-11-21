<?php /** @var array $rows */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">API Key 总览</h1>
    <p class="page-desc">所有用户创建的 Key。这里只能吊销或恢复——明文加密存储，只有创建者本人能查看，管理员也读不到。</p>
    <p class="page-desc tiny">Agent 的接口地址是 <code><?= e(base_url()) ?></code>（完整入口 <code><?= e(base_url()) ?>/api.php?path=</code>）。
      用户在自己账号的「我的 API Key」页可以看到并复制。</p>
  </div>
</section>

<section class="panel">
  <?php if (!$rows): ?>
    <div class="empty">还没有任何 Key。</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>ID</th><th>名称</th><th>用户</th><th>分组</th><th>权限</th><th>状态</th><th>最后使用</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $expired = $r['expires_at'] && strtotime((string)$r['expires_at']) < time();
            $state = (int)$r['revoked'] === 1 ? '已吊销' : ($expired ? '已过期' : '有效');
        ?>
          <tr>
            <td class="mono tiny"><?= (int)$r['id'] ?></td>
            <td><?= e($r['name']) ?><div class="mono tiny muted"><?= e($r['key_prefix']) ?>…</div></td>
            <td><?= e($r['username'] ?: '—') ?></td>
            <td><?= e($r['group_name'] ?: '—') ?></td>
            <td class="tiny"><?= (int)$r['can_read'] ? '读' : '' ?><?= (int)$r['can_read'] && (int)$r['can_write'] ? ' + ' : '' ?><?= (int)$r['can_write'] ? '写' : '' ?></td>
            <td><span class="pill <?= $state === '有效' ? 'ok' : 'off' ?>"><?= $state ?></span></td>
            <td class="tiny"><?= e($r['last_used_at'] ? human_date($r['last_used_at']) : '未使用') ?></td>
            <td class="right">
              <form method="post" action="<?= e(u('admin_keys')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="link <?= (int)$r['revoked'] === 0 ? 'danger' : '' ?>" type="submit">
                  <?= (int)$r['revoked'] === 0 ? '吊销' : '恢复' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
