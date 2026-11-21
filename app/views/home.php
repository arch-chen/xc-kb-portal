<?php /** @var array $groups @var array $stats @var array $recent */ ?>
<section class="hero">
  <h1 class="hero-title">找点什么？</h1>
  <form class="big-search" action="<?= e(u('search')) ?>" method="get" autocomplete="off">
    <input type="hidden" name="r" value="search">
    <input type="search" name="q" placeholder="输入关键词，比如：库存清零、类目审核、退货…"
           value="<?= e(get_q('q')) ?>" autofocus>
    <?php if (count($groups) > 1): ?>
      <select name="g" class="big-search-scope">
        <option value="">全部分组</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= e($g['slug']) ?>"><?= e($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button class="btn primary" type="submit">搜索</button>
  </form>
  <div class="hero-meta">
    共 <?= (int)$totalQa ?> 条问答 · <?= (int)$totalDoc ?> 篇文档 · <?= count($groups) ?> 个可访问分组
  </div>
</section>

<?php if ($groups): ?>
<section class="panel">
  <h2 class="panel-title">我的分组</h2>
  <div class="group-grid">
    <?php foreach ($groups as $g): $s = $stats[(int)$g['id']] ?? []; ?>
      <a class="group-card" href="<?= e(u('g', ['slug' => $g['slug']])) ?>">
        <div class="group-name"><?= e($g['name']) ?></div>
        <div class="group-desc"><?= e(mb_substr((string)$g['description'], 0, 60, 'UTF-8')) ?></div>
        <div class="group-count"><?= (int)($s['qa'] ?? 0) ?> 问答 · <?= (int)($s['doc'] ?? 0) ?> 文档</div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ((int)$totalQa === 0 && (int)$totalDoc === 0): ?>
<section class="panel empty-start">
  <h2>这里还是空的</h2>
  <p>两种方式把内容放进来：</p>
  <ol>
    <li>给 Agent 建一个 API Key，让它把整理好的问答推上来 —— <a href="<?= e(u('keys')) ?>">去建 Key</a></li>
    <li>直接在空间里手动新建一条 —— 点上面的分组卡片进去</li>
  </ol>
</section>
<?php endif; ?>

<?php if ($recent): ?>
<section class="panel">
  <h2 class="panel-title">最近更新</h2>
  <div class="list">
    <?php foreach ($recent as $r): ?>
      <a class="list-row" href="<?= e(u($r['kind'] === 'doc' ? 'doc' : 'entry', ['id' => $r['id']])) ?>">
        <span class="tag <?= e($r['kind']) ?>"><?= $r['kind'] === 'doc' ? '文档' : '问答' ?></span>
        <span class="list-title"><?= e($r['title'] ?: mb_substr((string)$r['question'], 0, 50, 'UTF-8')) ?></span>
        <span class="list-time"><?= e(human_date($r['updated_at'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
