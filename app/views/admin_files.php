<?php
/** @var array $rows 平台列表 */
/** @var array $files 附件 */
/** @var array $groups 分组 */
/** @var array $jobs 任务 */
/** @var array $stats */
$cfgLabels = storage_driver_fields('local'); // 只为拿类型，下面按驱动取
?>
<div class="page-head">
  <h1 class="page-title">文件存储</h1>
  <p class="muted">上传的文件按「主平台 + 所有已启用平台」多写一份；中途新接的平台点「同步数据」把历史文件补齐过去。</p>
</div>

<section class="panel">
  <?= panel_title('概览', '<p>支持的格式：<code>' . e($exts) . '</code>。'
      . '刻意不支持 <code>svg/html/php/js</code> 这类能执行脚本的格式。</p>'
      . '<p class="muted">下载一律走 <code>?r=f&amp;id=</code> 鉴权代理，不暴露对象存储地址，也不生成公开直链。</p>') ?>
  <div class="stat-grid">
    <div class="stat"><div class="stat-n"><?= (int)$stats['files'] ?></div><div class="stat-l">个文件</div></div>
    <div class="stat"><div class="stat-n"><?= e(file_human_size((int)$stats['bytes'])) ?></div><div class="stat-l">总大小</div></div>
    <div class="stat"><div class="stat-n"><?= (int)$stats['replicas'] ?></div><div class="stat-l">可用副本</div></div>
    <div class="stat"><div class="stat-n"><?= (int)$maxMb ?> MB</div><div class="stat-l">单文件上限</div></div>
  </div>
</section>

<section class="panel">
  <h2 class="panel-title">存储平台 <span class="tag">多副本</span></h2>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>名称</th><th>类型</th><th>状态</th><th>副本</th><th>缺</th><th>操作</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $p = $r['p']; ?>
        <tr>
          <td>
            <b><?= e($p['name']) ?></b>
            <?php if ((int)$p['is_primary'] === 1): ?><span class="tag">默认</span><?php endif; ?>
            <div class="tiny muted"><?= (int)$p['id'] ?> · <?= e(storage_driver_labels()[$p['driver']] ?? $p['driver']) ?></div>
          </td>
          <td class="tiny"><?= e($p['driver']) ?></td>
          <td>
            <?php if ((int)$p['enabled'] === 1): ?>
              <span class="tag ok">启用</span>
            <?php else: ?>
              <span class="tag">停用</span>
            <?php endif; ?>
            <?php if (($p['status'] ?? '') === 'fail'): ?>
              <div class="tiny err" title="<?= e((string)($p['last_error'] ?? '')) ?>">连接失败</div>
            <?php endif; ?>
          </td>
          <td><?= (int)$r['copies'] ?></td>
          <td><?= (int)$r['missing'] ?></td>
          <td class="ops">
            <button type="button" class="btn sm" data-test="<?= (int)$p['id'] ?>">测试连接</button>

            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="provider_toggle">
              <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
              <button class="btn sm" type="submit"><?= (int)$p['enabled'] === 1 ? '停用' : '启用' ?></button>
            </form>

            <?php if ((int)$p['is_primary'] !== 1): ?>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="provider_primary">
              <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
              <button class="btn sm" type="submit">设为默认</button>
            </form>
            <?php endif; ?>

            <?php if ((int)$p['enabled'] === 1): ?>
              <button type="button" class="btn sm primary" data-sync="<?= (int)$p['id'] ?>">同步数据<?= (int)$r['missing'] > 0 ? '（缺 ' . (int)$r['missing'] . '）' : '' ?></button>
            <?php endif; ?>

            <a class="btn sm" href="<?= e(u('admin_files', ['edit' => (int)$p['id']])) ?>">编辑</a>

            <?php if ((int)$p['is_primary'] !== 1): ?>
            <form method="post" class="inline-form" onsubmit="return confirm('确定删除这个平台？那边已上传的文件不会被删，但本站不再跟踪。')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="provider_delete">
              <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
              <button class="btn sm danger" type="submit">删除</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div id="jobBox" class="hint" style="display:none">
    <div class="job-line">
      <b id="jobTitle">同步中…</b>
      <span id="jobMsg" class="muted tiny"></span>
    </div>
    <div class="progress"><i id="jobBar" style="width:0%"></i></div>
  </div>

  <p class="hint">「同步数据」只补齐缺失副本，不删除；可随时中断，再点一次接着补。<?= tip(
      '<p><b>「同步数据」做什么：</b>把这个平台还缺的文件，从其他有副本的平台拉过来再写一份。'
      . '只补齐、不删除，可以随时中断，再点一次会接着补（已有的跳过）。</p>'
      . '<p><b>数据怎么搬：</b>源和目标是<b>同一家云、同一个账号</b>时走「云端直搬」——'
      . '由目标云自己去源桶取，数据不经过本站、不占服务器磁盘；'
      . '<b>跨厂商</b>（比如 OSS → COS）没有互认的复制协议，只能走「本地中转」：'
      . '先落到 <code>storage/tmp</code> 再传上去，处理完立即删除，峰值只占一个文件'
      . '（≤ 站点上传上限）。中转前会检查磁盘余量，不够就直接报错，不会写坏文件。</p>'
      . '<p class="muted">平台停用后不再接收新文件，历史副本保留，读取时也不会再用它——'
      . '相当于「先挂着不动」。</p>', '', '同步说明') ?></p>
