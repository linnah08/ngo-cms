<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * admin/quiz-save.php — the per-item quiz text editor endpoint, run for real
 * via run_admin_page(). content/{bg,en}/quiz.json is gitignored live content,
 * so every test restores it byte-for-byte.
 */
#[Group('admin')]
#[Group('quiz')]
final class QuizSaveTest extends TestCase
{
    private const EP = 'admin/quiz-save.php';
    private array $originals = [];

    protected function setUp(): void
    {
        foreach (['bg', 'en'] as $lang) {
            $file = CONTENT_PATH . "/$lang/quiz.json";
            if (!is_file($file)) $this->markTestSkipped("content/$lang/quiz.json is not present (gitignored content).");
            $this->originals[$file] = file_get_contents($file);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originals as $file => $bytes) file_put_contents($file, $bytes);
    }

    private function quiz(string $lang = 'bg'): array
    {
        return load_json(CONTENT_PATH . "/$lang/quiz.json");
    }

    private function save(array $post, array $opts = []): array
    {
        $r = run_admin_page(self::EP, $post, $opts);
        $r['json'] = json_decode(trim($r['body']), true);
        return $r;
    }

    private function assertUnchanged(): void
    {
        foreach ($this->originals as $file => $bytes) {
            $this->assertSame($bytes, file_get_contents($file), basename(dirname($file)) . '/quiz.json must not change');
        }
    }

    public function testOnlyAdminsCanSave(): void
    {
        $post = ['type' => 'ui', 'title' => 'Hacked'];
        $this->assertSame(403, $this->save($post, ['role' => 'author'])['status']);
        $this->assertSame(403, $this->save($post, ['role' => 'shop_admin'])['status']);
        $this->assertSame(403, $this->save($post, ['role' => null])['status']);
        $this->assertUnchanged();
    }

    public function testRejectsMissingCsrfAndGet(): void
    {
        $this->assertSame(400, $this->save(['type' => 'ui', 'title' => 'X'], ['csrf' => false])['status']);
        $this->assertSame(405, $this->save([], ['method' => 'GET'])['status']);
        $this->assertUnchanged();
    }

    public function testSavesPageTextAndIgnoresUnknownUiKeys(): void
    {
        $r = $this->save(['type' => 'ui', 'title' => '  Нов тест  ', 'ui' => ['start_button' => ' Почни ', 'evil' => 'x']]);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);

        $q = $this->quiz();
        $this->assertSame('Нов тест', $q['title']);
        $this->assertSame('Почни', $q['ui']['start_button']);
        $this->assertArrayNotHasKey('evil', $q['ui']);
        $this->assertSame($this->originals[CONTENT_PATH . '/en/quiz.json'], file_get_contents(CONTENT_PATH . '/en/quiz.json'), 'a BG save must not touch EN');
    }

    public function testEnSaveGoesToTheEnFileAndUnknownLangFallsBackToBg(): void
    {
        $this->save(['type' => 'ui', 'lang' => 'en', 'title' => 'EN title']);
        $this->assertSame('EN title', $this->quiz('en')['title']);

        $this->save(['type' => 'ui', 'lang' => '../../config', 'title' => 'BG title']);
        $this->assertSame('BG title', $this->quiz('bg')['title']);
    }

    public function testBlankTitleIsRejected(): void
    {
        $r = $this->save(['type' => 'ui', 'title' => '   ']);
        $this->assertSame(400, $r['status']);
        $this->assertFalse($r['json']['ok']);
        $this->assertUnchanged();
    }

    public function testSavesOneQuestionOnly(): void
    {
        $before = $this->quiz()['questions'];
        $this->assertNotEmpty($before);
        $id = $before[0]['id'];

        $r = $this->save(['type' => 'question', 'id' => $id, 'prompt' => 'Нов въпрос?', 'category_label' => 'К', 'intro' => '',
            'answers' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd']]);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);

        $after = $this->quiz()['questions'];
        $this->assertSame('Нов въпрос?', $after[0]['prompt']);
        $this->assertSame(['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], $after[0]['answers']);
        $this->assertSame(array_slice($before, 1), array_slice($after, 1), 'other questions must be untouched');
    }

    public function testQuestionNeedsAllFourAnswersAndAKnownId(): void
    {
        $id = $this->quiz()['questions'][0]['id'];
        $r = $this->save(['type' => 'question', 'id' => $id, 'answers' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => ' ']]);
        $this->assertSame(400, $r['status']);

        $r = $this->save(['type' => 'question', 'id' => 'no-such-question', 'answers' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd']]);
        $this->assertSame(404, $r['status']);
        $this->assertUnchanged();
    }

    public function testSavesAClusterAndRequiresAllItsText(): void
    {
        $id = $this->quiz()['clusters'][0]['id'];

        $r = $this->save(['type' => 'cluster', 'id' => $id, 'label' => 'Етикет', 'mechanism' => 'Защото', 'tip' => '']);
        $this->assertSame(400, $r['status']);
        $this->assertUnchanged();

        $r = $this->save(['type' => 'cluster', 'id' => $id, 'label' => 'Етикет', 'mechanism' => 'Защото', 'tip' => 'Опитай']);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);
        $c = $this->quiz()['clusters'][0];
        $this->assertSame(['Етикет', 'Защото', 'Опитай'], [$c['label'], $c['mechanism'], $c['tip']]);

        $this->assertSame(404, $this->save(['type' => 'cluster', 'id' => 'nope', 'label' => 'a', 'mechanism' => 'b', 'tip' => 'c'])['status']);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->assertSame(400, $this->save(['type' => 'structure'])['status']);
        $this->assertUnchanged();
    }
}
