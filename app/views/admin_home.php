<?php /** @var array $counts @var int $dbSize @var array $recentLogs */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">管理后台</h1>
    <p class="page-desc">系统设置、用户与权限、分组内容、API Key 都在这里，用上方菜单切换。</p>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><div class="stat-n"><?= (int)$counts['users'] ?></div><div class="stat-l">用户</div></div>
  <div class="stat"><div class="stat-n"><?= (int)$counts['groups'] ?></div><div class="stat-l">分组</div></div>
  <div class="stat"><div class="stat-n"><?= (int)$counts['entries'] ?></div><div class="stat-l">知识条目</div></div>
  <div class="stat"><div class="stat-n"><?= (int)$counts['keys'] ?></div><div class="stat-l">有效 API Key</div></div>
  <div class="stat"><div class="stat-n"><?= $dbSize ? round($dbSize / 1048576, 1) . 'M' : '—' ?></div><div class="stat-l">数据库</div></div>
  <div class="stat"><div class="stat-n">v<?= e(APP_VERSION) ?></div><div class="stat-l">版本</div></div>
</div>

<section class="panel">
  <h2 class="panel-title">最近操作</h2>
  <?php if (!$recentLogs): ?>
    <div class="empty">暂无记录。</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>时间</th><th>动作</th><th>详情</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($recentLogs as $l): ?>
          <tr>
            <td class="tiny mono"><?= e($l['created_at']) ?></td>
            <td class="tiny"><?= e($l['action']) ?></td>
            <td class="tiny"><?= e(mb_substr((string)$l['detail'], 0, 70, 'UTF-8')) ?></td>
            <td class="tiny mono"><?= e($l['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
