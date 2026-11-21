<?php
/** @var array $e @var array $group @var array $docs @var bool $canWrite
 *  可选：$inAdmin（后台预览）、$backUrl（后台返回地址）
 */
$inAdmin = $inAdmin ?? false;
?>
<div class="crumbs">
  <?php if ($inAdmin): ?>
    <a href="<?= e(u('admin')) ?>">后台</a><span>/</span>
    <a href="<?= e(u('admin_content', ['gid' => $e['group_id']])) ?>"><?= e($group['name'] ?? '') ?></a><span>/</span>
  <?php else: ?>
    <a href="<?= e(u()) ?>">首页</a>
    <span>/</span>
    <?php if ($group): ?>
      <a href="<?= e(u('g', ['slug' => $group['slug']])) ?>"><?= e($group['name']) ?></a>
      <span>/</span>
    <?php endif; ?>
  <?php endif; ?>
  <span class="crumb-cur">问答</span>
</div>

<article class="entry">
  <header class="entry-head">
    <span class="tag qa">问答</span>
    <h1 class="entry-title"><?= e($e['question'] !== '' ? $e['question'] : $e['title']) ?></h1>
    <div class="entry-meta">
      <?php if ($e['source']): ?><span>来源：<?= e($e['source']) ?></span><?php endif; ?>
      <?php if ($e['source_date']): ?><span><?= e($e['source_date']) ?></span><?php endif; ?>
      <span>版本 v<?= (int)$e['version'] ?></span>
      <span>更新于 <?= e(human_date($e['updated_at'])) ?></span>
      <?php if ($canWrite): ?>
        <a class="link" href="<?= e(u($inAdmin ? 'admin_entry_edit' : 'entry_edit', ['id' => $e['id']])) ?>">编辑</a>
      <?php endif; ?>
    </div>
  </header>

  <div class="prose">
    <?= md((string)$e['body']) ?>
  </div>
</article>

<?php if (!empty($versions)): ?>
<section class="panel">
  <h2 class="panel-title">历史版本 <span class="muted">改过 <?= count($versions) ?> 次</span></h2>
  <p class="muted">下面是每次修改前的原文，用于核对 Agent 写入带来的变化。</p>
  <?php foreach (array_reverse($versions) as $v): ?>
    <details class="hist">
      <summary>
        <b>v<?= (int)$v['version'] ?></b>
        <span class="muted"><?= e(human_date((string)$v['created_at'])) ?></span>
        <?php if (!empty($v['source'])): ?><span class="muted">· <?= e((string)$v['source']) ?></span><?php endif; ?>
      </summary>
      <?php if ((string)$v['question'] !== '' && (string)$v['question'] !== (string)$e['question']): ?>
        <p class="muted">当时的问题：<?= e((string)$v['question']) ?></p>
      <?php endif; ?>
      <div class="prose"><?= md((string)$v['body']) ?></div>
    </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($docs): ?>
<section class="panel">
  <h2 class="panel-title">收录于</h2>
  <div class="list">
    <?php foreach ($docs as $d): ?>
      <a class="list-row" href="<?= e(u('doc', ['id' => $d['id']])) ?>">
        <span class="tag doc">文档</span>
        <span class="list-title"><?= e($d['title']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
