<?php

namespace TypechoPlugin\SafeGo;

use Typecho\Common;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Archive;
use Widget\Base\Comments;
use Widget\Base\Contents;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 将文章内容、评论内容与评论者网址中的外链改写为中转页链接。
 *
 * @package SafeGo
 * @author 冰剑
 * @version 1.0.0
 * @link https://digu.plus
 * @since 1.3.0
 */
class Plugin implements PluginInterface
{
    /** 中转页路由前缀 */
    private const GO_PATH = '/go/';

    /** 锚点匹配，href 前必须有空白，避免误匹配 data-href 之类的属性 */
    private const ANCHOR_PATTERN = '/(<a\b[^>]*?\s+href\s*=\s*)(["\'])([^"\']+)\2/i';

    /** 处理范围默认值（全部来源） */
    public const SCOPE_ALL = ['content', 'commentContent', 'commentAuthor'];

    /** 分类：链接无效 */
    public const MODE_INVALID = 'invalid';
    /** 分类：黑名单，禁止跳转，仅展示地址 */
    public const MODE_BLOCK = 'block';
    /** 分类：白名单，进入中转页立即跳转 */
    public const MODE_AUTO = 'auto';
    /** 分类：白名单，倒计时后自动跳转 */
    public const MODE_COUNTDOWN = 'countdown';
    /** 分类：白名单，点击按钮后跳转 */
    public const MODE_CLICK = 'click';
    /** 分类：普通外链，必须点击确认后跳转 */
    public const MODE_CONFIRM = 'confirm';

    /** 输出缓冲是否由本插件开启 */
    private static bool $bufferStarted = false;

    /** pageBegin 时的缓冲层级，用于安全回收本层缓冲 */
    private static int $obLevel = 0;

    /** 当前页面出现的评论者网址集合（经 safeUrl 规范化） */
    private static array $authorUrls = [];

    /**
     * 激活插件，注册内容渲染钩子与中转页路由
     */
    public static function activate()
    {
        \Typecho\Plugin::factory(Contents::class)->contentEx = [self::class, 'rewriteContent'];
        \Typecho\Plugin::factory(Comments::class)->contentEx = [self::class, 'rewriteComment'];
        \Typecho\Plugin::factory(Comments::class)->filter = [self::class, 'collectAuthorUrl'];
        \Typecho\Plugin::factory(Archive::class)->beforeRender = [self::class, 'pageBegin'];
        \Typecho\Plugin::factory(Archive::class)->afterRender = [self::class, 'pageEnd'];

        Helper::addRoute('safego', self::GO_PATH . '[key:alpha]', Action::class, 'action');

        return _t('SafeGo 已启用，请前往插件设置完成跳转方式与黑白名单配置');
    }

    /**
     * 禁用插件，注销路由
     */
    public static function deactivate()
    {
        Helper::removeRoute('safego');
    }

    /**
     * 插件设置面板
     *
     * @param Form $form 配置表单
     */
    public static function config(Form $form)
    {
        $general = new Layout();
        $general->html(_t('<h3>基本设置</h3>'));
        $form->addItem($general);

        $scope = new Checkbox(
            'rewriteScope',
            [
                'content'        => _t('文章内容网址'),
                'commentContent' => _t('评论内容网址'),
                'commentAuthor'  => _t('评论者网址'),
            ],
            self::SCOPE_ALL,
            _t('生效范围'),
            _t('选择需要经过中转页处理的链接来源，未勾选的来源保持原始链接。')
        );
        $form->addInput($scope);

        $sameOrigin = new Radio(
            'sameOriginScope',
            [
                'exact'     => _t('相同域名，不含子域名'),
                'subdomain' => _t('相同域名，包含子域名'),
            ],
            'exact',
            _t('站内链接判定'),
            _t('判定哪些链接属于站内链接：站内链接不经过中转页，保持原样直接跳转。')
        );
        $form->addInput($sameOrigin);

        $white = new Layout();
        $white->html(_t('<h3>白名单</h3>'));
        $form->addItem($white);

        $whitelist = new Textarea(
            'whitelist',
            null,
            '',
            _t('白名单清单'),
            _t('信任的域名清单，每行一个，支持通配符 <code>*</code>，如 <code>example.com</code>（精确匹配）、<code>*.example.com</code>（任意子域，不含自身）。命中的链接进入中转页后按「白名单跳转方式」处理。')
        );
        $form->addInput($whitelist);

        $mode = new Radio(
            'whitelistMode',
            [
                'direct'    => _t('直接跳转（进入中转页后立即跳转）'),
                'countdown' => _t('倒计时跳转（进入中转页，倒计时结束后自动跳转）'),
                'click'     => _t('点击跳转（进入中转页，点击按钮后跳转）'),
            ],
            'countdown',
            _t('白名单跳转方式'),
            _t('命中白名单的链接在中转页采用哪种跳转方式。')
        );
        $form->addInput($mode->multiMode());

        $seconds = new Number(
            'autoJumpSeconds',
            null,
            3,
            _t('自动跳转延时（秒）'),
            _t('白名单「倒计时跳转」的等待时长，取值 1~60 秒。')
        );
        $form->addInput($seconds);

        $black = new Layout();
        $black->html(_t('<h3>黑名单</h3>'));
        $form->addItem($black);

        $blacklist = new Textarea(
            'blacklist',
            null,
            '',
            _t('黑名单清单'),
            _t('屏蔽的域名清单，每行一个，支持通配符 <code>*</code>，书写规则与白名单相同。命中的链接禁止跳转，中转页仅显示地址供复制。黑名单优先于白名单。')
        );
        $form->addInput($blacklist);
    }

