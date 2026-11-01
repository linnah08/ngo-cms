<?php
/**
 * Automatic article excerpts via Claude API, with a plain-truncation fallback.
 *
 * Used at article-save time (admin/article-edit.php) so new articles always
 * get an excerpt without the author needing to know it exists as a field, and
 * by cron/backfill-article-excerpts.php so both share the same
 * generation logic. API key is stored in the encrypted settings table under
 * key 'claude_api_key' (see includes/ai_keywords.php).
 *
 * Usage:
 *   $excerpt = article_auto_excerpt($title, $content, 'bg');
 *   // '' only when $content itself has no text to summarize.
 */

if (!function_exists('claude_is_configured')) {
    require_once __DIR__ . '/ai_keywords.php';
}
require_once __DIR__ . '/articles.php'; // article_excerpt_from_content()

/**
 * Article HTML as plain text for the excerpt prompt. Block-level tags become
 * line breaks first, so the words on either side of a paragraph or heading
 * don't get glued together by strip_tags().
 */
function claude_excerpt_source_text(string $html, int $max_len = 12000): string
{
    $html = preg_replace('~<br\s*/?>|</(p|div|h[1-6]|li|blockquote|tr)>~i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    $text = trim(preg_replace('/\s*\n\s*/u', "\n", $text));
    return mb_substr($text, 0, $max_len);
}

/**
 * The instructions for the excerpt. The Bulgarian rules come from summaries
 * an editor had to correct by hand: wrong gender/number agreement, Russian
 * words, made-up words, "със" before any consonant, the renarrative "се
 * зародила" for a plain past, calques, and details the article never said.
 */
function claude_excerpt_system_prompt(string $lang): string
{
    if ($lang === 'en') {
        return <<<TXT
You write the short summary shown under an article title on a charity's news page; it is also the page's description in search results.

Rules:
- One sentence, 120–170 characters, in natural, correct English.
- Use only facts stated in the article. Don't add details, numbers, dates or timing words ("just", "recently") the article doesn't give.
- Don't repeat the title.
- Don't write about the text itself ("the article describes", "the author explains"); say what happens and who does what.
- Plain, warm tone. No hype, no clichés, no exclamation marks.
- Don't address the reader (no "you can", "check out"); say what the article is about.
- Never write an amount in Bulgarian lev. If the article gives an amount only in lev, leave the amount out.

Reply with the summary only: no quotes, no markdown, no label.
TXT;
    }

    return <<<TXT
Пишеш краткото резюме, което стои под заглавието на статия в новините на благотворителна организация. Същото резюме Google показва като описание на страницата.

Правила:
- Едно изречение, 120–170 знака, на правилен, естествен български език — както би го написал грамотен български редактор.
- Само факти, които ги има в статията. Не добавяй подробности, числа, дати или думи за време („току-що“, „скоро“), които статията не казва.
- Не повтаряй заглавието.
- Не пиши за самия текст („статията описва“, „статията търси“, „авторът разказва“, „разказваме“) — кажи какво се случва и кой какво прави.
- Спокоен, топъл тон. Без реклама, клишета и удивителни.
- Не се обръщай към читателя (без „можете“, „можеш“, „вижте“) — разкажи какво има в статията.
- Никога не пиши суми в лева. Ако статията дава сума само в лева, не я споменавай.

Правопис и граматика — провери всяка дума, преди да отговориш:
- Никакви руски думи и руски правопис: „често“ (не „часто“), „създаде“ (не „создаде“), „започва“ (не „начева“), „учене“ или „обучение“ (не „учеба“).
- Само думи, които съществуват в българския: „се бори“ (не „се борира“), „кауза“ (не „каза“).
- Род и число се съгласуват: „второ гостуване“ (не „втора гостуване“), „парите, които отидоха“ (не „които отиде“), „която би могла“ (не „която могла“).
- Минало време с обикновената форма: „идеята, която се зароди“ (не „която се зародила“ — това е преизказно наклонение).
- „със“ само пред думи, които започват със „с“ или „з“: „с доброволци“, „със специални нужди“, „със застъпници“, но не „със доброволци“.
- Без буквален превод от английски: „работни места“ (не „работни възможности“), „подкрепят“ (не „подпомогнат“).
- Казвай „хора със специални нужди“, не „хора с увреждания“.
- Ако статията описва проект на организацията, кажи го ясно („Нашият проект, в който…“), а не като общо твърдение.

Примери за добри резюмета:
- Второ посещение в центъра в селото, където доброволци и деца готвят заедно, играят, разговарят и споделят моменти, които остават за дълго.
- Мария е 18-годишно момиче, което наскоро напусна приемна грижа и се бори за самостоятелен живот, докато учи и работи почасово.
- Новите картички за благотворителния базар са готови за печат, а екипът подготвя опаковането им преди 2 декември.

Отговори само с резюмето: без кавички, без markdown, без етикет.
TXT;
}

function claude_suggest_excerpt(string $title, string $content, string $lang = 'bg'): string
{
    $api_key = setting_get('claude_api_key');
    if ($api_key === '') return '';

    $text = claude_excerpt_source_text($content);
    if ($text === '') return '';

    $label = $lang === 'en' ? ['Title', 'Article'] : ['Заглавие', 'Статия'];
    // Haiku 5.5 thinks by default; high effort buys the extra checking pass
    // over grammar that the old one-shot summaries lacked. max_tokens leaves
    // room for that thinking on top of the one-sentence answer.
    $body = json_encode([
        'model'         => 'claude-haiku-5-5',
        'max_tokens'    => 8000,
        'output_config' => ['effort' => 'high'],
        'system'        => claude_excerpt_system_prompt($lang),
        'messages'      => [
            ['role' => 'user', 'content' => "{$label[0]}: {$title}\n\n{$label[1]}:\n{$text}"],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        _om_log('Claude excerpt', 'cURL error: ' . $curl_err);
        return '';
    }
    if ($http_code !== 200) {
        _om_log('Claude excerpt', "HTTP {$http_code}: " . substr((string)$response, 0, 200));
        return '';
    }

    return claude_excerpt_from_response((string)$response);
}

/**
 * The summary text out of a Messages API response. Thinking blocks come
 * before the answer, so the text block has to be looked up by type — not
 * read from content[0]. A cut-off or refused answer gives '' so the caller
 * falls back to plain truncation instead of saving half a sentence.
 */
function claude_excerpt_from_response(string $response): string
{
    $data = json_decode($response, true);
    if (($data['stop_reason'] ?? '') !== 'end_turn') return '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            return trim(trim($block['text']), "\"'„“”«» \t\n\r\0\x0B");
        }
    }
    return '';
}

/**
 * Generate an excerpt for content that doesn't have one yet: Claude when
 * configured and the ai_helpers module is on, otherwise a plain truncation. Never blocks article saving —
 * returns '' only when the content itself is empty.
 */
function article_auto_excerpt(string $title, string $content, string $lang = 'bg'): string
{
    if (trim(strip_tags($content)) === '') return '';

    // „Помощ от изкуствен интелект“ switched off in Admin → Модули: the plain
    // cut, with no call to the API.
    $ai_on = !function_exists('module_enabled_with_needs') || module_enabled_with_needs('ai_helpers');
    if ($ai_on && claude_is_configured()) {
        $excerpt = claude_suggest_excerpt($title, $content, $lang);
        if ($excerpt !== '') return $excerpt;
    }

    return article_excerpt_from_content($content);
}
