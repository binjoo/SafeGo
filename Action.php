<?php

namespace TypechoPlugin\SafeGo;

use Typecho\Common;
use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 外链中转页
 *
 * 完整自渲染页面（内联样式，不依赖主题），按域名分类决定跳转行为，
 * 保证在不同主题下呈现一致
 *
 * @package SafeGo
 */
class Action extends Widget implements ActionInterface
{
    /**
     * 入口函数
     */
    public function execute()
    {
    }

    /**
     * 解析中转参数并按分类渲染
     */
    public function action()
    {
        $key = (string) $this->request->get('key', '');
        $url = Plugin::decodeKey($key);

        if (null === $url) {
            $this->page(Plugin::MODE_INVALID, '');

            return;
        }

        $url = Common::safeUrl($url);
        if (!preg_match('#^https?://#i', $url)) {
            $this->page(Plugin::MODE_INVALID, '');

            return;
        }

        $this->page(Plugin::classify($url), $url);
    }

    /**
     * 渲染中转页并结束请求
     *
     * @param string $mode Plugin::MODE_* 分类
     * @param string $url 已清洗的目标地址，无效页传空串
     */
    private function page(string $mode, string $url): void
    {
        $options = Options::alloc();
        $site = htmlspecialchars((string) $options->title, ENT_QUOTES);
        $urlHtml = htmlspecialchars($url, ENT_QUOTES);
        $seconds = Plugin::autoJumpSeconds();

        @header('Content-Type: text/html; charset=UTF-8');

        /** 主题目录存在中转页模板时，仿 404 页机制交由主题渲染：模板内 $this 为 Widget\Archive，
         *  可直接 need('header.php') / need('footer.php') 融合主题风格，见插件 README「自定义中转页」章节 */
        $themeFile = self::locateThemeTemplate($options);
        if (null !== $themeFile) {
            [$type, $jump] = self::typeJump($mode);

            $archive = \Widget\Archive::allocWithAlias('safego', 'type=404');
            $archive->setArchiveTitle(_t('跳转提示'));
            $archive->go = [
                'url'       => $url,                     // 目标地址（type=invalid 时为空字符串）
                'type'      => $type,                    // invalid|block|white|normal
                'jump'      => $jump,                    // auto|countdown|click|copy（invalid 为空串）
                'countdown' => Plugin::MODE_COUNTDOWN === $mode ? $seconds : null,
                'site'      => (string) $options->title, // 站点名称（原文，输出时请自行转义）
            ];
            $archive->setThemeFile(basename($themeFile));
            $archive->render();

            // 借用的 404 句柄会将响应置为 404，中转页本身存在，恢复为 200
            $this->response->setStatus(200);

            exit;
        }

        $urlJs = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        [$meta, $script] = $this->jumpAssets($mode, $urlHtml, $urlJs, $seconds);

        $icon = '🔗';
        $title = '跳转提示';
        $msg = '';
        $meta = '';
        $script = '';
        $primary = '';
        $showUrlBox = true;
        $showCopy = false;
        $showBack = true;

        switch ($mode) {
            case Plugin::MODE_INVALID:
                $icon = '⚠️';
                $title = '链接无效';
                $msg = '该跳转链接无效或已失效，请返回并确认来源。';
                $showUrlBox = false;
                $primary = '';
                break;

            case Plugin::MODE_BLOCK:
                $icon = '🚫';
                $title = '禁止跳转';
                $msg = '目标域名已被站长列入黑名单，本站不提供跳转。如需访问，请自行复制下方地址并谨慎评估风险。';
                $showCopy = true;
                break;

            case Plugin::MODE_AUTO:
                $icon = '🚀';
                $title = '正在跳转';
                $msg = '该链接在站长设置的白名单内，正在前往外部链接，请稍候…';
                $showBack = false;
                break;

            case Plugin::MODE_COUNTDOWN:
                $icon = '⏱️';
                $title = '即将跳转';
                $msg = '该链接在站长设置的白名单内，<b><span id="go-sec">' . $seconds . '</span> 秒</b>后将自动前往下方链接，也可以点击按钮立即跳转。';
                $primary = '<a class="btn btn-primary" href="' . $urlHtml . '">立即跳转</a>';
                break;

            case Plugin::MODE_CLICK:
                $icon = '✅';
                $title = '白名单链接';
                $msg = '目标域名在站长设置的白名单内，请确认下方地址后继续访问。';
                $primary = '<a class="btn btn-primary" href="' . $urlHtml . '">继续访问</a>';
                break;

            case Plugin::MODE_CONFIRM:
            default:
                $icon = '🧭';
                $title = '即将离开本站';
                $msg = '你即将离开 <b>' . $site . '</b>，前往第三方网站。请注意保护个人信息与财产安全，谨慎访问陌生网站。';
                $primary = '<a class="btn btn-primary" href="' . $urlHtml . '">继续访问</a>';
                break;
        }

        if ($showUrlBox) {
            $copy = $showCopy
                ? '<button type="button" class="go-copy" id="go-copy">复制地址</button>'
                : '';
            $urlBox = '<div class="go-url"><code id="go-target">' . $urlHtml . '</code>' . $copy . '</div>';
        } else {
            $urlBox = '';
        }

        $back = $showBack
            ? '<button type="button" class="btn" onclick="history.back()">返回上一页</button>'
            : '';

        $script .= $showCopy ? $this->copyScript() : '';

        $style = <<<'CSS'
* { box-sizing: border-box; margin: 0; padding: 0 }
:root { --go-bg: #f3f4f6; --go-card: #ffffff; --go-text: #1f2328; --go-muted: #656d76; --go-border: #d8dee4; --go-primary: #2f6fed; --go-primary-hover: #1f5ae0; --go-code-bg: #f0f2f4 }
@media (prefers-color-scheme: dark) { :root { --go-bg: #14171c; --go-card: #1d2127; --go-text: #e6e8eb; --go-muted: #9aa0a6; --go-border: #33383f; --go-primary: #5b8def; --go-primary-hover: #7aa3f3; --go-code-bg: #262b33 } }
body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; background: var(--go-bg); color: var(--go-text); font: 15px/1.8 -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif; -webkit-font-smoothing: antialiased }
.go-card { width: 100%; max-width: 540px; background: var(--go-card); border: 1px solid var(--go-border); border-radius: 14px; padding: 36px 32px 24px; text-align: center; box-shadow: 0 6px 24px rgba(0, 0, 0, .07) }
.go-icon { font-size: 40px; line-height: 1; margin-bottom: 16px }
.go-title { font-size: 19px; font-weight: 600; margin-bottom: 10px }
.go-msg { color: var(--go-muted); font-size: 14px; margin-bottom: 18px }
.go-msg b { color: var(--go-text) }
.go-url { background: var(--go-code-bg); border: 1px solid var(--go-border); border-radius: 8px; padding: 10px 14px; margin-bottom: 6px; word-break: break-all; text-align: left }
.go-url code { font: 13px/1.8 ui-monospace, SFMono-Regular, Consolas, "Courier New", monospace; color: var(--go-text) }
.go-copy { display: block; border: 0; background: none; color: var(--go-primary); font-size: 13px; cursor: pointer; padding: 2px 0 0; margin: 0 auto }
.go-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 22px }
.btn { display: inline-block; padding: 8px 24px; border-radius: 8px; font-size: 14px; line-height: 1.8; text-decoration: none; cursor: pointer; border: 1px solid var(--go-border); background: var(--go-card); color: var(--go-text); font-family: inherit }
.btn:hover { border-color: var(--go-primary); color: var(--go-primary) }
.btn-primary { background: var(--go-primary); border-color: var(--go-primary); color: #fff }
.btn-primary:hover { background: var(--go-primary-hover); border-color: var(--go-primary-hover); color: #fff }
.go-footer { margin-top: 24px; font-size: 12px; color: var(--go-muted); opacity: .8 }
CSS;

        $titleHtml = htmlspecialchars($title, ENT_QUOTES);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>{$titleHtml} - {$site}</title>
{$meta}
<style>
{$style}
</style>
</head>
<body>
<main class="go-card">
<div class="go-icon">{$icon}</div>
<h1 class="go-title">{$titleHtml}</h1>
<p class="go-msg">{$msg}</p>
{$urlBox}
<div class="go-actions">{$primary}{$back}</div>
<p class="go-footer">{$site} · 外链安全跳转</p>
</main>
{$script}
</body>
</html>
HTML;

        echo $html;
        exit;
    }

    /** 中转页模板候选文件名，按优先级依次查找 */
    private const THEME_TEMPLATES = ['go.php', 'redirect.php', 'safe.php', 'safego.php'];

    /**
     * 内部分类映射为主题模板的类型与跳转方式
     *
     * @return array{string, string} [type, jump]
     */
    private static function typeJump(string $mode): array
    {
        switch ($mode) {
            case Plugin::MODE_INVALID:
                return ['invalid', ''];
            case Plugin::MODE_BLOCK:
                return ['block', 'copy'];
            case Plugin::MODE_AUTO:
                return ['white', 'auto'];
            case Plugin::MODE_COUNTDOWN:
                return ['white', 'countdown'];
            case Plugin::MODE_CLICK:
                return ['white', 'click'];
            default:
                return ['normal', 'click'];
        }
    }

    /**
     * 在当前主题目录按优先级查找中转页模板
     */
    private static function locateThemeTemplate(Options $options): ?string
    {
        foreach (self::THEME_TEMPLATES as $name) {
            $file = $options->themeFile((string) $options->theme, $name);
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * 生成自动跳转所需的 meta 与 script，仅 auto / countdown 模式非空
     *
     * @return array{string, string} [meta 标签, script 标签]
     */
    private function jumpAssets(string $mode, string $urlHtml, string $urlJs, int $seconds): array
    {
        $meta = '';
        $script = '';

        if (Plugin::MODE_AUTO === $mode) {
            $meta = '<meta http-equiv="refresh" content="0;url=' . $urlHtml . '">';
            $script = '<script>window.location.replace(' . $urlJs . ');</script>';
        } elseif (Plugin::MODE_COUNTDOWN === $mode) {
            $meta = '<meta http-equiv="refresh" content="' . $seconds . ';url=' . $urlHtml . '">';
            $script = '<script>(function () { var n = ' . $seconds . ', el = document.getElementById(\'go-sec\');'
                . ' var timer = setInterval(function () { n -= 1;'
                . ' if (n <= 0) { clearInterval(timer); window.location.replace(' . $urlJs . '); }'
                . ' else if (el) { el.textContent = n; } }, 1000); })();</script>';
        }

        return [$meta, $script];
    }

    /**
     * 黑名单页的复制地址脚本
     */
    private function copyScript(): string
    {
        return <<<'HTML'
<script>
(function () {
    var btn = document.getElementById('go-copy');
    if (!btn) { return; }
    var done = function () {
        btn.textContent = '已复制';
        setTimeout(function () { btn.textContent = '复制地址'; }, 1500);
    };
    var fallback = function (text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
    };
    btn.addEventListener('click', function () {
        var text = document.getElementById('go-target').textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(text); });
        } else {
            fallback(text);
        }
    });
})();
</script>
HTML;
    }
}
