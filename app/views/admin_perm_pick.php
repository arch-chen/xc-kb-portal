<?php /** @var array $rows */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">分组授权</h1>
    <p class="page-desc">选择要授权的用户。</p>
  </div>
</section>

<section class="panel">
  <?php if (!$rows): ?>
    <div class="empty">还没有用户，先去 <a href="<?= e(u('admin_users')) ?>">用户管理</a> 创建。</div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($rows as $r): ?>
        <a class="list-row" href="<?= e(u('admin_perm', ['uid' => $r['id']])) ?>">
          <span class="tag <?= $r['role'] === 'admin' ? 'doc' : 'qa' ?>"><?= $r['role'] === 'admin' ? '管理员' : '用户' ?></span>
          <span class="list-title"><?= e($r['username']) ?><?= $r['display_name'] ? ' · ' . e($r['display_name']) : '' ?></span>
          <span class="list-time"><?= (int)$r['status'] === 1 ? '启用' : '停用' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
