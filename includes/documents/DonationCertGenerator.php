<?php
require_once __DIR__ . '/DocumentGenerator.php';

class DonationCertGenerator extends DocumentGenerator {

    public function generateSigned(array $order, array $items, array $document, string $signature_b64): string {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($this->buildHtml($order, $items, $document, $signature_b64));
        return $mpdf->Output('', 'S');
    }

    protected function getOrg(): array {
        return self::FOUNDATION;
    }

    protected function getOrgText(bool $en): array {
        return [
            'reg_note'  => $en
                ? 'Reg. in the Central Register of Non-Profit Legal Entities in the public benefit, Ministry of Justice of Bulgaria'
                : 'Регистрирана в обществена полза в Централния регистър на ЮЛНЦ към Министерство на правосъдието',
            'intro'     => $en
                ? 'This certificate is issued by ' . SITE_NAME_EN . ', registered in the Central Register of Non-Profit Legal Entities in the public benefit at the Bulgarian Ministry of Justice, confirming receipt of a donation under the following terms:'
                : 'С настоящия сертификат ' . SITE_NAME_BG . ', вписана в Централния регистър на юридическите лица с нестопанска цел в обществена полза към Министерство на правосъдието, удостоверява, че е получила дарение при следните условия:',
            'sig_label' => $en ? 'Foundation Director:' : 'Управител на фондацията:',
        ];
    }