</section>

<section class="panel">
  <details class="fold" <?= $edit ? 'open' : '' ?>>
    <summary><?= $edit ? '编辑平台' : '接入新平台' ?> <span class="fold-note">本地 / OSS / COS / 七牛 / 又拍云 / R2</span></summary>
    <div class="fold-body">
  <form method="post" class="form" id="provForm" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="provider_save">
    <input type="hidden" name="provider_id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="form row-form">
      <label>平台类型
        <select name="driver" id="driverSel">
          <?php foreach (storage_driver_labels() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($edit['driver'] ?? 'local') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>显示名称
        <input type="text" name="name" value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="例如 阿里云-主">
      </label>
    </div>

    <?php
    // 六个驱动共用 bucket/ak/sk/domain 等同名 name，早先只靠 display:none 隐藏，
    // 但隐藏字段照样会提交，后面的驱动块会把前面用户填的值覆盖掉（最后是 r2 块），
    // 于是「保存后重开编辑像被改了、参数不生效」。三重加固：
    //   1) 字段按驱动分组命名 cfg[driver][field]，后端只取当前驱动那一组；
    //   2) 非当前驱动块在服务端就输出 disabled（JS 失效也不会误提交）；
    //   3) 关掉自动填充，避免浏览器把密钥框当登录框、把掩码串写进 SecretKey。
    $activeDrv = (string)($edit['driver'] ?? 'local');
    ?>
    <?php foreach (storage_driver_labels() as $dk => $dl): ?>
      <?php $off = $dk !== $activeDrv ? ' disabled' : ''; ?>
      <div class="drv-fields" data-driver="<?= e($dk) ?>" style="display:none">
        <?php foreach (storage_driver_fields($dk) as $fk => $meta): ?>
          <?php
            $label = $meta[0]; $ph = $meta[1]; $def = $meta[2]; $secret = $meta[3];
            // 只有当前驱动才回显已保存配置，切换平台时不会把旧平台参数带过去
            $cur = $dk === $activeDrv ? (string)($editCfg[$fk] ?? '') : '';
            $nm = 'cfg[' . $dk . '][' . $fk . ']';
            $ac = $secret ? 'new-password' : 'off';
          ?>
          <label><?= e($label) ?>
            <?php if ($secret && $edit && $dk === $activeDrv): ?>
              <input type="password" name="<?= e($nm) ?>" value="" autocomplete="<?= $ac ?>"<?= $off ?> placeholder="已保存，留空表示不修改">
            <?php else: ?>
              <input type="<?= $secret ? 'password' : 'text' ?>" name="<?= e($nm) ?>"
                     value="<?= e($cur !== '' ? $cur : $def) ?>" autocomplete="<?= $ac ?>"<?= $off ?> placeholder="<?= e($ph) ?>">
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <p class="hint">新平台保存后是<b>停用</b>状态：先「测试连接」通过再「启用」。<?= tip(
        '<p>密钥类字段（SecretKey / 密码）用 AES-256-GCM 加密存在 <code>settings</code> 库里，'
        . '页面永不回显；密钥本身是 <code>config.php</code> 的 pepper，库被下载走也解不开。</p>'
        . '<p class="muted">七牛报 401 bad token 时优先查四项：AK/SK 是否同账号、'
        . 'Bucket 填的是空间名而不是域名、服务器时间是否准、上传域名是否匹配区域。</p>', '', '密钥与排查') ?></p>

    <button class="btn primary" type="submit"><?= $edit ? '保存修改' : '添加平台' ?></button>
    <?php if ($edit): ?>
      <a class="btn" href="<?= e(u('admin_files')) ?>">取消</a>
    <?php endif; ?>
  </form>
    </div>
  </details>
</section>

<section class="panel">
  <h2 class="panel-title">上传文件</h2>
  <?php if (!$groups): ?>
    <div class="empty">还没有分组，先去 <a href="<?= e(u('admin_groups')) ?>">分组管理</a> 建一个。</div>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="form row-form">
      <label>存到哪个分组
        <select name="group_id">
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === (int)$gid ? 'selected' : '' ?>><?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>绑定到条目（可选，填条目 ID）
        <input type="number" name="entry_id" min="0" placeholder="留空表示只存不用">
      </label>
    </div>
    <label>选择文件（可多选）
      <input type="file" name="files[]" multiple>
    </label>
    <div class="hint">单文件上限 <?= (int)$maxMb ?> MB，同时受 PHP 的 <code>upload_max_filesize</code>
      （当前 <?= e((string)ini_get('upload_max_filesize')) ?>）限制。<?=
      tip_text('两者取小值生效。如果这里显示的上限比预期小，改 php.ini 的 upload_max_filesize 与 post_max_size 后重启 PHP；站点上限在「文件存储 → 添加平台」下方可调。', '', '上传上限说明') ?></div>
    <button class="btn primary" type="submit">上传</button>
  </form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="panel-title">附件列表</h2>
  <form method="get" class="form row-form" action="<?= e(u('admin_files')) ?>">
    <input type="hidden" name="r" value="admin_files">
    <label>按分组筛选
      <select name="gid" onchange="this.form.submit()">
        <option value="0">— 全部 —</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === (int)$gid ? 'selected' : '' ?>><?= e($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <?php if (!$files): ?>
    <div class="empty">还没有文件。上面选个分组上传试试。</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>文件</th><th>大小</th><th>副本</th><th>时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($files as $f): $reps = attachment_replicas((int)$f['id']); ?>
        <tr>
          <td>
            <b><?= e((string)$f['name']) ?></b>
            <?php if ((int)$gid === 0 && !empty($f['group_name'])): ?>
              <div class="tiny muted">分组：<?= e((string)$f['group_name']) ?></div>
            <?php endif; ?>
            <div class="tiny muted"><code><?= e((string)$f['object_key']) ?></code></div>
          </td>
          <td class="tiny"><?= e(file_human_size((int)$f['size'])) ?></td>
          <td class="tiny">
            <?php foreach ($reps as $rp): ?>
              <span class="tag <?= $rp['state'] === 'ok' ? 'ok' : '' ?>" title="<?= e((string)($rp['last_error'] ?? '')) ?>">
                <?= e((string)($rp['provider_name'] ?? '?')) ?><?= $rp['state'] === 'ok' ? '' : '（失败）' ?>
              </span>
            <?php endforeach; ?>
          </td>
          <td class="tiny muted"><?= e((string)$f['created_at']) ?></td>
          <td class="ops">
            <a class="btn sm" href="<?= e(u('admin_file', ['id' => (int)$f['id']])) ?>" target="_blank" rel="noopener">打开</a>
            <?php
              $fileUrl = u('admin_file', ['id' => (int)$f['id']]);
              $isImg = in_array(strtolower((string)$f['ext']), ['jpg','jpeg','png','gif','webp','bmp'], true);
              $ref = $isImg ? '![' . $f['name'] . '](' . $fileUrl . ')' : '[' . $f['name'] . '](' . $fileUrl . ')';
            ?>
            <button type="button" class="btn sm" data-copy="<?= e($ref) ?>"><?= $isImg ? '复制图片引用' : '复制链接' ?></button>
            <form method="post" class="inline-form" onsubmit="return confirm('删除后各平台的副本也会删掉，确定？')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="file_delete">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn sm danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($jobs): ?>
<section class="panel">
  <h2 class="panel-title">最近的同步任务</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>平台</th><th>状态</th><th>进度</th><th>说明</th><th>开始</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><?= e((string)($j['provider_name'] ?? '?')) ?></td>
          <td class="tiny"><?= e((string)$j['state']) ?></td>
          <td class="tiny"><?= (int)$j['done'] ?>/<?= (int)$j['total'] ?>（失败 <?= (int)$j['failed'] ?>）</td>
          <td class="tiny muted"><?= e((string)($j['message'] ?? '')) ?></td>
          <td class="tiny muted"><?= e((string)($j['started_at'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  // 驱动切换：只显示当前驱动的字段
  var sel = document.getElementById('driverSel');
  function syncDrv() {
    var v = sel ? sel.value : '';
    document.querySelectorAll('.drv-fields').forEach(function (el) {
      var on = el.getAttribute('data-driver') === v;
      el.style.display = on ? '' : 'none';
      // 隐藏块里的字段必须 disabled：display:none 不会阻止表单提交，
      // 同名字段一旦提交就会覆盖当前驱动刚填好的值。
      el.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
    });
  }
  if (sel) { sel.addEventListener('change', syncDrv); syncDrv(); }

  var csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';

  function csrfOf(el) {
    var f = el.closest ? el.closest('form') : null;
    var i = f ? f.querySelector('input[name="_csrf"]') : null;
    return i ? i.value : csrf;
  }

  function flash(el, text, ok) {
    var old = el.textContent;
    el.textContent = text;
    el.disabled = true;
    setTimeout(function () { el.textContent = old; el.disabled = false; }, 4000);
  }

  // 测试连接
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-test]') : null;
    if (!b) return;
    flash(b, '测试中…');
    fetch('<?= e(u('admin_files')) ?>&do=test&id=' + b.getAttribute('data-test'), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { alert('连接正常：' + d.data.message); location.reload(); }
        else { alert('连接失败：' + (d.error && d.error.message)); }
        b.disabled = false; b.textContent = '测试连接';
      })
      .catch(function (e) { alert('请求失败：' + e.message); b.disabled = false; b.textContent = '测试连接'; });
  });

  // 同步：开局 → 每秒推一批
  var box = document.getElementById('jobBox');
  var bar = document.getElementById('jobBar');
  var title = document.getElementById('jobTitle');
  var msg = document.getElementById('jobMsg');

  function paint(job, name) {
    box.style.display = '';
    title.textContent = (name || '') + ' 同步：' + job.percent + '%';
    var way = '';
    if (job.cloud_copies || job.relayed) {
      way = '　云端直搬 ' + job.cloud_copies + '、本地中转 ' + job.relayed;
    }
    msg.textContent = '成功 ' + job.done + ' / 共 ' + job.total + (job.failed ? '，失败 ' + job.failed : '') + way + '　' + (job.message || '');
    bar.style.width = job.percent + '%';
  }

  function step(jobId, name) {
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    body.set('job_id', jobId);
    fetch('<?= e(u('admin_files')) ?>&do=sync_step', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { alert('同步失败：' + (d.error && d.error.message)); return; }
      paint(d.data.job, name);
      if (d.data.job.state === 'running') { setTimeout(function () { step(jobId, name); }, 600); }
      else { setTimeout(function () { location.reload(); }, 900); }
    }).catch(function (e) { alert('同步中断：' + e.message); });
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-sync]') : null;
    if (!b) return;
    var pid = b.getAttribute('data-sync');
    if (!!b.getAttribute('data-busy')) return;
    b.setAttribute('data-busy', '1');
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    body.set('provider_id', pid);
    fetch('<?= e(u('admin_files')) ?>&do=sync_start', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (d) {
      b.removeAttribute('data-busy');
      if (!d.ok) { alert('启动失败：' + (d.error && d.error.message)); return; }
      var job = d.data.job;
      paint(job, b.textContent.replace(/（.*$/, ''));
      if (job.state === 'running') step(job.id, b.textContent.replace(/（.*$/, ''));
      else setTimeout(function () { location.reload(); }, 600);
    }).catch(function (e) { b.removeAttribute('data-busy'); alert('请求失败：' + e.message); });
  });
})();
</script>
