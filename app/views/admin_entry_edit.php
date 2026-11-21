<?php /** @var ?array $e @var array $group @var array $links @var array $qaList @var bool $inAdmin */ ?>
<?php
// 后台（管理后台 → 内容）与前台（分组页 / 条目页）共用这套表单，
// 但提交地址与「返回」必须各回各家：后台操作不能把用户甩到前台页面里。
$inAdmin = $inAdmin ?? false;
$route   = $inAdmin ? 'admin_entry_edit' : 'entry_edit';
$backUrl = $inAdmin ? u('admin_content', ['gid' => $group['id']]) : u('g', ['slug' => $group['slug']]);
?>
<div class="crumbs">
  <?php if ($inAdmin): ?>
    <a href="<?= e(u('admin')) ?>">后台</a><span>/</span>
    <a href="<?= e(u('admin_content', ['gid' => $group['id']])) ?>"><?= e($group['name']) ?></a><span>/</span>
  <?php else: ?>
    <a href="<?= e(u()) ?>">首页</a><span>/</span>
    <a href="<?= e(u('g', ['slug' => $group['slug']])) ?>"><?= e($group['name']) ?></a><span>/</span>
  <?php endif; ?>
  <span class="crumb-cur"><?= $e ? '编辑' : '新建' ?></span>
</div>

<?php $isDoc = $e ? $e['kind'] === 'doc' : (get_q('kind') === 'doc'); ?>

<form method="post" action="<?= e(u($route, $e ? ['id' => $e['id']] : ['gid' => $group['id']])) ?>" class="entry-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <section class="panel">
    <div class="form row-form">
      <label class="narrow">类型
        <select name="kind" id="kindSelect">
          <option value="qa" <?= !$isDoc ? 'selected' : '' ?>>问答</option>
          <option value="doc" <?= $isDoc ? 'selected' : '' ?>>文档</option>
        </select>
      </label>
      <label class="grow">标题
        <input type="text" name="title" value="<?= e($e['title'] ?? '') ?>" placeholder="留空自动取问题的前 60 字">
      </label>
    </div>

    <label id="questionRow">问题（可留空）
      <input type="text" name="question" value="<?= e($e['question'] ?? '') ?>" placeholder="用户会怎么问">
    </label>

    <label>正文
      <textarea name="body" rows="16" class="mono-input"
        placeholder="支持简单 Markdown：# 标题、- 列表、**粗体**、`代码`、```代码块```"><?= e($e['body'] ?? '') ?></textarea>
    </label>

    <div class="form row-form">
      <label>来源<input type="text" name="source" value="<?= e($e['source'] ?? '') ?>" placeholder="例如：微信群 / 官网文档"></label>
      <label>来源日期<input type="date" name="source_date" value="<?= e($e['source_date'] ?? '') ?>"></label>
    </div>
  </section>

  <section class="panel" id="linksPanel" style="<?= $isDoc ? '' : 'display:none' ?>">
    <h2 class="panel-title">收录的问答（勾选后按列表顺序展示）</h2>
    <?php if (!$qaList): ?>
      <div class="empty">这个分组还没有问答条目。</div>
    <?php else: ?>
      <div class="pick-list">
        <?php foreach ($qaList as $qa): ?>
          <label class="pick">
            <input type="checkbox" name="links[]" value="<?= (int)$qa['id'] ?>"
                   <?= in_array((int)$qa['id'], $links, true) ? 'checked' : '' ?>>
            <span class="pick-q"><?= e(mb_substr((string)($qa['question'] ?: $qa['title']), 0, 80, 'UTF-8')) ?></span>
            <span class="mono tiny muted">#<?= (int)$qa['id'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="hint">最多显示最近 500 条；要收录更早的问答，可以先在列表里找到它并记住编号。</div>
    <?php endif; ?>
  </section>

  <div class="form-actions sticky">
    <button class="btn primary" type="submit">保存</button>
    <a class="btn" href="<?= e($backUrl) ?>">返回列表</a>
    <?php if ($e): ?>
      <a class="btn" href="<?= e(u('admin_entry_view', ['id' => $e['id']])) ?>" target="_blank">查看</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($e): ?>
  <form method="post" action="<?= e(u($route, ['id' => $e['id']])) ?>"
        onsubmit="return confirm('删除后前台不再显示，确定？')" class="danger-zone">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="link danger" type="submit">删除这条内容</button>
  </form>
<?php endif; ?>
