<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

final class HomeTextImagePhotosTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $GLOBALS['_show_admin_bar'] = false;
    }

    private function render(array $fields): string
    {
        $doc = ['version' => 1, 'rev' => 1, 'sections' => [['id' => 's_ti', 'type' => 'text_image', 'visible' => true, 'fields' => $fields]]];
        ob_start();
        home_render($doc, 'bg');
        return (string) ob_get_clean();
    }

    private function photos(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['id' => 'i_aaaa0' . $i, 'image' => "/assets/images/p$i.jpg", 'image_alt' => ['bg' => "Снимка $i", 'en' => '']];
        }
        return $out;
    }

    public function test_legacy_section_keeps_photo_on_resave(): void
    {
        [$f, $e] = home_validate_section('text_image', ['image' => '/assets/images/a.jpg', 'body' => ['bg' => '<p>т</p>', 'en' => '']]);
        $this->assertSame('/assets/images/a.jpg', $f['photos'][0]['image']);
        $this->assertMatchesRegularExpression(HOME_LIST_ID_RE, $f['photos'][0]['id']);
        $this->assertSame([], $e);
    }

    public function test_more_than_four_photos_is_an_error(): void
    {
        [, $e] = home_validate_section('text_image', ['photos' => $this->photos(5)]);
        $this->assertSame('Може да добавите до 4 снимки.', $e['photos']);
    }

    public function test_a_photo_row_without_a_picture_is_an_error(): void
    {
        [, $e] = home_validate_section('text_image', ['photos' => [['image' => '']]]);
        $this->assertSame('Изберете снимка или премахнете реда.', $e['photos.0.image']);
    }

    public function test_renders_photos_stacked_in_order(): void
    {
        $html = $this->render(['photos' => $this->photos(3)]);
        $this->assertSame(3, substr_count($html, '<img'));
        $this->assertLessThan(strpos($html, 'p2.jpg'), strpos($html, 'p1.jpg'));
        $this->assertLessThan(strpos($html, 'p3.jpg'), strpos($html, 'p2.jpg'));
        $this->assertStringContainsString('display:flex;flex-direction:column', $html);
        $this->assertStringContainsString('alt="Снимка 2"', $html);
        $this->assertStringContainsString('grid-template-columns:1fr 1fr', $html);
        $this->assertStringContainsString('align-items:start', $html);
    }

    public function test_one_photo_stays_centred(): void
    {
        $this->assertStringContainsString('align-items:center', $this->render(['photos' => $this->photos(1)]));
    }

    public function test_no_photos_renders_text_full_width(): void
    {
        $html = $this->render(['body' => ['bg' => '<p>т</p>', 'en' => '']]);
        $this->assertStringContainsString('grid-template-columns:1fr;', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_each_photo_is_replaceable_on_the_page(): void
    {
        $GLOBALS['_show_admin_bar'] = true;
        $html = $this->render(['photos' => $this->photos(2)]);
        $this->assertStringContainsString('data-cms-field="photos.i_aaaa01.image"', $html);
        $this->assertStringContainsString('data-cms-field="photos.i_aaaa02.image"', $html);
    }

    public function test_narrow_screens_put_the_text_before_the_photos(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/includes/home_render.php');
        $this->assertStringContainsString('.home-ti>div.home-ti__text{order:0!important}', $css);
        $this->assertStringContainsString('.home-ti>div{order:1!important}', $css);
    }

    private function oldDocument(): void
    {
        $dir = sys_get_temp_dir() . '/home-ti-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $GLOBALS['_om_home_file'] = $dir . '/home.json';
        file_put_contents($GLOBALS['_om_home_file'], json_encode(['version' => HOME_DOC_VERSION, 'rev' => 3, 'sections' => [[
            'id' => 's_old1', 'type' => 'text_image', 'visible' => true,
            'fields' => ['heading' => ['bg' => 'Х', 'en' => ''], 'body' => ['bg' => '<p>т</p>', 'en' => ''],
                         'image' => '/assets/images/a.jpg', 'image_alt' => ['bg' => 'описание', 'en' => ''], 'image_side' => 'left'],
        ]]]));
    }

    protected function tearDown(): void
    {
        if (isset($GLOBALS['_om_home_file'])) {
            foreach (glob(dirname($GLOBALS['_om_home_file']) . '/*') ?: [] as $f) @unlink($f);
            @rmdir(dirname($GLOBALS['_om_home_file']));
            unset($GLOBALS['_om_home_file']);
        }
    }

    public function test_loading_an_old_block_gives_its_photo_a_stable_valid_id(): void
    {
        $this->oldDocument();
        $a = home_load()['doc']['sections'][0]['fields'];
        $b = home_load()['doc']['sections'][0]['fields'];
        $this->assertMatchesRegularExpression(HOME_LIST_ID_RE, $a['photos'][0]['id']);
        $this->assertSame($a['photos'][0]['id'], $b['photos'][0]['id']);
        $this->assertArrayNotHasKey('image', $a);
        $this->assertSame('описание', $a['photos'][0]['image_alt']['bg']);
    }

    public function test_page_uses_the_same_id_for_replacing_an_old_photo(): void
    {
        $this->oldDocument();
        $GLOBALS['_show_admin_bar'] = true;
        $doc = home_load()['doc'];
        $id = $doc['sections'][0]['fields']['photos'][0]['id'];
        ob_start();
        home_render($doc, 'bg');
        $this->assertStringContainsString('data-cms-field="photos.' . $id . '.image"', (string) ob_get_clean());
    }

    public function test_old_block_keeps_its_photo_through_the_admin_form(): void
    {
        $this->oldDocument();
        $doc    = home_load()['doc'];
        $fields = $doc['sections'][0]['fields'];
        $form   = hs_field('photos', home_types()['text_image']['fields']['photos'], $fields['photos'] ?? null, []);
        $this->assertStringContainsString('value="' . $fields['photos'][0]['id'] . '"', $form);
        $this->assertStringContainsString('/assets/images/a.jpg', $form);
        // What the browser posts back for that row.
        preg_match('#name="f\[photos\]\[0\]\[id\]" value="([^"]+)"#', $form, $id);
        preg_match('#name="f\[photos\]\[0\]\[image\]" value="([^"]+)"#', $form, $img);
        $this->assertNotEmpty($id);
        $this->assertNotEmpty($img);
        $r = home_admin_save($doc, ['id' => 's_old1', 'rev' => 3, 'f' => [
            'heading' => ['bg' => 'Х', 'en' => ''], 'body' => ['bg' => '<p>т</p>', 'en' => ''],
            'photos' => [['id' => $id[1], 'image' => html_entity_decode($img[1]), 'image_alt' => ['bg' => 'описание', 'en' => '']]],
        ]], []);
        $this->assertSame('saved', $r['status'], json_encode($r['errors']));
        $kept = home_load()['doc']['sections'][0]['fields']['photos'];
        $this->assertCount(1, $kept);
        $this->assertSame('/assets/images/a.jpg', $kept[0]['image']);
        $this->assertSame($fields['photos'][0]['id'], $kept[0]['id']);
    }
}
