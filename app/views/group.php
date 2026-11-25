<?php /** @var array $group @var array $rows @var array $pg @var string $kind @var bool $canWrite @var bool $canManage @var string $ownerNick @var string $baseName @var bool $isAdmin */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title"><?= e($group['name']) ?></h1>
    <p class="page-desc">
      <?php if ($group['description']): ?><?= e($group['description']) ?><?php endif; ?>
      <?php if ($ownerNick !== ''): ?>
        <span class="muted">· 创建者 <?= e($ownerNick) ?></span>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($canWrite): ?>
    <div class="page-actions">
      <a class="btn" href="<?= e(u('entry_edit', ['gid' => $group['id'], 'kind' => 'qa'])) ?>">＋ 新建问答</a>
      <a class="btn" href="<?= e(u('entry_edit', ['gid' => $group['id'], 'kind' => 'doc'])) ?>">＋ 新建文档</a>
    </div>
  <?php endif; ?>
</section>

<div class="chips">
  <a class="chip <?= $kind === '' ? 'on' : '' ?>" href="<?= e(u('g', ['slug' => $group['slug']])) ?>">全部 <?= (int)$pg['total'] ?></a>
  <a class="chip <?= $kind === 'qa' ? 'on' : '' ?>" href="<?= e(u('g', ['slug' => $group['slug'], 'kind' => 'qa'])) ?>">问答</a>
  <a class="chip <?= $kind === 'doc' ? 'on' : '' ?>" href="<?= e(u('g', ['slug' => $group['slug'], 'kind' => 'doc'])) ?>">文档</a>
</div>

<?php if (!$rows): ?>
  <div class="empty">
    这个分组还没有内容。
    <?php if ($canWrite): ?>
      <div class="empty-hint"><a href="<?= e(u('entry_edit', ['gid' => $group['id']])) ?>">新建第一条</a></div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="list">
    <?php foreach ($rows as $r): ?>
      <a class="list-row" href="<?= e(u($r['kind'] === 'doc' ? 'doc' : 'entry', ['id' => $r['id']])) ?>">
        <span class="tag <?= e($r['kind']) ?>"><?= $r['kind'] === 'doc' ? '文档' : '问答' ?></span>
        <span class="list-title">
          <?= e($r['title'] ?: mb_substr((string)$r['question'], 0, 60, 'UTF-8')) ?>
          <?php if ($r['kind'] === 'qa' && $r['question'] !== '' && $r['title'] !== $r['question']): ?>
            <em class="list-sub"><?= e(mb_substr((string)$r['question'], 0, 70, 'UTF-8')) ?></em>
          <?php endif; ?>
        </span>
        <span class="list-time"><?= e(human_date($r['updated_at'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pg['pages'] > 1): ?>
    <nav class="pager">
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <?php if ($i === 1 || $i === $pg['pages'] || abs($i - $pg['page']) <= 2): ?>
          <a class="page <?= $i === $pg['page'] ? 'on' : '' ?>"
             href="<?= e(u('g', ['slug' => $group['slug'], 'kind' => $kind, 'p' => $i])) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php if ($canManage): $isDefault = $ownerNick !== '' && $baseName === ''; ?>
<section class="panel">
  <h2 class="panel-title">分组设置</h2>
  <form method="post" action="<?= e(u('g', ['slug' => $group['slug']])) ?>" class="form">
    <?= csrf_field() ?>
    <?php if ($isDefault): ?><input type="hidden" name="default_space" value="1"><?php endif; ?>
    <label>分组名称
      <?php if ($ownerNick !== ''): ?>
        <div class="name-prefix">
          <span class="name-prefix-tag"><?= e($ownerNick) ?> ·</span>
          <input type="text" name="name" maxlength="60" value="<?= e($baseName) ?>"
                 placeholder="留空 = 默认空间「<?= e($ownerNick) ?> 的空间」">
        </div>
      <?php else: ?>
        <input type="text" name="name" maxlength="120" value="<?= e($baseName) ?>" required>
      <?php endif; ?>
    </label>
    <label>一句话说明（可选）
      <input type="text" name="description" maxlength="120" value="<?= e($group['description']) ?>">
    </label>
    <div class="hint">
      <?php if ($ownerNick !== ''): ?>
        私有分组的完整名称是「昵称 · 你填的名字」，前面的昵称会跟着账户昵称自动更新。
        <?php if ($isDefault): ?>留空则保持默认空间「<?= e($ownerNick) ?> 的空间」。<?php endif; ?>
      <?php else: ?>
        这是共享分组（全站共用），改名只影响显示名称，不影响里面的内容与已发出的 API Key。
        也可以到「管理后台 → 分组管理」里批量改，还能顺带调排序与说明。
      <?php endif; ?>
    </div>
    <div class="form-actions">
      <button class="btn primary" type="submit">保存</button>
      <a class="btn" href="<?= e(u('account')) ?>">改我的昵称</a>
    </div>
  </form>
</section>
<?php endif; ?>
