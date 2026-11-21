<?php
/** @var string $q @var string $kind @var string $gKey @var string $scopeName @var array $res @var array $groups @var array $pg */
$gmap = [];
foreach ($groups as $g) {
    $gmap[(int)$g['id']] = $g;
}
$baseParams = ['r' => 'search', 'q' => $q, 'g' => $gKey, 'kind' => $kind];
?>
<section class="search-head">
  <form class="big-search compact" action="<?= e(u('search')) ?>" method="get" autocomplete="off">
    <input type="hidden" name="r" value="search">
    <?php if ($gKey !== ''): ?><input type="hidden" name="g" value="<?= e($gKey) ?>"><?php endif; ?>
    <?php if ($kind !== ''): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="搜索知识库…" autofocus>
    <button class="btn primary" type="submit">搜索</button>
  </form>

  <div class="chips">
    <a class="chip <?= $kind === '' ? 'on' : '' ?>" href="<?= e(u('search', array_merge($baseParams, ['kind' => '', 'p' => '']))) ?>">全部</a>
    <a class="chip <?= $kind === 'qa' ? 'on' : '' ?>" href="<?= e(u('search', array_merge($baseParams, ['kind' => 'qa', 'p' => '']))) ?>">问答</a>
    <a class="chip <?= $kind === 'doc' ? 'on' : '' ?>" href="<?= e(u('search', array_merge($baseParams, ['kind' => 'doc', 'p' => '']))) ?>">文档</a>
    <?php if ($scopeName !== ''): ?>
      <span class="chip scope">范围：<?= e($scopeName) ?></span>
      <a class="chip" href="<?= e(u('search', ['q' => $q, 'kind' => $kind])) ?>">× 取消范围</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($q === ''): ?>
  <div class="empty">输入关键词开始搜索。中文可以只输片段，比如「库存」「类目」。</div>
<?php elseif ($res['total'] === 0): ?>
  <div class="empty">
    没有找到和「<?= e($q) ?>」相关的内容。
    <div class="empty-hint">试试更短的词，或者换一个说法。</div>
  </div>
<?php else: ?>
  <div class="result-meta">找到 <?= (int)$res['total'] ?> 条结果<?= $scopeName !== '' ? '（限 ' . e($scopeName) . '）' : '' ?></div>

  <div class="results">
    <?php foreach ($res['items'] as $it): $r = $it['entry']; $g = $gmap[(int)$r['group_id']] ?? null; ?>
      <article class="result">
        <div class="result-top">
          <span class="tag <?= e($r['kind']) ?>"><?= $r['kind'] === 'doc' ? '文档' : '问答' ?></span>
          <?php if ($g): ?>
            <a class="tag ghost" href="<?= e(u('g', ['slug' => $g['slug']])) ?>"><?= e($g['name']) ?></a>
          <?php endif; ?>
          <span class="result-date"><?= e(human_date($r['updated_at'])) ?></span>
        </div>
        <h3 class="result-title">
          <a href="<?= e(u($r['kind'] === 'doc' ? 'doc' : 'entry', ['id' => $r['id']])) ?>">
            <?= highlight(e($r['title'] ?: mb_substr((string)$r['question'], 0, 60, 'UTF-8')), $q) ?>
          </a>
        </h3>
        <?php if ($r['kind'] === 'qa' && $r['question'] !== ''): ?>
          <div class="result-q"><?= highlight(e(mb_substr((string)$r['question'], 0, 120, 'UTF-8')), $q) ?></div>
        <?php endif; ?>
        <p class="result-snippet"><?= highlight(snippet((string)$r['body'], $q, 170), $q) ?></p>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pg['pages'] > 1): ?>
    <nav class="pager">
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <?php if ($i === 1 || $i === $pg['pages'] || abs($i - $pg['page']) <= 2): ?>
          <a class="page <?= $i === $pg['page'] ? 'on' : '' ?>"
             href="<?= e(u('search', array_merge($baseParams, ['p' => $i]))) ?>"><?= $i ?></a>
        <?php elseif (abs($i - $pg['page']) === 3): ?>
          <span class="page-gap">…</span>
        <?php endif; ?>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