    /**
     * 用户个人设置（本插件无个人配置）
     *
     * @param Form $form 配置表单
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 文章内容渲染：改写正文中的外链
     *
     * @param string $content 渲染后的正文 HTML
     * @return string
     */
    public static function rewriteContent($content, $widget)
    {
        return self::scopeOn('content') ? self::rewriteLinks((string) $content) : $content;
    }

    /**
     * 评论内容渲染：改写评论正文中的外链
     *
     * @param string $text 渲染后的评论 HTML
     * @return string
     */
    public static function rewriteComment($text, $widget)
    {
        return self::scopeOn('commentContent') ? self::rewriteLinks((string) $text) : $text;
    }

    /**
     * 评论行加载：收集本页出现的评论者网址
     *
     * @param array $row 评论行数据
     * @return array
     */
    public static function collectAuthorUrl($row, $widget)
    {
        if (self::scopeOn('commentAuthor') && !empty($row['url'])) {
            $safe = Common::safeUrl((string) $row['url']);
            if (preg_match('#^https?://#i', $safe)) {
                self::$authorUrls[$safe] = true;
            }
        }

        return $row;
    }

    /**
     * 主题模板渲染前：开启输出缓冲，用于改写评论者网址链接
     */
    public static function pageBegin($archive)
    {
        if (!self::scopeOn('commentAuthor') || self::$bufferStarted) {
            return;
        }

        self::$bufferStarted = true;
        self::$obLevel = ob_get_level();
        ob_start([self::class, 'obProcess']);
    }

    /**
     * 主题模板渲染后：冲刷输出缓冲
     */
    public static function pageEnd($archive)
    {
        if (!self::$bufferStarted) {
            return;
        }

        self::$bufferStarted = false;

        // 先冲刷本层之上的其它缓冲（内容汇入本层），最后经由回调处理并结束本层；
        // 不能触动更外层的缓冲，否则会提前发送 HTTP 状态头
        while (ob_get_level() > self::$obLevel + 1) {
            ob_end_flush();
        }

        if (ob_get_level() === self::$obLevel + 1) {
            ob_end_flush();
        }
    }

    /**
     * 输出缓冲回调：仅改写 href 命中评论者网址集合的锚点，页面其它外链不受影响
     *
     * @param string $html 整页 HTML
     * @return string
     */
    public static function obProcess(string $html): string
    {
        if (empty(self::$authorUrls) || false === stripos($html, '<a')) {
            return $html;
        }

        return preg_replace_callback(self::ANCHOR_PATTERN, function (array $m) {
            $href = htmlspecialchars_decode(trim($m[3]), ENT_QUOTES);
            if (!isset(self::$authorUrls[$href])) {
                return $m[0];
            }

            $go = self::buildGoUrl($m[3]);

            return null === $go ? $m[0] : $m[1] . $m[2] . $go . $m[2];
        }, $html) ?? $html;
    }

    /**
     * 判断某个来源是否开启处理
     *
     * @param string $scope 来源标识
     */
    private static function scopeOn(string $scope): bool
    {
        $selected = self::option('rewriteScope');
        if (null === $selected || '' === $selected) {
            $selected = self::SCOPE_ALL;
        }

        return in_array($scope, (array) $selected, true);
    }

    /**
     * 改写 HTML 中所有符合条件的外链
     */
    private static function rewriteLinks(string $html): string
    {
        if ('' === $html || false === stripos($html, '<a')) {
            return $html;
        }

        return preg_replace_callback(self::ANCHOR_PATTERN, function (array $m) {
            $go = self::buildGoUrl($m[3]);

            return null === $go ? $m[0] : $m[1] . $m[2] . $go . $m[2];
        }, $html) ?? $html;
    }

