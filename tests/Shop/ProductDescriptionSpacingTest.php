<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The product description is HTML from TinyMCE. Shown with white-space:pre-wrap,
 * every line break between its paragraphs became an extra blank line, leaving
 * big empty gaps on the product page.
 */
#[Group('shop')]
final class ProductDescriptionSpacingTest extends TestCase
{
    public function testDescriptionDoesNotKeepSourceLineBreaks(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/magazin/index.php');

        $this->assertMatchesRegularExpression('#<div style="([^"]*)"\s+data-cms-field="description"#', $src);
        preg_match('#<div style="([^"]*)"\s+data-cms-field="description"#', $src, $m);
        $this->assertStringNotContainsString('pre-wrap', $m[1]);
    }
}
