<?php
/**
 * Page wording reworded on the page itself in Edit mode.
 *
 * The fixed words of the public pages — page titles and labels, section headings,
 * form labels and buttons, "nothing here yet" messages, footer headings — print
 * through te() (config.php), which marks each one with its strings key. The on-page
 * editor saves them here, as section "ui" of admin/inline-save.php.
 *
 * They live in the database (setting "ui_wording": {"bg": {key: text}, "en": {...}}),
 * not in content/{lang}/strings.site.json: that file can be part of a site's repo
 * and a deploy copies it over the server's, which would quietly undo the edits.
 * lang_strings() lays them over strings.json and strings.site.json, so what an
 * admin typed on the page wins. Emptying a text on the page brings back the default.
 */

require_once __DIR__ . '/settings.php';

const UI_WORDING_SETTING = 'ui_wording';
const UI_WORDING_KEY_RE  = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,3}$/';
const UI_WORDING_MAX_LEN = 500;
const UI_WORDING_MAX_BYTES = 30000;   // settings.value is TEXT (64 KB) and is stored encrypted

/** Both languages' edited wording: ['bg' => [key => text], 'en' => [...]]. */
function ui_wording_all(): array
{
    if (!defined('DB_HOST')) return ['bg' => [], 'en' => []];   // install wizard: no database yet
    $raw  = json_decode(setting_get(UI_WORDING_SETTING, '{}'), true);
    $out  = ['bg' => [], 'en' => []];
    if (!is_array($raw)) return $out;
    foreach (['bg', 'en'] as $l) {
        foreach ((array) ($raw[$l] ?? []) as $k => $v) {
            if (is_string($k) && preg_match(UI_WORDING_KEY_RE, $k) && is_string($v) && $v !== '') $out[$l][$k] = $v;
        }
    }
    return $out;
}

/** A text as typed on the page (innerHTML), as plain text on one line. */
function ui_wording_clean(string $html): string
{
    $s = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $s)));
}

/**
 * Save the texts the editor sent: [key => ['bg' => html, 'en' => html]].
 * A language the editor did not send keeps what it had; one sent empty goes back
 * to the default wording.
 *
 * @return array{ok: bool, error?: string}
 */
function ui_wording_save(array $fields): array
{
    $all = ui_wording_all();
    $changed = 0;
    foreach ($fields as $key => $values) {
        if (!is_string($key) || !preg_match(UI_WORDING_KEY_RE, $key) || !is_array($values)) {
            return ['ok' => false, 'error' => 'Този текст не може да се промени оттук.'];
        }
        foreach (['bg', 'en'] as $l) {
            if (!array_key_exists($l, $values)) continue;
            $v = ui_wording_clean(is_string($values[$l]) ? $values[$l] : '');
            if (mb_strlen($v) > UI_WORDING_MAX_LEN) {
                return ['ok' => false, 'error' => 'Текстът е твърде дълъг (до ' . UI_WORDING_MAX_LEN . ' знака).'];
            }
            if ($v === '') unset($all[$l][$key]);
            else $all[$l][$key] = $v;
            $changed++;
        }
    }
    if ($changed === 0) return ['ok' => true];
    $json = json_encode($all, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > UI_WORDING_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Твърде много променени текстове — част от тях не може да се запази.'];
    }
    setting_set(UI_WORDING_SETTING, $json);
    // setting_set() only logs a failure; read it back so the page never says "saved" when it wasn't.
    if (setting_get(UI_WORDING_SETTING, '') !== $json) {
        return ['ok' => false, 'error' => 'Текстът не се запази. Опитайте отново.'];
    }
    return ['ok' => true];
}
