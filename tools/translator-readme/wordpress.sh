#!/usr/bin/env bash
#
# Runs the WordPress page's documented pipeline against a real plugin tree.
#
# The runtime half needs WordPress and is covered in PHP by verify.php, which pins the part
# that actually goes wrong: `\__()` reaching the global rather than this library's marker.
# This is the extraction half, which needs wp-cli but no WordPress install — `make-pot` runs
# on the before_wp_load hook.
#
# The plugin is built from `git archive`, so what it holds is what a consumer's `vendor/`
# holds. A file that stops shipping fails here rather than in their tree.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

plugin="$work/my-plugin"
mkdir -p "$plugin/vendor/gravitypdf/upload" "$plugin/languages"
git -C "$root" archive HEAD | tar -x -C "$plugin/vendor/gravitypdf/upload"

cat > "$plugin/my-plugin.php" <<'PHP'
<?php
/**
 * Plugin Name: My Plugin
 * Text Domain: my-plugin
 */
__('a string the plugin owns', 'my-plugin');
PHP

fail() {
    echo "$1" >&2
    exit 1
}

# The command the page tells a plugin author to run.
wp i18n make-pot "$plugin" "$plugin/languages/my-plugin.pot" \
    --merge="$plugin/vendor/gravitypdf/upload/i18n/upload.pot" \
    --skip-audit >/dev/null

shipped="$(grep -c '^msgid "' "$plugin/vendor/gravitypdf/upload/i18n/upload.pot")"
merged="$(grep -c '^msgid "' "$plugin/languages/my-plugin.pot")"

# The plugin's own string and its Plugin Name header, on top of everything the library ships.
[ "$merged" -gt "$shipped" ] || fail "make-pot --merge did not add the library's msgids: $merged vs $shipped"

grep -q '^msgid "No file was uploaded"$' "$plugin/languages/my-plugin.pot" \
    || fail "the library's msgids are missing from the merged catalogue"

grep -q '^msgid "a string the plugin owns"$' "$plugin/languages/my-plugin.pot" \
    || fail "the plugin's own strings were lost in the merge"

# The rest of the documented pipeline, on the merged catalogue.
msginit --no-translator --locale=de_DE \
    --input="$plugin/languages/my-plugin.pot" \
    --output="$plugin/languages/my-plugin-de_DE.po" >/dev/null 2>&1

perl -0pi -e 's/msgid "No file was uploaded"\nmsgstr ""/msgid "No file was uploaded"\nmsgstr "Es wurde keine Datei hochgeladen"/' \
    "$plugin/languages/my-plugin-de_DE.po"

msgfmt --check \
    --output-file="$plugin/languages/my-plugin-de_DE.mo" \
    "$plugin/languages/my-plugin-de_DE.po"

msgunfmt "$plugin/languages/my-plugin-de_DE.mo" | grep -q 'Es wurde keine Datei hochgeladen' \
    || fail "the compiled .mo does not carry the translation"

# What the page says will NOT work, asserted so the warning cannot go stale: a strings file
# inside vendor/ is invisible to make-pot, because vendor/ is what it excludes.
cp "$plugin/vendor/gravitypdf/upload/i18n/upload.pot" "$plugin/vendor/gravitypdf/upload/i18n/strings.pot"
wp i18n make-pot "$plugin" "$work/vendor-only.pot" --skip-audit >/dev/null

if grep -q '^msgid "No file was uploaded"$' "$work/vendor-only.pot"; then
    fail "make-pot read vendor/ — the WordPress page's central claim is wrong"
fi

# The generator the wordpress.org section documents, run verbatim out of the page. It has to
# emit single-quoted PHP: `%1$s` inside a double-quoted string parses `$s` as a variable, and
# make-pot would not read the result as a literal.
php -r '
$doc = file_get_contents($argv[1]);
preg_match_all("/```php\n(.*?)```/s", $doc, $m);
$block = array_values(array_filter($m[1], function ($b) { return strpos($b, "upload-strings.php") !== false; }));
if (count($block) !== 1) { fwrite(STDERR, "expected one generator snippet, found " . count($block) . "\n"); exit(1); }
file_put_contents($argv[2], $block[0]);
' "$root/docs/translation/wordpress.md" "$plugin/generate-strings.php"

( cd "$plugin" && php generate-strings.php )

php -l "$plugin/languages/upload-strings.php" >/dev/null \
    || fail "the documented generator produced a file PHP cannot parse"

wp i18n make-pot "$plugin" "$work/strings-file.pot" --skip-audit >/dev/null

grep -q '^msgid "No file was uploaded"$' "$work/strings-file.pot" \
    || fail "make-pot did not read the generated strings file"

# The one msgid whose quoting is easy to get wrong.
grep -q 'File contents do not match the ..%1\$s.. extension' "$work/strings-file.pot" \
    || fail "a msgid carrying a printf conversion did not survive the generator"

echo "WordPress pipeline verified: make-pot --merge, msginit, msgfmt, the strings-file"
echo "generator, and vendor/ still excluded"
