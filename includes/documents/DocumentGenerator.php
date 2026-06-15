<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

abstract class DocumentGenerator {

    const EUR_TO_BGN = 1.95583;

    // Issuer details printed on documents. Identity/bank fields come from
    // site.config (set by the wizard); the legal-registry fields (address, MOL,
    // EIK, registration note) are filled from ORG_* constants when present —
    // see org() below. TODO: add these to the install wizard for tax-correct docs.
    const FOUNDATION = [
        'name'         => SITE_NAME_BG,
        'bank'         => SITE_BANK_NAME,
        'bic'          => SITE_BIC,
        'iban'         => SITE_IBAN,
        'phone'        => SITE_PHONE,
        'email'        => SITE_EMAIL,
        'website'      => SITE_URL,
        'address'      => '',
        'mol'          => '',
        'eik'          => '',
        'registration' => '',
    ];

    const COLORS = [
        'teal'       => '#1b998b',
        'teal_light' => '#e8f5f3',
        'teal_dark'  => '#14796d',
        'text'       => '#1a1a2e',
        'muted'      => '#6b7280',
        'border'     => '#e2e8f0',
        'bg'         => '#f8fafc',
        'white'      => '#ffffff',
    ];

    protected function createMpdf(): \Mpdf\Mpdf {
        $tmp = sys_get_temp_dir() . '/mpdf_' . substr(md5(uniqid('', true)), 0, 8);
        if (!is_dir($tmp) && !mkdir($tmp, 0755, true)) {
            throw new \RuntimeException("Cannot create mPDF temp directory: {$tmp}");
        }
        return new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 14,
            'margin_bottom' => 14,
            'margin_left'   => 18,
            'margin_right'  => 18,
            'default_font'  => 'dejavusans',
            'tempDir'       => $tmp,
        ]);
    }

    abstract protected function buildHtml(array $order, array $items, array $document): string;

    public function generate(array $order, array $items, array $document): string {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($this->buildHtml($order, $items, $document));
        return $mpdf->Output('', 'S');
    }

    protected static function eur2bgn(float $eur): float {
        return round($eur * self::EUR_TO_BGN, 2);
    }

    protected static function fmtEur(float $v): string {
        return number_format($v, 2, '.', ' ') . ' €';
    }

    protected static function fmtBgn(float $v): string {
        return number_format($v, 2, '.', ' ') . ' лв.';
    }

    protected static function fmtDate(string $date): string {
        $d = date_create($date);
        return $d ? date_format($d, 'd.m.Y') : substr($date, 0, 10);
    }

    protected static function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected static function amountInWordsBg(float $amount): string {
        $lv = (int) floor($amount);
        $st = (int) round(($amount - $lv) * 100);
        $result = ucfirst(self::intToWordsBg($lv)) . ' лв.';
        if ($st > 0) {
            $result .= ', ' . str_pad((string) $st, 2, '0', STR_PAD_LEFT) . ' ст.';
        }
        return $result;
    }

    protected static function amountInWordsEur(float $amount): string {
        $eur  = (int) floor($amount);
        $cent = (int) round(($amount - $eur) * 100);
        $result = ucfirst(self::intToWordsBg($eur)) . ' евро';
        if ($cent > 0) {
            $result .= ' и ' . str_pad((string) $cent, 2, '0', STR_PAD_LEFT) . ' цента';
        }
        return $result;
    }

    protected static function intToWordsBg(int $n): string {
        if ($n === 0) return 'нула';
        if ($n < 0)  return 'минус ' . self::intToWordsBg(-$n);

        $ones = [
            '', 'един', 'два', 'три', 'четири', 'пет', 'шест', 'седем', 'осем', 'девет',
            'десет', 'единадесет', 'дванадесет', 'тринадесет', 'четиринадесет', 'петнадесет',
            'шестнадесет', 'седемнадесет', 'осемнадесет', 'деветнадесет',
        ];
        $tens = [
            '', '', 'двадесет', 'тридесет', 'четиридесет', 'петдесет',
            'шестдесет', 'седемдесет', 'осемдесет', 'деветдесет',
        ];
        $hundreds = [
            '', 'сто', 'двеста', 'триста', 'четиристотин', 'петстотин',
            'шестотин', 'седемстотин', 'осемстотин', 'деветстотин',
        ];
        $thou_fem = [
            '', 'една', 'две', 'три', 'четири', 'пет', 'шест', 'седем', 'осем', 'девет',
            'десет', 'единадесет', 'дванадесет', 'тринадесет', 'четиринадесет', 'петнадесет',
            'шестнадесет', 'седемнадесет', 'осемнадесет', 'деветнадесет',
        ];

        $parts = [];

        if ($n >= 1_000_000) {
            $m  = (int) ($n / 1_000_000);
            $n %= 1_000_000;
            $parts[] = self::intToWordsBg($m) . ($m === 1 ? ' милион' : ' милиона');
        }

        if ($n >= 1000) {
            $th = (int) ($n / 1000);
            $n %= 1000;
            if ($th === 1) {
                $parts[] = 'хиляда';
            } elseif ($th < 20) {
                $parts[] = $thou_fem[$th] . ' хиляди';
            } else {
                $t = $tens[(int) ($th / 10)];
                $o = $th % 10;
                $parts[] = ($o ? $t . ' и ' . $thou_fem[$o] : $t) . ' хиляди';
            }
        }

        if ($n >= 100) {
            $parts[] = $hundreds[(int) ($n / 100)];
            $n %= 100;
        }

        if ($n >= 20) {
            $t = $tens[(int) ($n / 10)];
            $o = $ones[$n % 10];
            $parts[] = $o ? $t . ' и ' . $o : $t;
        } elseif ($n > 0) {
            $parts[] = $ones[$n];
        }

        return implode(' и ', $parts);
    }

    protected static function sharedCss(): string {
        $t  = self::COLORS['teal'];
        $tl = self::COLORS['teal_light'];
        $td = self::COLORS['teal_dark'];
        $tx = self::COLORS['text'];
        $mu = self::COLORS['muted'];
        $br = self::COLORS['border'];
        $bg = self::COLORS['bg'];
        return "
            body { font-family: DejaVuSans, sans-serif; font-size: 9.5pt; color: {$tx}; margin: 0; }
            table { border-collapse: collapse; }
            .teal   { color: {$t}; }
            .muted  { color: {$mu}; }
            .label  { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.08em; color: {$mu}; font-weight: bold; }
            .section-title {
                background: {$t}; color: #fff; padding: 5px 10px;
                font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.07em;
            }
            .info-table { width: 100%; font-size: 9pt; }
            .info-table td { padding: 4px 8px; border-bottom: 1px solid {$br}; vertical-align: top; }
            .info-table td:first-child { font-weight: bold; width: 45%; background: {$bg}; }
            .items-table { width: 100%; font-size: 9pt; }
            .items-table th {
                background: {$t}; color: #fff; padding: 6px 9px;
                text-align: left; font-size: 8pt;
            }
            .items-table th.right { text-align: right; }
            .items-table td { padding: 6px 9px; border-bottom: 1px solid {$br}; vertical-align: middle; }
            .items-table td.right { text-align: right; }
            .items-table tr.even td { background: {$bg}; }
            .items-table tr.total td {
                border-top: 2px solid {$t}; border-bottom: none;
                font-weight: bold; font-size: 10pt; color: {$t};
            }
            .items-table tr.subtotal td { border-bottom: none; font-weight: bold; color: {$tx}; }
        ";
    }
}
