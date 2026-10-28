<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A site must be able to speak to its visitors in its own voice (formal „Вие“
 * or informal „ти“) purely through content/{bg,en}/strings.site.json. That only
 * works for text that goes through t_or() or te() (the Edit-mode version): a
 * sentence typed straight into the page can't be reworded without editing code.
 *
 * These sentences used to be hard-coded. Each must now appear in its page only
 * as a t_or()/te() default — never as page text or as a plain PHP string.
 */
final class VoiceOverridableTextTest extends TestCase
{
    /** file => sentences (current BG or EN wording) that must only live in t_or()/te() */
    public static function movedSentences(): array
    {
        return [
            'kontakti/index.php' => ['Свържете се с нас', 'Изпратете съобщение', 'Благодарим! Ще се свържем с вас скоро.',
                'Как можете да помогнете?', '— Изберете тема —', 'Изпрати съобщение', 'Моля попълнете всички полета.',
                'Моля изберете валидна тема.', 'Грешка при изпращане'],
            'en/contacts/index.php' => ['Get in touch', 'Send a message', 'Thank you! We will be in touch soon.',
                'How can you help?', 'Please fill in all fields.', 'There was an error sending your message'],
            'novini/index.php' => ['Подкрепете ни', 'Истории като тази са възможни с вашата помощ.',
                'Подкрепете пряко работата ни.', 'Купете продукт от нашия магазин', 'Станете партньор',
                'Свържете се с нас', 'Новини и истории от нашата работа.'],
            'en/news/index.php' => ['Support us', 'Stories like this are possible with your help.', 'Become a partner',
                'Get in touch', 'News and stories from our work.'],
            'campaign/index.php' => ['Вашето име', 'вашият@email.com', 'В следващата стъпка ще изберете начин на доставка.',
                'Ще получите сертификат за дарение по имейл.', 'Подкрепи кампанията'],
            'campaign/checkout.php' => ['Моля, въведете вашето име.', 'Моля, въведете валиден имейл адрес.',
                'Техническа грешка. Моля, опитайте отново.', 'Плащанията не са конфигурирани.'],
            'templates/comments.php' => ['Вашето име', 'Коментарът ви е получен и очаква одобрение.', 'Бъди първият!'],
            'templates/product-reviews.php' => ['Твоето име', 'Твоята оценка', 'Отзивът ти е получен', 'Бъди първият!'],
            'api/comment-submit.php' => ['Въведете валиден имейл.', 'Изчакайте малко, преди да публикувате нов коментар.'],
            'api/review-submit.php' => ['Въведи валиден имейл.', 'Изберете оценка от 1 до 5.', 'Изчакайте малко, преди да публикувате нов отзив.'],
            'magazin/index.php' => ['Изберете размер, за да продължите.', 'Качи твоя дизайн', 'Всяка покупка подкрепя каузата ни.',
                'с покупка от нашия магазин.', 'with a purchase from our shop.'],
            'kak-da-pomogna/index.php' => ['Свържете се с нас', 'Свържи се с нас', 'Научете как можете да подкрепите'],
            'en/how-to-help/index.php' => ['Get in touch', 'Find out how you can support'],
            'newsletter/unsubscribe.php' => ['Отписани сте', 'Няма да получавате повече имейли от нас.'],
            'includes/emails/campaign-confirmation.php' => ['Твоята награда ще бъде изпратена на:'],
            'includes/emails/donation-confirmation-customer.php' => ['Вашето послание'],
            'includes/emails/cert-needs-signature.php' => ['Здравейте,', 'Генериран е нов сертификат за дарение и очаква вашия подпис.',
                'Подпишете сертификата', 'до сертификата в страницата на поръчката.'],
            'includes/newsletter.php' => ['Отпишете се от бюлетина', 'Unsubscribe from this newsletter'],
            'za-nas/index.php' => ['Научете повече за {name}, нашия екип и нашата история.', 'Научете повече за {name} и нашата история.'],
            'en/about/index.php' => ['Learn more about {name}, our team and our story.', 'Learn more about {name} and our story.'],
            'includes/documents/DistributionProtocolGenerator.php' => ['Цена за брой'],
        ];
    }

