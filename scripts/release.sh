#!/usr/bin/env bash
#
# SafeGo 发布脚本
#
# 子命令：
#   meta     打印本次发布的 tag 与版本号
#   check    校验 tag、@package/@version/@since 一致性、PHP 语法
#   build    打包 dist/SafeGo.zip（顶层目录为 SafeGo/）并断言结构
#   notes    输出 Release 说明正文
#   publish  创建或更新 GitHub Release（需要 GH_TOKEN 或已 gh auth login）
#
# 用法：bash scripts/release.sh <子命令> [tag]
#   tag 省略时自动解析：push tag 触发时取 GITHUB_REF_NAME，否则取本地最高的 v* tag

set -euo pipefail

PLUGIN_NAME="${PLUGIN_NAME:-SafeGo}"
TYPECHO_FLOOR="${TYPECHO_FLOOR:-1.3.0}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

info() { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ⚠ %s\n' "$*"; }
step() { printf '\n[%s]\n' "$*"; }
fail() { printf '\n✗ %s\n' "$*" >&2; exit 1; }

# 解析本次发布的 tag
resolve_tag() {
    local given="${1:-}"

    if [ -n "$given" ]; then
        printf '%s\n' "$given"
        return
    fi

    if [ "${GITHUB_REF_TYPE:-}" = "tag" ] && [ -n "${GITHUB_REF_NAME:-}" ]; then
        printf '%s\n' "$GITHUB_REF_NAME"
        return
    fi

    git tag --list 'v*' --sort=-v:refname | head -n 1
}

TAG="$(resolve_tag "${2:-}")"

# 主版本号比较，输出 -1 / 0 / 1
compare_version() {
    local -a left right
    IFS='.' read -r -a left <<< "$1"
    IFS='.' read -r -a right <<< "$2"

    local i x y
    for i in 0 1 2; do
        x="${left[$i]:-0}"
        y="${right[$i]:-0}"

        if [ "$x" -gt "$y" ]; then printf '1\n'; return; fi
        if [ "$x" -lt "$y" ]; then printf '%s\n' '-1'; return; fi
    done

    printf '0\n'
}

require_tag() {
    [ -n "$TAG" ] || fail "找不到任何 v* tag，请先打 tag 再发布"
    git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null \
        || fail "本地不存在 tag $TAG（CI 中请用 fetch-depth: 0 检出）"
    VERSION="${TAG#v}"
}

# 读取指定 tag 里 Plugin.php 的 DocBlock 字段
plugin_field() {
    git show "$TAG:Plugin.php" \
        | sed -n "s|^[[:space:]]*\*[[:space:]]*@$1[[:space:]]\{1,\}||p" \
        | head -n 1 \
        | tr -d '\r'
}

# 列出 zip 条目，依次尝试 unzip / tar / PHP
list_zip() {
    if command -v unzip >/dev/null 2>&1; then
        unzip -Z1 "$1"
    elif tar -tf "$1" >/dev/null 2>&1; then
        tar -tf "$1"
    elif command -v php >/dev/null 2>&1 && php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' 2>/dev/null; then
        php -r '$z = new ZipArchive(); $z->open($argv[1]); for ($i = 0; $i < $z->numFiles; $i++) { echo $z->getNameIndex($i), "\n"; }' "$1"
    else
        fail "无法读取 zip，请安装 unzip 或启用 PHP 的 zip 扩展"
    fi
}

cmd_check() {
    require_tag
    step "校验 $TAG（版本 $VERSION）"

    local package version since
    package="$(plugin_field package)"
    version="$(plugin_field version)"
    since="$(plugin_field since)"

    [ "$package" = "$PLUGIN_NAME" ] || fail "@package 是「$package」，与插件名 $PLUGIN_NAME 不一致"
    info "@package = $package"

    [ -n "$version" ] || fail "Plugin.php 的 DocBlock 缺少 @version"
    [ "$version" = "$VERSION" ] || fail "@version「$version」与 tag「$TAG」不一致，请修正后再发布"
    info "@version = $version（与 tag 一致）"

    if [ -n "$since" ]; then
        if [ "$(compare_version "$since" "$TYPECHO_FLOOR")" = "1" ]; then
            warn "@since 是 $since，高于声明基线 $TYPECHO_FLOOR"
        else
            info "@since = $since（Typecho $TYPECHO_FLOOR 及以上可用）"
        fi
    else
        warn "DocBlock 未声明 @since"
    fi

    # PHP 语法检查：直接校验 tag 里的内容，避免工作区与标签不一致
    local checked=0 broken=0 file
    while IFS= read -r file; do
        checked=$((checked + 1))

        if ! git show "$TAG:$file" | php -l >/dev/null 2>&1; then
            printf '  ✗ 语法错误：%s\n' "$file"
            git show "$TAG:$file" | php -l || true
            broken=$((broken + 1))
        fi
    done < <(git ls-tree -r --name-only "$TAG" | grep '\.php$')

    [ "$broken" = 0 ] || fail "存在 PHP 语法错误"
    [ "$checked" -gt 0 ] || fail "tag $TAG 里没有找到任何 PHP 文件"
    info "PHP 语法检查通过（$checked 个文件）"

    # 目录守卫（AGENTS.md 约定，缺少只提示不阻断）
    while IFS= read -r file; do
        git show "$TAG:$file" | grep -q '__TYPECHO_ROOT_DIR__' \
            || warn "$file 缺少 __TYPECHO_ROOT_DIR__ 守卫"
    done < <(git ls-tree -r --name-only "$TAG" | grep '\.php$')
}

cmd_build() {
    require_tag
    step "打包 $PLUGIN_NAME.zip（顶层目录 $PLUGIN_NAME/）"

    mkdir -p dist
    git archive --format=zip --prefix="$PLUGIN_NAME/" -o "dist/$PLUGIN_NAME.zip" "$TAG"

    local entries count stray
    entries="$(list_zip "dist/$PLUGIN_NAME.zip")"
    count="$(printf '%s\n' "$entries" | grep -c . || true)"
    [ "$count" -gt 0 ] || fail "打包结果为空"

    stray="$(printf '%s\n' "$entries" | grep -v "^$PLUGIN_NAME/" || true)"
    [ -z "$stray" ] || fail "顶层结构错误，以下条目不在 $PLUGIN_NAME/ 之下：$(printf '%s ' $stray)"

    printf '%s\n' "$entries" | grep -qx "$PLUGIN_NAME/Plugin.php" \
        || fail "zip 中缺少 $PLUGIN_NAME/Plugin.php"

    if printf '%s\n' "$entries" | grep -q '^[^/]*/\.github/'; then
        warn "zip 里含 .github/，请检查 .gitattributes 的 export-ignore 是否生效"
    fi

    info "共 $count 个条目，顶层目录为 $PLUGIN_NAME/"
    info "产物 dist/$PLUGIN_NAME.zip（$(du -h "dist/$PLUGIN_NAME.zip" | cut -f1)）"

    if command -v sha256sum >/dev/null 2>&1; then
        info "sha256 $(sha256sum "dist/$PLUGIN_NAME.zip" | cut -d' ' -f1)"
    fi
}

cmd_notes() {
    require_tag

    local -a tags
    mapfile -t tags < <(git tag --list 'v*' --sort=-v:refname)

    local prev="" i
    for i in "${!tags[@]}"; do
        if [ "${tags[$i]}" = "$TAG" ] && [ $((i + 1)) -lt ${#tags[@]} ]; then
            prev="${tags[$((i + 1))]}"
        fi
    done

    local range="$TAG"
    [ -n "$prev" ] && range="$prev..$TAG"

    printf '## 安装方法\n\n'
    printf '1. 下载附件 `%s.zip`\n' "$PLUGIN_NAME"
    printf '2. 解压得到 `%s/` 目录\n' "$PLUGIN_NAME"
    printf '3. 上传到 Typecho 的 `/usr/plugins/` 下\n'
    printf '4. 后台「控制台 → 插件」中启用\n\n'
    printf '> 请勿使用 GitHub 自动生成的 Source code 压缩包：顶层目录带版本号，Typecho 无法识别。\n\n'
    printf '## 变更\n\n'
    git log --pretty='- %s' --no-merges "$range" || true
    printf '\n'

    if [ -n "$prev" ]; then
        printf '对比：`%s...%s`\n' "$prev" "$TAG"
    else
        printf '首次发布\n'
    fi
}

cmd_publish() {
    require_tag
    [ -f "dist/$PLUGIN_NAME.zip" ] || fail "还没有打包，请先执行 build"

    if [ -z "${GH_TOKEN:-}${GITHUB_TOKEN:-}" ] && ! gh auth status >/dev/null 2>&1; then
        fail "publish 需要 GH_TOKEN / GITHUB_TOKEN，或先 gh auth login"
    fi

    cmd_notes > "dist/release-notes.md"

    if gh release view "$TAG" >/dev/null 2>&1; then
        step "更新已有 Release $TAG"
        gh release upload "$TAG" "dist/$PLUGIN_NAME.zip" --clobber
        gh release edit "$TAG" --title "$PLUGIN_NAME $VERSION" --notes-file "dist/release-notes.md"
        info "附件已覆盖，说明已更新"
    else
        step "创建 Release $TAG"
        gh release create "$TAG" "dist/$PLUGIN_NAME.zip" --title "$PLUGIN_NAME $VERSION" --notes-file "dist/release-notes.md"
        info "已发布 $TAG"
    fi
}

case "${1:-}" in
    meta)    require_tag; printf 'TAG=%s\nVERSION=%s\n' "$TAG" "$VERSION" ;;
    check)   cmd_check ;;
    build)   cmd_build ;;
    notes)   cmd_notes ;;
    publish) cmd_publish ;;
    *)       fail "用法：bash scripts/release.sh <meta|check|build|notes|publish> [tag]" ;;
esac