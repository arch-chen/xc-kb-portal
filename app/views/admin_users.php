<?php /** @var array $rows */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">用户管理</h1>
    <p class="page-desc">开放注册时，用户自己注册就会得到一个私有空间，不需要你手动建。这里的「新建用户」是给不开放注册、或要直接开通账号的场景用。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('admin_perm')) ?>">分组授权 →</a>
  </div>
</section>

<section class="panel">
  <h2 class="panel-title">新建用户</h2>
  <form method="post" action="<?= e(u('admin_users')) ?>" class="form row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>用户名<input type="text" name="username" required placeholder="字母数字，3 位以上"></label>
    <label>昵称<input type="text" name="display_name" maxlength="40" placeholder="全站唯一，留空=用用户名"></label>
    <label>邮箱<input type="email" name="email" placeholder="可选，用于收通知"></label>
    <label>初始密码<input type="text" name="password" required placeholder="至少 8 位"></label>
    <button class="btn primary" type="submit">创建</button>
  </form>
</section>

<section class="panel">
  <h2 class="panel-title">全部用户（<?= count($rows) ?>）</h2>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>ID</th><th>用户名</th><th>昵称</th><th>邮箱</th><th>角色</th><th>私有空间</th><th>状态</th><th>最后登录</th><th>操作</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $em = (string)($r['email'] ?? ''); ?>
        <tr>
          <td class="mono tiny"><?= (int)$r['id'] ?></td>
          <td><strong><?= e($r['username']) ?></strong></td>
          <td><?= e($r['display_name']) ?></td>
          <td class="tiny">
            <?php if ($em === ''): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="mono"><?= e($em) ?></span>
              <?php if (($r['email_verified_at'] ?? null) !== null): ?>
                <span class="pill ok tiny">已验证</span>
              <?php else: ?>
                <span class="pill off tiny">未验证</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= $r['role'] === 'admin' ? '<span class="pill on">管理员</span>' : '普通用户' ?></td>
          <td class="tiny"><?= (int)$r['spaces'] > 0 ? (int)$r['spaces'] . ' 个' : '—' ?></td>
          <td><?= (int)$r['status'] === 1 ? '<span class="pill ok">启用</span>' : '<span class="pill off">停用</span>' ?></td>
          <td class="tiny"><?= e($r['last_login_at'] ?: '—') ?></td>
          <td class="right col-actions">
            <a class="link" href="<?= e(u('admin_perm', ['uid' => $r['id']])) ?>">授权</a>
            <form method="post" action="<?= e(u('admin_users')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="link" type="submit"><?= (int)$r['status'] === 1 ? '停用' : '启用' ?></button>
            </form>
            <form method="post" action="<?= e(u('admin_users')) ?>" class="inline-form"
                  onsubmit="return confirm('确定删除该用户？其 API Key 会同时失效。他的私有空间会转为共享分组，内容不会丢。')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="link danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
        <tr class="sub-row">
          <td></td>
          <td colspan="8" style="display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center">
            <form method="post" action="<?= e(u('admin_users')) ?>" class="inline-form reset-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="text" name="password" placeholder="设置新密码（≥8位）">
              <button class="link" type="submit">重置密码</button>
            </form>
            <form method="post" action="<?= e(u('admin_users')) ?>" class="inline-form reset-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="email">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="email" name="email" value="<?= e($em) ?>" placeholder="设置邮箱（留空=清空）">
              <button class="link" type="submit">保存邮箱</button>
              <span class="muted tiny">管理员代设视为已验证</span>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
