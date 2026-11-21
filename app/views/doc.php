<?php /** @var array $e @var array $group @var array $items @var bool $canWrite */ ?>
<div class="crumbs">
  <a href="<?= e(u()) ?>">首页</a>
  <span>/</span>
  <?php if ($group): ?>
    <a href="<?= e(u('g', ['slug' => $group['slug'], 'kind' => 'doc'])) ?>"><?= e($group['name']) ?></a>
    <span>/</span>
  <?php endif; ?>
  <span class="crumb-cur">文档</span>
</div>

<div class="doc-layout">
  <?php if (count($items) > 1): ?>
    <nav class="doc-toc">
      <div class="toc-title">本文目录</div>
      <?php foreach ($items as $i => $it): ?>
        <a href="#s<?= (int)$it['id'] ?>"><?= ($i + 1) ?>.</a>
        <a class="toc-text" href="#s<?= (int)$it['id'] ?>"><?= e(mb_substr((string)$it['question'], 0, 28, 'UTF-8')) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <article class="entry doc">
    <header class="entry-head">
      <span class="tag doc">文档</span>
      <h1 class="entry-title"><?= e($e['title']) ?></h1>
      <div class="entry-meta">
        <?php if ($e['source']): ?><span>来源：<?= e($e['source']) ?></span><?php endif; ?>
        <span><?= count($items) ?> 个章节</span>
        <span>更新于 <?= e(human_date($e['updated_at'])) ?></span>
        <?php if ($canWrite): ?>
          <a class="link" href="<?= e(u('entry_edit', ['id' => $e['id']])) ?>">编辑</a>
        <?php endif; ?>
      </div>
    </header>

    <?php if (trim((string)$e['body']) !== ''): ?>
      <div class="prose doc-intro"><?= md((string)$e['body']) ?></div>
    <?php endif; ?>

    <?php foreach ($items as $i => $it): ?>
      <section class="doc-section" id="s<?= (int)$it['id'] ?>">
        <h2 class="doc-h2"><span class="doc-num"><?= ($i + 1) ?></span><?= e($it['question'] !== '' ? $it['question'] : $it['title']) ?></h2>
        <div class="prose"><?= md((string)$it['body']) ?></div>
        <div class="doc-section-foot">
          <a class="link" href="<?= e(u('entry', ['id' => $it['id']])) ?>">单独打开这条问答 →</a>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if (!$items): ?>
      <div class="empty">这篇文档还没有关联问答，编辑时可以勾选要收录的问答。</div>
    <?php endif; ?>
  </article>
</div>
