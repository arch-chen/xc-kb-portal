<?php /** @var array $rows @var string $scope @var int $cntMine @var int $cntUsers */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">分组管理</h1>
    <p class="page-desc">一个分组就是一个独立的知识库。API Key 绑定到分组，Agent 只能看到这个分组里的内容。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('admin_content')) ?>">内容管理 →</a>
  </div>
</section>

<div class="chips">
  <a class="chip <?= $scope === 'mine' ? 'on' : '' ?>" href="<?= e(u('admin_groups')) ?>">
    我的分组 <span class="muted"><?= $cntMine ?></span>
  </a>
  <a class="chip <?= $scope === 'users' ? 'on' : '' ?>" href="<?= e(u('admin_groups', ['scope' => 'users'])) ?>">
    用户的分组 <span class="muted"><?= $cntUsers ?></span>
  </a>
</div>

<?php if ($scope === 'mine'): ?>
<section class="panel">
  <h2 class="panel-title">新建分组</h2>
  <form method="post" action="<?= e(u('admin_groups')) ?>" class="form row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>名称<input type="text" name="name" required maxlength="120" placeholder="例如：跨境电商"></label>
    <label class="grow">说明<input type="text" name="description" placeholder="这个分组收什么内容"></label>
    <label class="narrow">排序<input type="number" name="sort" value="0"></label>
    <button class="btn primary" type="submit">创建</button>
  </form>
</section>
<?php else: ?>
<div class="hint">
  这些是用户注册后自动得到的私有空间，名称由「昵称 · 自定义名」组成，不能在这里新建。
  用户改昵称时前缀会自动更新；你在这里改名只影响「·」后面的部分。
</div>
<?php endif; ?>

<section class="panel">
  <h2 class="panel-title">
    <?= $scope === 'users' ? '用户的分组' : '我的分组' ?>（<?= count($rows) ?>）
  </h2>
  <?php if (!$rows): ?>
    <div class="empty">
      <?= $scope === 'users' ? '还没有用户自建的分组。' : '还没有分组，先建一个。' ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ID</th><th>名称</th><th>标识</th><th>归属</th><th>说明</th><th>条目</th><th>排序</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $fid = 'gf' . (int)$r['id']; ?>
          <tr>
            <td class="mono tiny"><?= (int)$r['id'] ?></td>
            <td><input type="text" name="name" form="<?= $fid ?>" value="<?= e($r['name']) ?>" class="cell-input"
                       title="<?= (int)$r['owner_id'] > 0 ? '完整名称，前面的昵称会自动维护' : '' ?>"></td>
            <td><input type="text" name="slug" form="<?= $fid ?>" value="<?= e($r['slug']) ?>" class="tiny-input" style="width:120px" title="自定义访问标识，留空则保持"></td>
            <td class="tiny">
              <?php if ((int)$r['owner_id'] > 0): ?>
                <span class="pill ok" title="私有空间，仅归属人可访问">私有</span>
                <?= e($r['owner_nick'] ?: $r['owner_name'] ?: '（用户已删除）') ?>
                <?php if ($r['owner_nick'] && $r['owner_name']): ?>
                  <span class="muted">(<?= e($r['owner_name']) ?>)</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="pill off">共享</span>
              <?php endif; ?>
            </td>
            <td><input type="text" name="description" form="<?= $fid ?>" value="<?= e($r['description']) ?>" placeholder="说明" class="cell-input"></td>
            <td><a href="<?= e(u('admin_content', ['gid' => $r['id']])) ?>"><?= (int)$r['cnt'] ?></a></td>
            <td><input type="number" name="sort" form="<?= $fid ?>" value="<?= (int)$r['sort'] ?>" class="tiny-input"></td>
            <td class="col-actions">
              <form id="<?= $fid ?>" method="post" action="<?= e(u('admin_groups')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="scope" value="<?= e($scope) ?>">
                <button class="link" type="submit">保存</button>
              </form>
              <form method="post" action="<?= e(u('admin_groups')) ?>" class="inline-form"
                    onsubmit="return confirm('确定删除该分组？只有空分组可以删。')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="scope" value="<?= e($scope) ?>">
                <button class="link danger" type="submit">删除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
