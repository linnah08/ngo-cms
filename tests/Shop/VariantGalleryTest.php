<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
final class VariantGalleryTest extends TestCase
{
    public function testReturnsImagesInStoredOrder(): void
    {
        $pv = ['image' => 'b.jpg', 'images' => json_encode(['a.jpg', 'b.jpg', 'c.jpg'])];
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], variant_gallery($pv));
    }

    public function testFallsBackToPrimaryWhenImagesEmpty(): void
    {
        $this->assertSame(['only.jpg'], variant_gallery(['image' => 'only.jpg', 'images' => null]));
        $this->assertSame(['only.jpg'], variant_gallery(['image' => 'only.jpg', 'images' => '[]']));
    }

    public function testEmptyWhenNoPhotos(): void
    {
        $this->assertSame([], variant_gallery(['image' => '', 'images' => null]));
        $this->assertSame([], variant_gallery([]));
    }

    public function testDedupesAndTrims(): void
    {
        $pv = ['image' => 'a.jpg', 'images' => json_encode(['a.jpg', 'a.jpg', ' b.jpg ', ''])];
        $this->assertSame(['a.jpg', 'b.jpg'], variant_gallery($pv));
    }

    public function testMalformedJsonFallsBackToPrimary(): void
    {
        $this->assertSame(['p.jpg'], variant_gallery(['image' => 'p.jpg', 'images' => 'not json']));
    }
}
