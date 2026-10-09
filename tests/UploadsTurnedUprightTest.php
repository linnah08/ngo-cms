<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every place that saves an uploaded photo must turn a phone photo upright
 * (image_resize_to_fit() or image_fix_orientation() in includes/images.php) —
 * otherwise it shows sideways once GD touches it. A new upload path that forgets
 * fails here; one that never takes photos goes in EXEMPT with the reason.
 */
#[Group('images')]
final class UploadsTurnedUprightTest extends TestCase
{
    private const EXEMPT = [
        'includes/financial_reports.php' => 'annual reports are PDFs',
    ];

    public function testEveryPhotoUploadTurnsPhotosUpright(): void
    {
        $root = dirname(__DIR__);
        $skip = ['vendor', 'node_modules', 'tests', '.git', '.claude', 'uploads', 'documents', 'logs', 'backups'];
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $f): bool => !$f->isDir() || !($f->getPath() === $root && in_array($f->getFilename(), $skip, true))
        ));
        $checked = 0;
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = substr($f->getPathname(), strlen($root) + 1);
            $src = (string) file_get_contents($f->getPathname());
            $moves = preg_match_all('/\bmove_uploaded_file\s*\(/', $src);
            if (!$moves || isset(self::EXEMPT[$rel])) continue;
            $upright = preg_match_all('/\bimage_(resize_to_fit|fix_orientation)\s*\(/', $src);
            $this->assertGreaterThanOrEqual($moves, $upright, "$rel saves $moves upload(s) but turns only $upright upright");
            $checked++;
        }
        $this->assertGreaterThan(5, $checked, 'the scan found the upload files');
    }
}