    public static function files(): array
    {
        return array_map(static fn ($f) => [$f], array_combine(array_keys(self::movedSentences()), array_keys(self::movedSentences())));
    }

    /**
     * Everything in a PHP file a visitor could see that is NOT a t_or() default:
     * inline HTML plus every quoted string outside the first three arguments
     * of a t_or() or te() call. Comments are left out.
     */
    private static function textOutsideTOr(string $src): string
    {
        $tokens = array_values(array_filter(token_get_all($src),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $out  = '';
        $skip = 0;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && in_array($t[1], ['t_or', 'te'], true) && ($tokens[$i + 1] ?? null) === '(') {
                $skip = 3;   // key, BG default, EN default
                continue;
            }
            if (!is_array($t)) continue;
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                if ($skip > 0) { $skip--; continue; }
                $out .= "\n" . stripslashes(substr($t[1], 1, -1));
            } elseif ($t[0] === T_INLINE_HTML || $t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $out .= "\n" . $t[1];
            }
        }
        return $out;
    }

    #[DataProvider('files')]
    public function testMovedSentencesOnlyLiveInTOr(string $file): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $file);
        $this->assertNotSame('', $src, $file);
        $rest = self::textOutsideTOr($src);
        foreach (self::movedSentences()[$file] as $sentence) {
            $this->assertStringContainsString($sentence, $src, "$file: the sentence should still be the default wording");
            $this->assertStringNotContainsString($sentence, $rest,
                "$file: „{$sentence}“ is hard-coded again — route it through t_or() or te() so a site can reword it in strings.site.json");
        }
    }

    public function testTheScannerSeesHardCodedText(): void
    {
        $rest = self::textOutsideTOr("<?php \$a = t_or('k', 'Вие', 'You'); \$b = 'Свържете се'; ?>\n<p>Изпратете</p>");
        $this->assertStringNotContainsString('Вие', $rest);
        $this->assertStringContainsString('Свържете се', $rest);
        $this->assertStringContainsString('Изпратете', $rest);
    }

    public function testTheScannerSkipsTeDefaultsToo(): void
    {
        $rest = self::textOutsideTOr("<?= te('k', 'Свържете се', 'Get in touch') ?>");
        $this->assertStringNotContainsString('Свържете се', $rest);
    }

    public function testASiteCanSwitchToInformalVoice(): void
    {
        // A fresh PHP process (lang_strings() caches per language) with a site's
        // own strings.site.json: its wording replaces the built-in default.
        $dir = sys_get_temp_dir() . '/voice-' . bin2hex(random_bytes(4));
        mkdir($dir . '/bg', 0777, true);
        file_put_contents($dir . '/bg/strings.site.json', json_encode(['contact.err.fields' => 'Моля, попълни всички полета.'], JSON_UNESCAPED_UNICODE));
        $root = dirname(__DIR__);
        $code = '$_SERVER["DOCUMENT_ROOT"] = ' . var_export($root, true) . '; $_SERVER["HTTP_HOST"] = "localhost";'
              . ' $GLOBALS["_om_site_strings_dir"] = ' . var_export($dir, true) . ';'
              . ' require ' . var_export($root . '/config.php', true) . ';'
              . ' echo t_lookup("contact.err.fields", "Моля попълнете всички полета.", "Please fill in all fields.", "bg"), "|",'
              . ' t_lookup("contact.err.email", "Невалиден имейл адрес.", "Invalid email address.", "bg");';
        try {
            $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        } finally {
            unlink($dir . '/bg/strings.site.json'); rmdir($dir . '/bg'); rmdir($dir);
        }
        $this->assertStringEndsWith('Моля, попълни всички полета.|Невалиден имейл адрес.', trim($out),
            'the site\'s own wording wins; a key it does not set keeps the default');
    }
}
