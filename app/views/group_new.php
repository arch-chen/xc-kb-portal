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
    <label><?= field_label('分组名称', '完整名称会自动带上「' . nick_of(current_user() ?: []) . ' · 」前缀，管理员一看就知道归谁。分组名可以改，改名后 API 返回的标识不变。') ?>
      <input type="text" name="name" required maxlength="60" placeholder="例如：跨境电商 / 售后话术 / 内部 SOP" autofocus>
    </label>
    <label><?= field_label('一句话说明（可选）', '写清这个分组收什么内容，之后在列表、搜索与 API 返回里都会显示。') ?>
      <input type="text" name="description" maxlength="120" placeholder="这个分组收什么内容">
    </label>
    <div class="form-actions">
      <button class="btn primary" type="submit">创建</button>
      <a class="btn" href="<?= e(u()) ?>">取消</a>
    </div>
  </form>
</section>

<div class="hint muted tiny">
  分组建好后想给同事看，让管理员在「分组授权」里给他开读权限即可。你自己建的 Key 只能访问这一个分组。
</div>
