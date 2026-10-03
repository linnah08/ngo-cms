<?php
/**
 * The PDF LinkedIn shows as a swipeable "document" carousel: one square page per photo,
 * main photo first, its English caption underneath (Bulgarian when there is no English).
 */
require_once __DIR__ . '/../social_images.php';

final class LinkedInCarouselGenerator
{
    /** Square page, in mm. 1080px at 96 dpi ≈ 285.75mm. */
    private const SIDE = 285.75;

    public static function html(array $bg, array $en): string
    {
        $en_caps = array_column(article_photos(array_merge($en, ['image' => $bg['image'] ?? ''])), 'caption', 'src');
        $bg_caps = array_column(article_photos($bg), 'caption', 'src');
        $html = '<style>body{font-family:dejavusans;margin:0}.p{text-align:center}'
              . '.p img{width:240mm;height:240mm;object-fit:cover}.c{font-size:16pt;color:#1a1a2e;margin-top:6mm}</style>';
        $first = true;
        foreach (social_photo_paths($bg) as $path) {
            $ready = social_prepare_image($path, 'square') ?? $path;
            $cap   = ($en_caps[$path] ?? '') !== '' ? $en_caps[$path] : ($bg_caps[$path] ?? '');
            $html .= ($first ? '' : '<pagebreak />')
                   . '<div class="p"><img src="' . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] . $ready, ENT_QUOTES, 'UTF-8') . '">'
                   . ($cap !== '' ? '<div class="c">' . htmlspecialchars($cap, ENT_QUOTES, 'UTF-8') . '</div>' : '')
                   . '</div>';
            $first = false;
        }
        return $html;
    }

    public static function build(array $bg, array $en): string
    {
        $tmp = sys_get_temp_dir() . '/mpdf_' . substr(md5(uniqid('', true)), 0, 8);
        if (!is_dir($tmp) && !mkdir($tmp, 0755, true)) {
            throw new \RuntimeException("Cannot create mPDF temp directory: {$tmp}");
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => [self::SIDE, self::SIDE],
            'margin_top' => 18, 'margin_bottom' => 12, 'margin_left' => 22, 'margin_right' => 22,
            'default_font' => 'dejavusans', 'tempDir' => $tmp,
        ]);
        $mpdf->SetTitle((string) ($en['title'] ?? $bg['title'] ?? ''));
        $mpdf->WriteHTML(self::html($bg, $en));
        return $mpdf->Output('', 'S');
    }

    /** Write the PDF next to the post's images; null when there are fewer than two photos. */
    public static function save(array $bg, array $en): ?string
    {
        if (count(article_photos($bg)) < 2) return null;
        $hash = substr(md5(json_encode([$bg['photos'] ?? [], $en['photos'] ?? [], $bg['image'] ?? ''])), 0, 10);
        $path = '/assets/images/articles/linkedin-' . ascii_slug((string) ($bg['slug'] ?? 'post')) . '-' . $hash . '.pdf';
        $abs  = $_SERVER['DOCUMENT_ROOT'] . $path;
        if (!is_file($abs) && file_put_contents($abs, self::build($bg, $en)) === false) return null;
        return $path;
    }
}
