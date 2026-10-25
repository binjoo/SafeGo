# SafeGo — 外链跳转中转页

拦截文章内容、评论内容与评论者网址中的外链，点击后先经过中转页 `/go/{key}` 提醒，再按 **黑名单 > 白名单 > 普通** 的优先级决定跳转行为。

## 安装

1. 从 [Releases](https://github.com/binjoo/SafeGo/releases) 下载 `SafeGo.zip`，或到 [插件合集](https://github.com/binjoo/typecho-plugins) 一次性下载全部插件
2. 解压得到 `SafeGo/` 目录，把**整个目录**上传到 Typecho 的 `/usr/plugins/` 下
3. 在后台「控制台 → 插件」中启用

> 请不要使用 GitHub 页面上的 "Source code" 压缩包：它的顶层目录带版本号（`SafeGo-1.0.0/`），而 Typecho 要求目录名与插件名一致，否则无法识别。
>
> 运行要求：Typecho 1.3+、PHP 8.0+（用到 `match` 与 `str_starts_with()`）。

## 发布

推送一个 `v*` 标签即自动发布（校验版本一致性 → 打包 → 创建 Release）：

```bash
git tag v1.0.1 && git push origin v1.0.1
```

本地也可以先跑一遍校验与打包，不推送任何东西：

```bash
bash scripts/release.sh check    # 版本、DocBlock、PHP 语法
bash scripts/release.sh build    # 生成 dist/SafeGo.zip 并断言 zip 结构
```

## 行为说明

| 分类 | 中转页行为 |
|---|---|
| 黑名单 | 禁止跳转，仅显示纯文本地址供复制（内置页附一键复制按钮） |
| 白名单 · 直接跳转 | 立即自动跳转（零等待） |
| 白名单 · 倒计时跳转 | 倒计时（默认 3 秒，可配置）后自动跳转，可点击"立即跳转" |
| 白名单 · 点击跳转 | 显示白名单提醒，点击"继续访问"后跳转 |
| 普通 | 显示安全提醒，必须点击"继续访问"才能跳转 |
| 站内链接 · 相同域名，不含子域名（默认） | 与站点域名完全一致的链接直接跳转，子域名链接视为外链 |
| 站内链接 · 相同域名，包含子域名 | 站点域名及其子域名（互为父子域名）均视为站内，直接跳转 |

## 设置项

- **生效范围**：文章内容网址 / 评论内容网址 / 评论者网址（复选框，默认全选）
- **站内链接判定**：相同域名，不含子域名（默认）/ 相同域名，包含子域名
- **白名单**：域名清单，每行一个，支持通配符 `*`
- **白名单跳转方式**：直接跳转 / 倒计时跳转 / 点击跳转（默认倒计时跳转）
- **自动跳转延时（秒）**：倒计时跳转的等待时长（默认 3，取值 1~60）
- **黑名单**：域名清单，每行一个，支持通配符 `*`；命中后禁止跳转仅显示地址

### 域名书写规则

- `example.com` 精确匹配该域名
- `*.example.com` 匹配任意层级子域（不含 `example.com` 自身，两者都拦请各写一行）
- `*` 可出现在任意位置，如 `*.github.io`
- 支持粘贴完整网址（自动提取主机）；`#` 开头为注释；匹配忽略大小写与端口
- 同一域名同时命中黑/白名单时，黑名单优先

## 技术信息

| 项目 | 值 |
|---|---|
| 中转页路由 | `/go/{key}`，URL 固定；`key` 为目标地址的 base64url 编码 |
| 路由名 | `safego`（内部标识，可用于 `Typecho\Router::url('safego', ['key' => …])` 反解析） |
| 配置存储键 | `plugin:SafeGo`（options 表） |
| 主题模板候选 | `go.php` / `redirect.php` / `safe.php` / `safego.php`（按序查找，详见下文） |
| 运行环境 | Typecho 1.3+（命名空间插件机制） |

## 自定义中转页（主题模板）

中转页支持**主题级自定义**：插件渲染中转页时，优先查找当前启用主题目录下的中转页模板（`go.php` 等候选文件名，见下），存在则完全交由主题模板渲染；不存在则使用插件内置页面。

```
优先级：主题模板（go.php 等候选名，见上）  >  插件内置页面
```

- 模板位置：`usr/themes/{当前主题}/` 目录，支持多个候选文件名，按以下优先级依次查找：

  ```
  go.php  >  redirect.php  >  safe.php  >  safego.php
  ```
- 删除模板文件即可随时回退到内置页面，无需改动插件
- 插件升级不会覆盖主题中的模板文件

模板与 `404.php` 采用**同一机制**：模板内 `$this` 就是 `Widget\Archive` 组件，因此可以直接 `$this->need('header.php')` / `$this->need('footer.php')` 复用主题头尾，**只需编写 main 部分的内容**即可融合主题风格；也可以不引头尾、自写完整 HTML。除 `$go` 外，`$this` 具备 `Widget\Archive` 的全部原生成员（`need()`、`options`、`is()` 等）可供模板使用。

### 模板上下文

通过 `$this->go` 获取本次跳转的全部上下文，跳转逻辑完全由模板自行实现：

| 键 | 类型 | 说明 |
|---|---|---|
| `url` | string | 目标地址。已由插件清洗（仅 http/https，已去除引号与尖括号）；`type=invalid` 时为空字符串。输出到 HTML 时仍请 `htmlspecialchars` 转义 |
| `type` | string | 目标类型：`invalid`（链接无效）/ `block`（黑名单）/ `white`（白名单）/ `normal`（普通外链） |
| `jump` | string | 跳转方式：`auto`（立即自动跳转）/ `countdown`（倒计时后自动跳转）/ `click`（点击按钮跳转）/ `copy`（禁止跳转，仅展示地址供复制）；`type=invalid` 时为空字符串 |
| `countdown` | int\|null | 倒计时时长（单位秒），仅 `jump=countdown` 时有值，其余为 `null` |
| `site` | string | 站点名称（原文，输出时请自行转义），可用于页面标题与页脚 |

### type × jump 对应关系

| type | jump | 触发条件 |
|---|---|---|
| `invalid` | （空） | 中转参数无法还原为合法链接 |
| `block` | `copy` | 目标域名命中黑名单 |
| `white` | `auto` | 命中白名单，且「白名单跳转方式」为直接跳转 |
| `white` | `countdown` | 命中白名单，且为倒计时跳转（`countdown` 为秒数） |
| `white` | `click` | 命中白名单，且为点击跳转 |
| `normal` | `click` | 未命中任何名单 |

### 各跳转方式的实现要求

跳转逻辑由模板自行实现，各方式的行为约定如下：

| jump | 模板必须做到 |
|---|---|
| `auto` | 自行实现零等待跳转。推荐 `<meta http-equiv="refresh" content="0;url=…">` 与 `location.replace()` 双保险 |
| `countdown` | 自行实现倒计时后跳转。推荐 `<meta http-equiv="refresh" content="N;url=…">` 兜底 + JS 倒计时；无 JS 环境下 meta refresh 也能完成跳转 |
| `click` | 提供指向 `$go['url']` 的链接按钮，由访客点击后跳转 |
| `copy` | **安全边界：严禁输出任何指向 `$go['url']` 的链接**，只以纯文本展示地址，可加复制按钮 |
| （空，invalid） | 展示错误提示，不渲染任何跳转元素 |

### 注意事项

1. 推荐写法与 `404.php` 相同：`$this->need('header.php')` → main 内容 → `$this->need('footer.php')`。注意 `header.php` 输出完毕后 `<head>` 已关闭，`auto` / `countdown` 的自动跳转请在页面尾部用 JS 实现（见下方示例）；若坚持使用 `<meta http-equiv="refresh">`，可改为不引头尾、自写完整 `<head>` 的独立 HTML，两种写法均受支持。无 JS 环境下自动跳转会失效，请知悉。
2. `$go['url']` 已经过插件清洗（仅 http/https，无引号与尖括号），可安全用于 JS 字符串与 HTML 属性，但按惯例输出时仍建议 `htmlspecialchars` 转义；`site` 为原文，必须转义。
3. 引主题头尾时 `<head>` 由主题 `header.php` 控制，`noindex` 等元信息按主题既有行为输出；改为自写完整 HTML 的写法时，请在 `<head>` 中自行加 `<meta name="robots" content="noindex, nofollow">`，避免中转页被搜索引擎收录。
4. `copy`（黑名单）是安全边界：页面上不能出现 `<a href="目标地址">`，否则黑名单形同虚设。
5. 不要对该页面做任何缓存（CDN / 服务器缓存请排除 `/go/` 路径），否则名单配置变更后旧页面会继续生效。

### 完整示例模板

以下模板实现全部六种场景，与主题头尾融合（样式类名请按你的主题自行调整）：

```php
<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
$go = $this->go;
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$js = json_encode($go['url'], JSON_UNESCAPED_SLASHES);
$this->need('header.php');
?>
<main class="go-page">
<?php if ('invalid' === $go['type']): ?>
  <h1>链接无效</h1>
  <p>该跳转链接无效或已失效，请返回并确认来源。</p>
<?php elseif ('copy' === $go['jump']): ?>
  <h1>禁止跳转</h1>
  <p>目标域名已被站长列入黑名单，本站不提供跳转。如需访问，请自行复制下方地址并谨慎评估风险。</p>
  <p><code id="go-target"><?php echo $e($go['url']); ?></code></p>
  <p><button type="button" id="go-copy">复制地址</button></p>
<?php elseif ('auto' === $go['jump']): ?>
  <h1>正在跳转</h1>
  <p>该链接在站长设置的白名单内，正在前往 <?php echo $e(parse_url($go['url'], PHP_URL_HOST) ?: ''); ?>，请稍候…</p>
<?php elseif ('countdown' === $go['jump']): ?>
  <h1>即将跳转</h1>
  <p>该链接在站长设置的白名单内，<b><span id="go-sec"><?php echo (int) $go['countdown']; ?></span> 秒</b>后自动前往：</p>
  <p><code><?php echo $e($go['url']); ?></code></p>
<?php else: ?>
  <?php if ('white' === $go['type']): ?>
    <h1>白名单链接</h1>
    <p>目标域名在站长设置的白名单内，请确认下方地址后继续访问。</p>
  <?php else: ?>
    <h1>即将离开本站</h1>
    <p>你即将离开本站，前往第三方网站。请注意保护个人信息与财产安全。</p>
  <?php endif; ?>
  <p><code><?php echo $e($go['url']); ?></code></p>
  <p><a href="<?php echo $e($go['url']); ?>">继续访问</a></p>
<?php endif; ?>
</main>
<?php if ('auto' === $go['jump']): ?>
<script>window.location.replace(<?php echo $js; ?>);</script>
<?php elseif ('countdown' === $go['jump']): ?>
<script>
(function () {
    var n = <?php echo (int) $go['countdown']; ?>, el = document.getElementById('go-sec');
    var timer = setInterval(function () {
        n -= 1;
        if (n <= 0) { clearInterval(timer); window.location.replace(<?php echo $js; ?>); }
        else if (el) { el.textContent = n; }
    }, 1000);
})();
</script>
<?php elseif ('copy' === $go['jump']): ?>
<script>
(function () {
    var btn = document.getElementById('go-copy');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var text = document.getElementById('go-target').textContent;
        var done = function () { btn.textContent = '已复制'; };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta); done();
        }
    });
})();
</script>
<?php endif; ?>
<?php $this->need('footer.php'); ?>
```

> `auto` / `countdown` 的跳转脚本放在 main 之后、`footer.php` 之前即可；`click` 由链接按钮触发；`copy` 不含任何指向目标的链接——这是四类跳转方式各自应有的形态。

## 兼容性说明

- **内置页面**由插件完整自渲染（内联 CSS、自动适配深色模式），不依赖主题，任何主题下表现一致；提供主题模板后则融合主题风格（见「自定义中转页」章节）
- **自定义中转页**：在当前主题目录放置 `go.php`（或 `redirect.php` / `safe.php` / `safego.php`）即可完全接管中转页外观（没有模板文件时使用内置页面，删除即回退），开发说明与示例模板见上方「自定义中转页（主题模板）」章节
- 评论者网址通过输出缓冲改写，仅匹配 Typecho 规范化后的评论者域名链接，不会影响侧栏友链等其它外链；若主题手写评论者链接且格式不一致，该链接保持原样
- 若当前主题自带链接跳转功能，建议关闭，避免双重中转
