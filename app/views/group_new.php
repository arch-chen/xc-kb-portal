<?php /** @var string $title */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">新建分组</h1>
    <p class="page-desc">
      分组就是一个独立的知识库。你建的分组自动拥有读写权限，可以直接往里加内容，
      也可以为它创建 API Key 交给 Agent 用。
    </p>
  </div>
</section>

<section class="panel">
  <form method="post" action="<?= e(u('group_new')) ?>" class="form">
    <?= csrf_field() ?>
    <label>分组名称
      <span class="field-hint">完整名称会自动带上「<?= e(nick_of(current_user() ?: [])) ?> · 」前缀，管理员一看就知道归谁</span>
      <input type="text" name="name" required maxlength="60" placeholder="例如：跨境电商 / 售后话术 / 内部 SOP" autofocus>
    </label>
    <label>一句话说明（可选）
      <input type="text" name="description" maxlength="120" placeholder="这个分组收什么内容">
    </label>
    <div class="form-actions">
      <button class="btn primary" type="submit">创建</button>
      <a class="btn" href="<?= e(u()) ?>">取消</a>
    </div>
  </form>
</section>

<div class="hint" style="max-width:60ch">
  提示：如果这个分组要给同事看，请让管理员在「分组授权」里给他开读权限。
  你自己建的 Key 只能访问这一个分组。
</div>
