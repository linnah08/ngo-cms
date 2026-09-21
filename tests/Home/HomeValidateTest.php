<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeValidateTest extends TestCase
{
    public function test_registry_has_all_builtins_and_blocks(): void
    {
        $types = home_types();
        foreach (['hero', 'products', 'impact', 'campaign', 'centres', 'mission', 'news', 'partners'] as $t) {
            $this->assertTrue($types[$t]['builtin'], $t);
        }
        foreach (['text_image', 'cta', 'richtext', 'cards', 'video'] as $t) {
            $this->assertFalse($types[$t]['builtin'], $t);
        }
    }

    public function test_every_field_kind_is_known(): void
    {
        $kinds = ['text', 'textarea', 'alt', 'html', 'link', 'image', 'choice', 'video', 'internal', 'cards'];
        foreach (home_types() as $type => $def) {
            foreach ($def['fields'] as $key => $f) {
                $this->assertContains($f['kind'], $kinds, "$type.$key");
                $this->assertNotSame('', $f['label'] ?? '', "$type.$key needs a label");
            }
        }
    }

    public function test_unknown_type_is_an_error(): void
    {
        [, $errors] = home_validate_section('evil', []);
        $this->assertArrayHasKey('_type', $errors);
    }

    public function test_text_is_stripped_and_length_checked(): void
    {
        [$f, $e] = home_validate_section('richtext', ['heading' => ['bg' => '<b>Здравей</b>  свят', 'en' => str_repeat('x', 151)]]);
        $this->assertSame('Здравей свят', $f['heading']['bg']);
        $this->assertArrayHasKey('heading.en', $e);
    }

    public function test_bad_link_is_an_error_and_keeps_what_was_typed(): void
    {
        [$f, $e] = home_validate_section('cta', [
            'heading' => ['bg' => 'Помогнете', 'en' => ''],
            'btn1_label' => ['bg' => 'Дари', 'en' => ''], 'btn1_url' => ['bg' => 'javascript:alert(1)', 'en' => ''],
        ]);
        $this->assertArrayHasKey('btn1_url.bg', $e);
        $this->assertSame('javascript:alert(1)', $f['btn1_url']['bg']);
    }

    public function test_button_label_without_link_is_an_error(): void
    {
        [, $e] = home_validate_section('cta', ['btn1_label' => ['bg' => 'Дари', 'en' => ''], 'btn1_url' => ['bg' => '', 'en' => '']]);
        $this->assertArrayHasKey('btn1_url.bg', $e);
    }

    public function test_english_label_may_reuse_the_bulgarian_link(): void
    {
        [, $e] = home_validate_section('cta', [
            'btn1_label' => ['bg' => 'Дари', 'en' => 'Donate'], 'btn1_url' => ['bg' => '/magazin/', 'en' => ''],
        ]);
        $this->assertArrayNotHasKey('btn1_url.en', $e);
    }

    public function test_rich_text_is_cleaned(): void
    {
        [$f] = home_validate_section('richtext', ['body' => ['bg' => '<p onclick="x">Hi</p>', 'en' => '']]);
        $this->assertSame('<p>Hi</p>', $f['body']['bg']);
    }

    public function test_choice_defaults_and_rejects_tampering(): void
    {
        [$f, $e] = home_validate_section('cta', []);
        $this->assertSame('teal', $f['background']);
        $this->assertSame([], array_intersect_key($e, ['background' => 1]));
        [$f, $e] = home_validate_section('cta', ['background' => 'hotpink']);
        $this->assertSame('teal', $f['background']);
        $this->assertArrayHasKey('background', $e);
    }

    public function test_image_outside_assets_is_an_error(): void
    {
        [, $e] = home_validate_section('text_image', ['image' => '/etc/passwd']);
        $this->assertArrayHasKey('image', $e);
    }

    public function test_video_is_required_and_stored_as_provider_and_id(): void
    {
        [, $e] = home_validate_section('video', []);
        $this->assertArrayHasKey('video', $e);
        [, $e] = home_validate_section('video', ['video' => 'https://example.org/x']);
        $this->assertArrayHasKey('video', $e);
        [$f, $e] = home_validate_section('video', ['video' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->assertSame([], $e);
        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], $f['video']);
        $this->assertSame('', $f['thumb']);
    }

    public function test_cards_need_two_to_four_with_titles(): void
    {
        $card = fn(string $t) => ['title' => ['bg' => $t, 'en' => ''], 'link' => ['bg' => '/za-nas/', 'en' => '']];
        [, $e] = home_validate_section('cards', ['cards' => [$card('A')]]);
        $this->assertArrayHasKey('cards', $e);
        [, $e] = home_validate_section('cards', ['cards' => array_fill(0, 5, $card('A'))]);
        $this->assertArrayHasKey('cards', $e);
        [$f, $e] = home_validate_section('cards', ['cards' => ['7' => $card('A'), '3' => $card('')]]);
        $this->assertArrayHasKey('cards.1.title.bg', $e);
        $this->assertArrayNotHasKey('cards', $e);
        $this->assertSame('A', $f['cards'][0]['title']['bg']);   // re-indexed in submitted order
    }

    public function test_builtin_campaign_has_no_fields(): void
    {
        [$f, $e] = home_validate_section('campaign', ['anything' => 'x']);
        $this->assertSame([], $f);
        $this->assertSame([], $e);
    }
}
