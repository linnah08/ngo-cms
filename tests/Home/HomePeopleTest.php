<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

final class HomePeopleTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $GLOBALS['_show_admin_bar'] = false;
        unset($GLOBALS['_om_people_style']);
    }

    protected function tearDown(): void
    {
        home_target_reset();
        unset($GLOBALS['_show_admin_bar']);
    }

    private function render(array $people): string
    {
        $doc = ['version' => 1, 'rev' => 1, 'sections' => [[
            'id' => 's_pe01', 'type' => 'people', 'visible' => true,
            'fields' => ['heading' => ['bg' => 'Екипът', 'en' => ''], 'people' => $people],
        ]]];
        ob_start();
        home_render($doc, 'bg');
        return (string) ob_get_clean();
    }

    private function person(array $over = []): array
    {
        return $over + ['id' => 'i_pppp01', 'image' => '', 'focus' => null, 'image_alt' => ['bg' => '', 'en' => ''],
            'name' => ['bg' => 'Мария', 'en' => ''], 'role' => ['bg' => 'Психолог', 'en' => ''], 'bio' => ['bg' => '<p>Текст</p>', 'en' => '']];
    }

    public function test_people_type_is_offered_on_created_pages(): void
    {
        home_target_page('p_12345678');
        $this->assertArrayHasKey('people', home_doc_types());
    }

    public function test_name_is_required(): void
    {
        [, $e] = home_validate_section('people', ['people' => [['name' => ['bg' => '', 'en' => '']]]]);
        $this->assertArrayHasKey('people.0.name.bg', $e);
    }

    public function test_thirty_one_people_is_an_error(): void
    {
        $in = array_fill(0, 31, ['name' => ['bg' => 'А', 'en' => '']]);
        [, $e] = home_validate_section('people', ['people' => $in]);
        $this->assertSame('Добавете между 1 и 30 души.', $e['people']);
    }

    public function test_section_preview_counts_people(): void
    {
        $s = ['type' => 'people', 'fields' => ['people' => [$this->person(), $this->person(['id' => 'i_pppp02'])]]];
        $this->assertStringContainsString('2 души', home_section_preview($s));
    }

    private function rect(array $over = []): array
    {
        return $over + ['x' => 100, 'y' => 0, 'w' => 300, 'h' => 400, 'iw' => 1200, 'ih' => 800];
    }

    private function cleanFocus(mixed $raw): mixed
    {
        [$v, $e] = home_clean_field(HOME_PERSON_FIELDS['focus'], $raw, 'people.0.focus');
        $this->assertSame([], $e, 'a bad focus is dropped quietly');
        return $v;
    }

    public function test_focus_valid_rect_is_kept(): void
    {
        $this->assertSame($this->rect(), $this->cleanFocus($this->rect()));
        $this->assertSame($this->rect(), $this->cleanFocus(json_encode($this->rect())));
        $this->assertSame($this->rect(), $this->cleanFocus(['x' => '100', 'y' => '0', 'w' => '300', 'h' => '400', 'iw' => '1200', 'ih' => '800']));
    }

    public static function badRects(): array
    {
        $ok = ['x' => 100, 'y' => 0, 'w' => 300, 'h' => 400, 'iw' => 1200, 'ih' => 800];
        return [
            'negative x'     => [array_replace($ok, ['x' => -1])],
            'overflows right' => [array_replace($ok, ['x' => 901])],
            'overflows down' => [array_replace($ok, ['y' => 401])],
            'square'         => [array_replace($ok, ['w' => 400])],
            'strings'        => [array_replace($ok, ['x' => 'abc'])],
            'floats'         => [array_replace($ok, ['x' => 1.5])],
            'zero width'     => [array_replace($ok, ['w' => 0, 'h' => 0])],
            'missing key'    => [array_diff_key($ok, ['ih' => 1])],
            'bad json'       => ['{nope'],
            'a number'       => [42],
            'null'           => [null],
        ];
    }

    #[DataProvider('badRects')]
    public function test_focus_rejects_bad_rects(mixed $raw): void
    {
        $this->assertNull($this->cleanFocus($raw));
    }

    public function test_focus_style_maps_rect_to_percentages(): void
    {
        $css = home_focus_style(['x' => 100, 'y' => 50, 'w' => 300, 'h' => 400, 'iw' => 1200, 'ih' => 800]);
        foreach (['width:400.0000%', 'height:200.0000%', 'left:-33.3333%', 'top:-12.5000%'] as $part) {
            $this->assertStringContainsString($part, $css);
        }
    }

    public function test_focus_style_without_rect_is_centred_cover(): void
    {
        $this->assertSame('position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:50% 50%;', home_focus_style(null));
    }

    public function test_render_people_rows(): void
    {
        $html = $this->render([
            $this->person(['image' => '/assets/images/a.jpg', 'name' => ['bg' => 'Мария', 'en' => '']]),
            $this->person(['id' => 'i_pppp02', 'name' => ['bg' => 'Иван', 'en' => '']]),
        ]);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('role="list"', $html);
        $this->assertSame(2, substr_count($html, '<li'));
        $this->assertMatchesRegularExpression('#<h3[^>]*>Мария</h3>#u', $html);
        $this->assertMatchesRegularExpression('#<h3[^>]*>Иван</h3>#u', $html);
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringContainsString('alt="Мария"', $html);
        $this->assertStringNotContainsString('👤', $html);
        $this->assertSame(1, substr_count($html, 'aspect-ratio:3/4'));
    }

    public function test_photo_alt_text_wins_over_name(): void
    {
        $html = $this->render([$this->person(['image' => '/assets/images/a.jpg', 'image_alt' => ['bg' => 'Мария в градината', 'en' => '']])]);
        $this->assertStringContainsString('alt="Мария в градината"', $html);
    }

    public function test_person_with_focus_gets_the_cropped_style(): void
    {
        $html = $this->render([$this->person(['image' => '/assets/images/a.jpg', 'focus' => $this->rect()])]);
        $this->assertStringContainsString('width:400.0000%', $html);
    }

    public function test_mobile_style_is_printed_once_and_scoped(): void
    {
        $html = $this->render([$this->person()]) . $this->render([$this->person()]);
        $this->assertStringContainsString('.home-people .home-people__row', $html);
        $this->assertSame(1, substr_count($html, '.home-people__frame{max-width:160px'));
    }

    public function test_render_each_photo_replaceable(): void
    {
        $GLOBALS['_show_admin_bar'] = true;
        $html = $this->render([$this->person(['image' => '/assets/images/a.jpg'])]);
        $this->assertStringContainsString('data-cms-field="people.i_pppp01.image"', $html);
    }

    public function test_admin_form_shows_focus_control_with_reason_when_no_photo(): void
    {
        $html = hs_field('focus', HOME_PERSON_FIELDS['focus'], null, [], 'f[people][0]', 'f_people_0', 'people.0.', '');
        $this->assertStringContainsString('name="f[people][0][focus]"', $html);
        $this->assertStringContainsString('Избери какво да се вижда', $html);
        $this->assertStringContainsString('Първо изберете снимка.', $html);
        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_every_person_text_field_is_translatable(): void
    {
        $html = hs_list('people', home_types()['people']['fields']['people'], [$this->person()], []);
        foreach (['name', 'role', 'bio', 'image_alt'] as $f) {
            $this->assertStringContainsString('data-translate-from="f[people][0][' . $f . '][bg]"', $html);
        }
    }
}
