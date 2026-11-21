<?php /** @var array $user @var array $groups @var array $perms @var array $privateSpaces */ ?>
<div class="crumbs">
  <a href="<?= e(u('admin')) ?>">后台</a><span>/</span>
  <a href="<?= e(u('admin_users')) ?>">用户</a><span>/</span>
  <span class="crumb-cur">分组授权</span>
</div>

<section class="page-head">
  <div>
    <h1 class="page-title">分组授权 · <?= e($user['username']) ?></h1>
    <p class="page-desc">
      勾选他能读的分组。写权限通常不用给人，除非需要人工编辑内容；API Key 的写权限也不会超过这里。
    </p>
  </div>
</section>

<?php if ($privateSpaces): ?>
  <section class="panel">
    <h2 class="panel-title">私有空间</h2>
    <p class="page-desc">
      私有空间只属于创建者本人，不在这里授权，管理员也无法把人加进去——这是数据隔离的底线。
      下面列出全部私有空间，供你了解归属；需要看内容请到「内容管理」。
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>空间</th><th>归属</th><th>条目</th></tr></thead>
        <tbody>
        <?php foreach ($privateSpaces as $g): ?>
          <tr>
            <td><strong><?= e($g['name']) ?></strong>
              <div class="tiny muted mono"><?= e($g['slug']) ?></div></td>
            <td>
              <?php if ((int)$g['owner_id'] === (int)$user['id']): ?>
                <span class="pill ok">当前用户</span>
              <?php else: ?>
                <?= e($g['owner_name'] ?: '（已删除用户）') ?>
              <?php endif; ?>
            </td>
            <td class="tiny"><?= group_entry_count((int)$g['id']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<form method="post" action="<?= e(u('admin_perm', ['uid' => $user['id']])) ?>">
  <?= csrf_field() ?>
  <section class="panel">
    <h2 class="panel-title">共享分组</h2>
    <div class="table-wrap">
      <table class="table perm-table">
        <thead>
          <tr><th>分组</th><th>条目</th><th class="c">可读</th><th class="c">可写</th></tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): $p = $perms[(int)$g['id']] ?? ['read' => false, 'write' => false]; ?>
          <tr>
            <td>
              <strong><?= e($g['name']) ?></strong>
              <div class="tiny muted mono"><?= e($g['slug']) ?></div>
            </td>
            <td class="tiny"><?= group_entry_count((int)$g['id']) ?></td>
            <td class="c">
              <input type="checkbox" name="read[]" value="<?= (int)$g['id'] ?>"
                     <?= $p['read'] ? 'checked' : '' ?>>
            </td>
            <td class="c">
              <input type="checkbox" name="write[]" value="<?= (int)$g['id'] ?>"
                     <?= $p['write'] ? 'checked' : '' ?>>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$groups): ?>
      <div class="empty">还没有共享分组，可以到 <a href="<?= e(u('admin_groups')) ?>">分组管理</a> 建一个。</div>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn primary" type="submit">保存权限</button>
      <span class="hint">勾了「可写」会自动包含「可读」的语义，但建议两个都勾。</span>
    </div>
  </section>
</form>
