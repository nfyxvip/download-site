<?php
/**
 * 下载站配置示例
 * 用法：把这个文件复制为数据目录下的 config.php（默认在网站目录同级的 download-site-data/），
 * 然后把 admin_pass_hash 换成你自己的密码哈希。
 *
 * 生成密码哈希（在终端运行）：
 *   php -r 'echo password_hash("你的密码", PASSWORD_DEFAULT), PHP_EOL;'
 * 把输出的那一串贴到下面的 admin_pass_hash 里。
 */
return array (
  // 站名（显示在顶部）
  'site_name' => '我的下载站',
  // 副标题
  'site_desc' => '文件分享 · 点击下载',
  // 管理员密码哈希（不要写明文）。默认是 "admin123456" 的哈希，首次使用请立即改掉。
  'admin_pass_hash' => '$2y$10$7QXcq.raYMoa6n7/47exNOyv0yrk225osfsPAeQBC9TBLHDvfGfru',
  // 单个上传文件最大字节数（默认 200MB）
  'max_upload_bytes' => 209715200,
  // 文件夹密码：相对路径 => password_hash（在后台设置/移除，不用手动改）
  'folder_locks' => array (
  ),
);
