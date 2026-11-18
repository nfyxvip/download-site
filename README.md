# 简易下载站（仿永硕E盘）

一个纯 PHP、无数据库、无框架的文件分享站，仿永硕E盘的经典紫色皮肤。

## 功能

- **文件夹浏览**：点文件夹进入子目录，面包屑导航；左侧有目录树，可直接跳转
- **文件表格**：文件名、修改时间、大小三列，点表头按名字/时间/大小排序
- **搜索**：顶栏搜索框，实时过滤当前目录里的文件名（和 h5ai 一样，只搜当前目录）
- **详情栏**：点中某个文件或文件夹，右侧显示大图标、名字、时间、大小，下面有当前路径的二维码，手机扫码就能打开
- **文件下载**：点文件直接下载；图片点一下在线预览（带灯箱）
- **网址收藏**：在某个目录放一个 `links.json`，显示为链接行
- **目录说明**：在某个目录放一个 `desc.txt`，显示在列表上方
- **后台管理**（`admin.php`）：登录后可上传文件、建文件夹、删除、重命名、改网址收藏与目录说明、改站名、改密码
- **文件夹加密**：后台给某个文件夹设密码，前台点进去要输密码；直接猜下载链接也进不去；管理员登录后直接打开

## 目录结构

```
download-site/            ← 网站根目录（放到 Nginx/Apache 的 web 目录）
├── index.php             ← 前台
├── admin.php             ← 后台
├── style.css             ← 样式
├── config.example.php    ← 配置示例
├── README.md
└── .gitignore

download-site-data/       ← 数据目录（网站目录的同级，不在 web 根目录内）
├── config.php            ← 真实配置（管理员密码哈希、文件夹密码）
└── files/                ← 所有文件和文件夹放这里
    ├── 图片/
    ├── 工具下载/
    └── 网址收藏/
```

数据目录放在 web 根目录之外，是有意为之：这样别人无法通过网址直接猜到文件地址（比如 `/files/秘密.txt`），文件夹加密才真正有效。

## 安装

1. 把 `download-site/` 放到网站根目录（Nginx `root` 指向它）。
2. 在网站目录的**同级**新建 `download-site-data/`，里面再建 `files/`。
3. 把 `config.example.php` 复制为 `download-site-data/config.php`，按里面的说明换掉管理员密码哈希。
   生成哈希：`php -r 'echo password_hash("你的密码", PASSWORD_DEFAULT), PHP_EOL;'`
4. 确保 PHP 能写 `download-site-data/`（`chmod -R a+rX`，上传目录需可写）。
5. 打开网站，点底部“后台管理”，用新密码登录，首次登录后在后台改掉默认密码。

Nginx 示例（PHP 8.3-FPM）：

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/download-site;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. {
        deny all;
    }
}
```

## 后台管理

打开 `admin.php`（前台底部也有“后台管理”链接），输入管理员密码登录。

后台可以做：

- **上传文件**：选文件（可多选）传到当前目录，单个不超过 200MB，同名文件不会覆盖；PHP 等脚本类文件与隐藏文件（`.htaccess` 等）禁止上传
- **新建文件夹**、**删除**（文件夹只删空的）、**重命名**
- **网址收藏**：改当前目录的 `links.json`
- **目录说明**：改当前目录的 `desc.txt`
- **站点设置**：改站名和副标题
- **修改密码**：改管理员密码
- **文件夹加密**：每个文件夹后面有个“🔒 加密”输入框，输个密码点一下就锁上了；想取消就点“🔒 已加密”按钮

## 文件夹加密

- 前台看到带锁的文件夹会显示一个红色 🔒，点进去要先输密码，输对了才能看里面的文件、下载
- 每个文件夹的密码可以不一样；子文件夹如果自己也设了密码，要再输一次
- 直接猜下载链接也下不了，会被拦到密码页
- 管理员登录后不用输文件夹密码，直接能打开（和永硕E盘一样）
- 密码只存哈希在 `config.php` 的 `folder_locks` 里，不存明文

## 安全说明

- 配置与文件都在 web 根目录之外，无法通过网址直接访问；所有下载/预览都走 PHP，并受文件夹密码保护
- 所有路径都限制在 `files/` 内，禁止 `../` 跳出
- 上传禁止脚本类扩展名与隐藏文件名；重命名同样受限
- 管理员登录用 `password_verify`（bcrypt），登录成功后更换 session ID，失败登录有 1 秒延迟；后台表单带 CSRF 令牌
- 输出一律 `htmlspecialchars` 转义；网址收藏只允许 `http://` 与 `https://`
- 错误信息不回显给浏览器（`display_errors` 已关）

注意：本站目前是 HTTP。如需 HTTPS，请用反向代理或 Let's Encrypt；不要把 `config.php` 放进 web 根目录，也不要把真实密码提交到 Git。

## 许可证

MIT。