    protected function buildHtml(array $order, array $items, array $document, ?string $signature_b64 = null): string {
        $f    = $this->getOrg();
        $num  = $document['formatted_number'];
        $c    = self::COLORS;

        $inv  = json_decode($order['invoice_data'] ?? '{}', true) ?? [];
        $lang = $inv['lang'] ?? 'bg';
        $en   = $lang === 'en';

        // Donation amount: from items or from total
        $don_amount = 0.0;
        $recipient  = '';
        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'donation') {
                $don_amount += (float) ($item['amount_eur'] ?? 0);
                if (!$recipient) $recipient = $item['recipient'] ?? '';
            }
        }
        if ($don_amount <= 0) {
            $don_amount = (float) $order['total_eur'];
        }
        $don_bgn = self::eur2bgn($don_amount);

        // Donor info
        $donor_type  = $inv['donor_type'] ?? 'individual';
        $donor_label = $en
            ? ($donor_type === 'company' ? 'Legal entity' : 'Individual')
            : ($donor_type === 'company' ? 'Юридическо лице' : 'Физическо лице');
        $donor_name  = self::h(
            $donor_type === 'company' && !empty($inv['company_name'])
                ? $inv['company_name']
                : $order['customer_name']
        );

        // Date received
        $date_received = self::fmtDate($order['created_at']);
        $date_issued   = $date_received;

        // Payment method label
        $pay_labels = $en ? [
            'cod'           => 'Cash',
            'card'          => 'Bank card',
            'bank_transfer' => 'Bank transfer',
        ] : [
            'cod'           => 'В брой',
            'card'          => 'Банкова карта',
            'bank_transfer' => 'Банков превод',
        ];
        $pay_label = $pay_labels[$order['payment_method'] ?? 'card'] ?? ($en ? 'Bank card' : 'Банкова карта');

        // Purpose — a single configurable line (all donations fund the org).
        $purpose = self::h($en
            ? (defined('DONATION_PURPOSE_EN') ? DONATION_PURPOSE_EN : 'For the activities and programmes of ' . (defined('SITE_NAME_EN') ? SITE_NAME_EN : ''))
            : (defined('DONATION_PURPOSE_BG') ? DONATION_PURPOSE_BG : 'За дейността и програмите на ' . (defined('SITE_NAME_BG') ? SITE_NAME_BG : '')));

        $sig_html = $signature_b64
            ? '<img src="data:image/png;base64,' . htmlspecialchars($signature_b64, ENT_QUOTES, 'UTF-8') . '" style="height:52px;display:block;margin:4px 0 4px auto;max-width:220px;">'
            : '<div style="height:32px;"></div>';

        $css = self::sharedCss();
        $t   = $c['teal'];
        $br  = $c['border'];
        $bg  = $c['bg'];
        $mu  = $c['muted'];

        // B2B extras
        $extra_rows = '';
        if ($donor_type === 'company') {
            if (!empty($inv['eik'])) {
                $extra_rows .= $this->infoRow($en ? 'Company ID (EIK)' : 'ЕИК / Булстат', self::h($inv['eik']));
            }
            if (!empty($inv['vat_number'])) {
                $extra_rows .= $this->infoRow($en ? 'VAT number' : 'ДДС номер', self::h($inv['vat_number']));
            }
            if (!empty($inv['mol'])) {
                $extra_rows .= $this->infoRow($en ? 'Representative' : 'МОЛ', self::h($inv['mol']));
            }
        }

        // Language-dependent strings
        $orgText      = $this->getOrgText($en);
        $reg_note     = $orgText['reg_note'];
        $intro        = $orgText['intro'];
        $title        = $en ? 'DONATION CERTIFICATE' : 'СЕРТИФИКАТ ЗА ДАРЕНИЕ';
        $sec_donor    = $en ? 'DONOR DETAILS'    : 'Данни за дарителя';
        $sec_donation = $en ? 'DONATION DETAILS'  : 'Данни за дарението';
        $lbl_name     = $en ? 'Full name'          : 'Пълно име';
        $lbl_type     = $en ? 'Donor type'         : 'Вид дарител';
        $lbl_kind     = $en ? 'Type of donation'   : 'Вид на дарението';
        $lbl_kind_val = $en ? 'Monetary donation'  : 'Парично дарение';
        $lbl_amount   = $en ? 'Amount'             : 'Размер / Стойност';
        $lbl_date     = $en ? 'Date received'      : 'Дата на получаване';
        $lbl_method   = $en ? 'Payment method'     : 'Начин на предаване';
        $lbl_iban     = $en ? 'Bank account (IBAN)': 'Банкова сметка (IBAN)';
        $lbl_purpose  = $en ? 'Purpose'            : 'Цел на дарението';
        $legal        = $en
            ? 'This certificate is issued pursuant to Bulgarian tax law (Art. 22 of the Personal Income Tax Act and Art. 31 of the Corporate Income Tax Act) and may be used by the donor to claim a tax deduction when filing an annual tax return. Individuals may deduct up to 5% of their tax base; legal entities — up to 10% of their accounting profit.'
            : 'Настоящият сертификат се издава на основание чл.&nbsp;22 от ЗДДФЛ и чл.&nbsp;31 от ЗКПО и може да се използва от дарителя за ползване на данъчно облекчение при подаване на годишна данъчна декларация. Физическите лица могат да приспаднат до 5% от данъчната си основа, а юридическите лица — до 10% от счетоводната печалба.';
        $sig_label    = $orgText['sig_label'];
        $sig_name     = ($f['mol'] ?? '') !== '' ? $f['mol'] : $f['name'];
        $addr_label   = $en ? 'Address' : 'Адрес';
        $tel_label    = $en ? 'Tel'     : 'Тел';
        $header_meta  = 'EIK: ' . self::h($f['eik']) . ' &nbsp;|&nbsp; ' . $addr_label . ': ' . self::h($f['address']);
        if (!empty($f['phone'])) $header_meta .= ' &nbsp;|&nbsp; ' . $tel_label . ': ' . self::h($f['phone']);
        if (!empty($f['email'])) $header_meta .= ' &nbsp;|&nbsp; Email: ' . self::h($f['email']);

        $amount_row   = $en
            ? self::fmtEur($don_amount)
            : self::fmtEur($don_amount) . ' / ' . self::fmtBgn($don_bgn);
        $reg_note_html = $reg_note !== ''
            ? '<div style="font-size:7pt;color:' . $mu . ';margin-top:2px;">' . $reg_note . '</div>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <style>
        {$css}
        .cert-title {
            font-size: 18pt; font-weight: bold; color: {$t};
            text-align: center; letter-spacing: 0.04em; margin: 16px 0 2px;
            text-decoration: underline; text-decoration-color: {$t};
        }
        .cert-num {
            font-size: 9pt; color: {$mu}; text-align: center; margin-bottom: 14px;
        }
        .intro {
            font-size: 9.5pt; line-height: 1.6; margin-bottom: 14px;
            text-align: justify;
        }
        .section-title {
            background: {$t}; color: #fff;
            padding: 5px 10px; font-size: 8pt; font-weight: bold;
            text-transform: uppercase; letter-spacing: 0.07em;
        }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .info-table td { padding: 6px 10px; border-bottom: 1px solid {$br}; font-size: 9pt; vertical-align: top; }
        .info-table td:first-child { font-weight: bold; width: 40%; background: {$bg}; }
        .legal {
            font-size: 7.5pt; color: {$mu}; line-height: 1.55; margin-top: 14px;
            border-top: 1px solid {$br}; padding-top: 10px;
        }
        .sig-block { margin-top: 24px; text-align: right; font-size: 9pt; }
        .sig-label  { color: {$mu}; font-size: 8pt; margin-bottom: 20px; }
        .sig-name   { font-weight: bold; font-size: 10pt; }
        .header-rule { border: none; border-top: 2px solid {$t}; margin: 6px 0 0; }
        </style>
        </head>
        <body>

        <!-- Issuing org header -->
        <div style="text-align:center;margin-bottom:4px;">
          <div style="font-size:14pt;font-weight:bold;color:{$t};letter-spacing:0.04em;">{$f['name']}</div>
          <div style="font-size:7.5pt;color:{$mu};margin-top:3px;">{$header_meta}</div>
          {$reg_note_html}
        </div>
        <hr class="header-rule">

        <!-- Certificate title -->
        <div class="cert-title">{$title}</div>
        <div class="cert-num">№ {$num} &nbsp;/&nbsp; {$date_issued}</div>

        <!-- Intro paragraph -->
        <div class="intro">{$intro}</div>

        <!-- Donor data -->
        <div class="section-title">{$sec_donor}</div>
        <table class="info-table">
          {$this->infoRow($lbl_name, $donor_name)}
          {$this->infoRow($lbl_type, $donor_label)}
          {$extra_rows}
        </table>

        <!-- Donation data -->
        <div class="section-title">{$sec_donation}</div>
        <table class="info-table">
          {$this->infoRow($lbl_kind,    $lbl_kind_val)}
          {$this->infoRow($lbl_amount,  $amount_row)}
          {$this->infoRow($lbl_date,    $date_received)}
          {$this->infoRow($lbl_method,  $pay_label)}
          {$this->infoRow($lbl_iban,    self::h($f['iban']))}
          {$this->infoRow($lbl_purpose, $purpose)}
        </table>

        <!-- Legal -->
        <div class="legal">{$legal}</div>

        <!-- Signature -->
        <div class="sig-block">
          <div class="sig-label">{$sig_label}</div>
          {$sig_html}
          <div class="sig-name">{$sig_name}</div>
        </div>

        </body>
        </html>
        HTML;
    }

    private function infoRow(string $label, string $value): string {
        return "<tr><td>{$label}</td><td>{$value}</td></tr>";
    }
}
