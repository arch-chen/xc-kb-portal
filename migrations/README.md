# 迁移脚本

升级时按文件名排序执行，执行过会记录在 `settings` 表的 `mig_<文件名>` 里，不会重复跑。

命名用版本号，例如 `1.1.0.php`。文件必须 `return` 一个可调用对象，参数是 PDO：

```php
<?php // migrations/1.1.0.php
return function (PDO $pdo): void {
    $p = db_prefix();
    // 加字段
    $pdo->exec("ALTER TABLE {$p}entries ADD COLUMN tags VARCHAR(255) NULL");
    // 回填数据
    $pdo->exec("UPDATE {$p}entries SET tags = '' WHERE tags IS NULL");
};
```

注意：

- 只会执行版本号**高于当前版本**的迁移（`tools/build_update.php` 按这个规则打包）
- 写 SQL 时用 `db_prefix()`，别写死表名，否则改过前缀的站点会失败
- 一次升级多个迁移时按文件名升序执行，所以 `1.1.0.php` 会早于 `1.2.0.php`
- MySQL 的 `ALTER TABLE` 不支持 `IF NOT EXISTS`，重复执行会报错，靠 `mig_*` 记录避免
