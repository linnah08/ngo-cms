<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeVideoThumbTest extends TestCase
{
    private string $root;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/home-thumb-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_youtube_thumbnail_is_saved_locally(): void
    {
        $asked = [];
        $get = function (string $url) use (&$asked) { $asked[] = $url; return base64_decode(self::PNG); };
        $path = home_fetch_video_thumb(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], 's_ab12', $get, $this->root);
        $this->assertSame('/assets/images/pages/home/s_ab12-video-dQw4w9WgXcQ.png', $path);
        $this->assertFileExists($this->root . $path);
        $this->assertSame(['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'], $asked);
    }

    public function test_vimeo_uses_oembed_and_only_trusts_vimeocdn(): void
    {
        $get = fn(string $url) => str_contains($url, 'oembed')
            ? json_encode(['thumbnail_url' => 'https://i.vimeocdn.com/video/1_640.jpg'])
            : base64_decode(self::PNG);
        $this->assertSame('/assets/images/pages/home/s_ab12-video-76979871.png',
            home_fetch_video_thumb(['provider' => 'vimeo', 'id' => '76979871'], 's_ab12', $get, $this->root));

        $evil = fn(string $url) => str_contains($url, 'oembed')
            ? json_encode(['thumbnail_url' => 'https://evil.example/x.jpg'])
            : base64_decode(self::PNG);
        $this->assertSame('', home_fetch_video_thumb(['provider' => 'vimeo', 'id' => '76979871'], 's_ab12', $evil, $this->root));
    }

    public function test_failures_are_not_errors(): void
    {
        $v = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'];
        $this->assertSame('', home_fetch_video_thumb($v, 's_ab12', fn() => null, $this->root));
        $this->assertSame('', home_fetch_video_thumb($v, 's_ab12', fn() => '<html>not an image</html>', $this->root));
        $this->assertSame('', home_fetch_video_thumb(['provider' => 'youtube', 'id' => '../../x'], 's_ab12', fn() => base64_decode(self::PNG), $this->root));
    }
}
