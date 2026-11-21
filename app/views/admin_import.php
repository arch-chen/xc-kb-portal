<?php /** @var string $msg @var string $err @var ?array $stats @var bool $builtin @var float $builtinSize @var array $users @var array $groups */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">导入数据</h1>
    <p class="page-desc">
      上传一份问答 CSV 灌进分组，系统会自动建索引、并给每个分类生成一篇「手册」文档。
      重复导入会自动跳过已有问题，不会产生重复。
    </p>
  </div>
</section>

<?php if ($msg !== ''): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

<?php if ($stats): ?>
  <section class="panel">
    <h2 class="panel-title">本次结果</h2>
    <div class="stat-grid">
      <div class="stat"><div class="stat-n"><?= (int)$stats['inserted'] ?></div><div class="stat-l">新增问答</div></div>
      <div class="stat"><div class="stat-n"><?= (int)$stats['skipped'] ?></div><div class="stat-l">跳过（重复/空行）</div></div>
      <div class="stat"><div class="stat-n"><?= (int)$stats['qa'] ?></div><div class="stat-l">分组内问答总数</div></div>
      <div class="stat"><div class="stat-n"><?= (int)$stats['doc'] ?></div><div class="stat-l">分册文档</div></div>
    </div>
    <a class="btn" href="<?= e(u('admin_content')) ?>">去看看内容 →</a>
  </section>
<?php endif; ?>

<div class="two-col">
  <section class="panel">
    <h2 class="panel-title">方式一：用内置的 Wayfair 数据</h2>
    <?php if ($builtin): ?>
      <p class="muted tiny">检测到 <code>data/wayfair-qa.csv</code>（约 <?= e((string)$builtinSize) ?> MB，1800+ 条问答）。</p>
      <form method="post" action="<?= e(u('admin_import')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="builtin">
        <label>导入到分组
          <input type="text" name="group_name" value="跨境电商" list="groupNames">
          <datalist id="groupNames">
            <?php foreach ($groups as $g): ?><option value="<?= e($g['name']) ?>"><?php endforeach; ?>
          </datalist>
        </label>
        <label>归属用户（权限给到他）
          <select name="username" required>
            <?php foreach ($users as $u2): ?>
              <option value="<?= e($u2['username']) ?>"><?= e($u2['username']) ?><?= $u2['display_name'] ? ' · ' . e($u2['display_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>来源标注
          <input type="text" name="source" value="微信群 · Wayfair破浪远洋3群">
        </label>
        <button class="btn primary" type="submit" onclick="return confirm('开始导入？已有问题会自动跳过。')">开始导入</button>
      </form>
    <?php else: ?>
      <div class="empty">
        没有内置数据。<br>
        <span class="empty-hint">把 CSV 放到 <code>data/wayfair-qa.csv</code>，或用右边的上传方式。</span>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="panel-title">方式二：上传 CSV</h2>
    <form method="post" action="<?= e(u('admin_import')) ?>" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="upload">
      <label>CSV 文件
        <input type="file" name="csv" accept=".csv" required>
      </label>
      <div class="hint">
        支持的列名：<code>问题</code>/<code>当前答案</code>/<code>分册</code>/<code>首次提问</code>；
        也认 <code>question</code>/<code>answer</code>/<code>book</code>。没有「分册」列时全部归到「其他」。
      </div>
      <label>导入到分组
        <input type="text" name="group_name" value="跨境电商" list="groupNames2">
        <datalist id="groupNames2">
          <?php foreach ($groups as $g): ?><option value="<?= e($g['name']) ?>"><?php endforeach; ?>
        </datalist>
      </label>
      <label>归属用户
        <select name="username" required>
          <?php foreach ($users as $u2): ?>
            <option value="<?= e($u2['username']) ?>"><?= e($u2['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn primary" type="submit">上传并导入</button>
    </form>
  </section>
</div>
