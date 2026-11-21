<?php /** @var array $groups @var ?array $group @var array $rows @var array $pg @var string $kind @var string $q */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">内容管理</h1>
    <p class="page-desc">问答是原子条目，文档可以把多条问答组织成一篇可通读的手册。</p>
  </div>
  <?php if ($group): ?>
    <div class="page-actions">
      <a class="btn" href="<?= e(u('admin_entry_edit', ['gid' => $group['id'], 'kind' => 'qa'])) ?>">＋ 问答</a>
      <a class="btn" href="<?= e(u('admin_entry_edit', ['gid' => $group['id'], 'kind' => 'doc'])) ?>">＋ 文档</a>
    </div>
  <?php endif; ?>
</section>

<?php if (!$groups): ?>
  <div class="empty">还没有分组，先去 <a href="<?= e(u('admin_groups')) ?>">分组管理</a> 建一个。</div>
<?php else: ?>
  <div class="chips">
    <?php foreach ($groups as $g): ?>
      <a class="chip <?= (int)$g['id'] === (int)$group['id'] ? 'on' : '' ?>"
         href="<?= e(u('admin_content', ['gid' => $g['id'], 'kind' => $kind])) ?>"><?= e($g['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <section class="panel">
    <form class="form row-form" method="get" action="<?= e(u('admin_content')) ?>">
      <input type="hidden" name="r" value="admin_content">
      <input type="hidden" name="gid" value="<?= (int)$group['id'] ?>">
      <label class="grow">搜索<input type="search" name="q" value="<?= e($q) ?>" placeholder="标题或问题包含…"></label>
      <label class="narrow">类型
        <select name="kind">
          <option value="" <?= $kind === '' ? 'selected' : '' ?>>全部</option>
          <option value="qa" <?= $kind === 'qa' ? 'selected' : '' ?>>问答</option>
          <option value="doc" <?= $kind === 'doc' ? 'selected' : '' ?>>文档</option>
        </select>
      </label>
      <button class="btn" type="submit">筛选</button>
      <span class="hint">共 <?= (int)$pg['total'] ?> 条</span>
    </form>

    <?php if (!$rows): ?>
      <div class="empty">这个分组还没有内容。</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>ID</th><th>类型</th><th>标题 / 问题</th><th>来源</th><th>版本</th><th>更新</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="mono tiny"><?= (int)$r['id'] ?></td>
              <td><span class="tag <?= e($r['kind']) ?>"><?= $r['kind'] === 'doc' ? '文档' : '问答' ?></span></td>
              <td class="col-title">
                <a href="<?= e(u('admin_entry_view', ['id' => $r['id']])) ?>" target="_blank">
                  <?= e($r['title'] ?: mb_substr((string)$r['question'], 0, 60, 'UTF-8')) ?>
                </a>
              </td>
              <td class="tiny"><?= e($r['source']) ?></td>
              <td class="tiny">v<?= (int)$r['version'] ?></td>
              <td class="tiny"><?= e(substr((string)$r['updated_at'], 0, 16)) ?></td>
              <td class="right"><a class="link" href="<?= e(u('admin_entry_edit', ['id' => $r['id']])) ?>">编辑</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pg['pages'] > 1): ?>
        <nav class="pager">
          <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
            <?php if ($i === 1 || $i === $pg['pages'] || abs($i - $pg['page']) <= 2): ?>
              <a class="page <?= $i === $pg['page'] ? 'on' : '' ?>"
                 href="<?= e(u('admin_content', ['gid' => $group['id'], 'kind' => $kind, 'q' => $q, 'p' => $i])) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
