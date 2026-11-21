<?php
/**
 * 通用界面小部件（视图层复用）
 *
 * 目前只有「感叹号提示气泡」一种：把冗长的解释收进图标里，默认不可见，
 * 鼠标悬停 / 键盘聚焦 / 触屏点一下才展开，让页面保持干净。
 * 纯 CSS 实现（assets/app.css 的 .tip 段），不依赖脚本，SSR 与 AJAX 片段都能用。
 */

/**
 * 提示内容消毒：气泡里允许少量标签，但不允许能执行脚本的东西。
 *
 * 调用方本来就该用 e() 转义动态值，这里只是第二道闸——万一有人把用户输入
 * 直接拼进提示里，也不至于变成存储型 XSS。
 */
function tip_clean(string $html): string
{
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|svg)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|svg)\b[^>]*/?>#i', '', (string)$html);
    // 行内事件处理器：onclick= / onerror= 之类
    $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', (string)$html);
    // javascript: 伪协议
    $html = preg_replace('#javascript\s*:#i', '', (string)$html);
    return (string)$html;
}

/**
 * 感叹号提示图标 + 悬浮气泡。
 *
 * @param string $html  气泡内容，视为**可信 HTML**（动态值请自行 e() 转义）。
 *                      支持 <p> <b> <code> <pre> <ul> <li>；不含任何已知安全标签的
 *                      纯文本会自动包 <p> 并转义。
 * @param string $pos   位置修正：'' 默认（左下展开）｜'right' 右对齐｜'up' 向上展开
 * @param string $label 无障碍标签，读屏软件念的内容
 */
function tip(string $html, string $pos = '', string $label = '说明'): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    // 只有确实含有已知安全标签才按 HTML 处理；否则整段当纯文本转义，
    // 避免「想把 <script> 当例子展示」结果被当成标签执行、或被消毒函数吞掉。
    if (!preg_match('#<\s*(p|b|strong|i|em|code|pre|ul|ol|li|br|span)\b#i', $html)) {
        $html = '<p>' . nl2br(e($html)) . '</p>';
    } else {
        $html = tip_clean($html);
    }
    return tip_shell($html, $pos, $label);
}

/**
 * 纯文本版：内容自动转义，换行转 <br>。省去调用方手写 e()。
 *
 * 注意这里不走 tip()——tip() 会对含标签的内容做二次消毒，而本函数的入参
 * 是「原始纯文本」，先转义再交给 tip() 就会被转义两次（& 变成 &amp;amp;）。
 */
function tip_text(string $text, string $pos = '', string $label = '说明'): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    return tip_shell('<p>' . nl2br(e($text)) . '</p>', $pos, $label);
}

/** 组装外壳：tip() 与 tip_text() 共用的最后一步，内容视为已消毒 HTML。 */
function tip_shell(string $safeHtml, string $pos, string $label): string
{
    $cls = 'tip';
    if ($pos === 'right') {
        $cls .= ' tip-right';
    } elseif ($pos === 'up') {
        $cls .= ' tip-up';
    }
    return '<span class="' . $cls . '" tabindex="0" role="note" aria-label="' . e($label) . '">'
        . '<span class="tip-mark" aria-hidden="true">?</span>'
        . '<span class="tip-pop"><span class="tip-card">' . $safeHtml . '</span></span>'
        . '</span>';
}

/**
 * 标题旁的提示：把「面板名 + 感叹号」组合好，省得每个视图写一遍。
 * 例：<?= panel_title('接口地址', '两者不能混用…') ?>
 */
function panel_title(string $title, string $tipHtml = '', string $tag = ''): string
{
    $out = '<h2 class="panel-title">' . e($title);
    if ($tag !== '') {
        $out .= ' <span class="tag">' . e($tag) . '</span>';
    }
    if ($tipHtml !== '') {
        $out .= ' ' . tip($tipHtml, '', $title . ' 说明');
    }
    return $out . '</h2>';
}

/**
 * 表单标签后的提示：返回可直接插进 <label> 的行内片段。
 * 只适用于行内（flex-row）标签，比如 .check-line。
 * 例：<label class="check-line">接收通知<?= label_tip('关掉后…') ?></label>
 */
function label_tip(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    return ' ' . tip_text($text, '', '字段说明');
}

/**
 * 表单字段标签：文字与提示图标包在同一个 <span> 里。
 * .form label 是 flex-column，文字与图标若各自成为 flex item 会被拆成两行，
 * 所以竖排标签必须用这个函数，不能直接用 label_tip()。
 * 例：<label><?= field_label('昵称', '全站唯一…') ?><input …></label>
 */
function field_label(string $text, string $tipText = '', string $tag = ''): string
{
    $out = '<span class="lb">' . e($text);
    if ($tag !== '') {
        $out .= ' <span class="field-hint">' . e($tag) . '</span>';
    }
    if (trim($tipText) !== '') {
        $out .= ' ' . tip_text($tipText, '', $text . ' 说明');
    }
    return $out . '</span>';
}