    /**
     * 将锚点 href 转为中转页链接；不满足改写条件时返回 null（保持原样）
     */
    private static function buildGoUrl(string $href): ?string
    {
        $url = htmlspecialchars_decode(trim($href), ENT_QUOTES);
        if ('' === $url) {
            return null;
        }

        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ('' !== $scheme && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if ('' === $scheme && !str_starts_with($url, '//')) {
            return null;
        }

        $siteParts = parse_url((string) Options::alloc()->siteUrl);
        $siteHost = is_array($siteParts) ? (string) ($siteParts['host'] ?? '') : '';
        if (self::sameOriginExempt($parts, $siteHost)) {
            return null;
        }

        return Common::url(self::GO_PATH . self::encodeKey($url), (string) Options::alloc()->siteUrl);
    }

    /**
     * 是否属于免于中转的站内链接，判定范围由「站内链接判定」配置决定
     *
     * @param array $parts 目标地址 parse_url 结果
     * @param string $siteHost 站点域名
     */
    private static function sameOriginExempt(array $parts, string $siteHost): bool
    {
        if (empty($parts['host'])) {
            return false;
        }

        return self::hostExempt((string) $parts['host'], $siteHost, self::sameOriginScope());
    }

    /**
     * 按判定范围比较目标域名与站点域名
     *
     * @param string $scope exact 仅相同域名 / subdomain 包含子域名
     */
    private static function hostExempt(string $host, string $siteHost, string $scope): bool
    {
        if ('' === $siteHost || '' === $host) {
            return false;
        }

        $host = strtolower($host);
        $siteHost = strtolower($siteHost);

        // 仅相同域名：主机名完全一致
        if ($host === $siteHost) {
            return true;
        }

        // 包含子域名：站点与目标互为父子域名时同样视为站内（如 example.com 与 www.example.com）
        if ('subdomain' === $scope
            && (str_ends_with($host, '.' . $siteHost) || str_ends_with($siteHost, '.' . $host))) {
            return true;
        }

        return false;
    }

    /**
     * 站内链接判定范围：exact 仅相同域名 / subdomain 包含子域名
     */
    private static function sameOriginScope(): string
    {
        $scope = (string) self::option('sameOriginScope');

        return in_array($scope, ['exact', 'subdomain'], true) ? $scope : 'exact';
    }

    /**
     * 中转页分类：黑名单 > 白名单 > 普通
     *
     * @param string $url 已清洗的目标地址
     * @return string MODE_* 常量
     */
    public static function classify(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return self::MODE_INVALID;
        }
        $host = strtolower($parts['host']);

        $blacklist = (string) self::option('blacklist');
        if ('' !== trim($blacklist) && self::matchList($host, $blacklist)) {
            return self::MODE_BLOCK;
        }

        $whitelist = (string) self::option('whitelist');
        if ('' !== trim($whitelist) && self::matchList($host, $whitelist)) {
            return match (self::option('whitelistMode')) {
                'direct' => self::MODE_AUTO,
                'click' => self::MODE_CLICK,
                default => self::MODE_COUNTDOWN,
            };
        }

        return self::MODE_CONFIRM;
    }

    /**
     * 域名通配符匹配，忽略大小写，* 匹配任意字符
     */
    private static function matchList(string $host, string $config): bool
    {
        foreach (self::domainLines($config) as $pattern) {
            if (false === strpos($pattern, '*')) {
                if ($host === $pattern) {
                    return true;
                }
            } elseif (preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析域名清单：每行一个，忽略空行与 # 注释，兼容粘贴完整网址
     */
    private static function domainLines(string $config): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $config) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = strtolower(trim($line));
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $line = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $line) ?? '';
            $line = preg_replace('~[/?#].*$~', '', $line) ?? '';
            $line = trim($line, '.');
            if ('' !== $line) {
                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * URL 转 base64url（仅含 [A-Za-z0-9_-]，与路由 [key:alpha] 兼容）
     */
    public static function encodeKey(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /**
     * base64url 还原 URL，非法输入返回 null
     */
    public static function decodeKey(string $key): ?string
    {
        if ('' === $key || strlen($key) > 2048 || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return null;
        }

        $base64 = strtr($key, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $url = base64_decode($base64, true);

        return false === $url || '' === $url ? null : $url;
    }

    /**
     * 读取插件配置项
     *
     * @return mixed
     */
    private static function option(string $key)
    {
        static $config = null;

        if (null === $config) {
            $config = Options::alloc()->plugin('SafeGo');
        }

        return $config->{$key} ?? null;
    }

    /**
     * 白名单倒计时秒数，钳制在 1~60
     */
    public static function autoJumpSeconds(): int
    {
        return max(1, min(60, (int) (self::option('autoJumpSeconds') ?: 3)));
    }
}
