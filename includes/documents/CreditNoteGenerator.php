<?php
require_once __DIR__ . '/DocumentGenerator.php';

class CreditNoteGenerator extends DocumentGenerator {

    protected function buildHtml(array $order, array $items, array $document): string {
        $f    = self::FOUNDATION;
        $date = self::fmtDate(date('Y-m-d H:i:s')); // credit note date = today
        $num  = $document['formatted_number'];
        $c    = self::COLORS;

        $source_num  = self::h($document['source_formatted_number'] ?? '');
        $source_date = self::h($document['source_date'] ?? '');

        $product_items  = array_filter($items, fn($i) => ($i['type'] ?? '') !== 'donation');
        $donation_items = array_filter($items, fn($i) => ($i['type'] ?? '') === 'donation');

        $subtotal       = (float) $order['subtotal_eur'];
        $shipping       = (float) $order['shipping_eur'];
        $total          = (float) $order['total_eur'];
        $donation_total = array_sum(array_column($donation_items, 'amount_eur'));
        $credit_total   = $total - $donation_total;

        $inv  = json_decode($order['invoice_data'] ?? '{}', true) ?? [];
        $recipient_name    = self::h($inv['company_name']    ?? $order['customer_name']);
        $recipient_address = self::h($inv['company_address'] ?? '');
        $recipient_mol     = self::h($inv['mol']             ?? '');
        $recipient_eik     = self::h($inv['eik']             ?? '');
        $recipient_vat     = self::h($inv['vat_number']      ?? '');

        $css = self::sharedCss();
        $t   = $c['teal'];
        $td  = $c['teal_dark'];
        $br  = $c['border'];
        $bg  = $c['bg'];
        $mu  = $c['muted'];

        // Items rows
        $rows_html = '';
        $i = 0;
        foreach ($product_items as $item) {
            $class = (++$i % 2 === 0) ? ' class="even"' : '';
            $name  = self::h($item['name_bg'] ?? $item['name'] ?? '');
            $qty   = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price_eur'] ?? 0);
            $sub   = (float) ($item['subtotal_eur'] ?? $price * $qty);
            $rows_html .= "
            <tr{$class}>
                <td>{$name}</td>
                <td style=\"text-align:center;\">бр.</td>
                <td style=\"text-align:center;\">{$qty}</td>
                <td class=\"right\">" . self::fmtEur($price) . "</td>
                <td class=\"right\">" . self::fmtEur($sub) . "</td>
            </tr>";
        }
        if ($shipping > 0) {
            $class = (++$i % 2 === 0) ? ' class="even"' : '';
            $rows_html .= "
            <tr{$class}>
                <td>Доставка</td>
                <td style=\"text-align:center;\">бр.</td>
                <td style=\"text-align:center;\">1</td>
                <td class=\"right\">" . self::fmtEur($shipping) . "</td>
                <td class=\"right\">" . self::fmtEur($shipping) . "</td>
            </tr>";
        }

        $amount_words = self::amountInWordsEur($credit_total);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <style>
        {$css}
        .doc-header { border-bottom: 3px solid {$t}; padding-bottom: 12px; margin-bottom: 14px; }
        .doc-title  { font-size: 17pt; font-weight: bold; color: {$t}; letter-spacing: -0.02em; }
        .doc-sub    { font-size: 8pt; color: {$mu}; text-transform: uppercase; letter-spacing: 0.08em; }
        .party-box  { border: 1px solid {$br}; border-radius: 4px; padding: 10px 12px; font-size: 9pt; }
        .party-name { font-size: 11pt; font-weight: bold; margin-bottom: 4px; }
        </style>
        </head>
        <body>

        <!-- Header -->
        <table class="doc-header" style="width:100%;margin-bottom:14px;border-bottom:3px solid {$t};">
          <tr>
            <td style="vertical-align:bottom;padding-bottom:8px;">
              <div class="doc-title">КРЕДИТНО ИЗВЕСТИЕ &nbsp;<span style="font-size:10pt;font-weight:normal;color:{$mu};">Оригинал</span></div>
              <div class="doc-sub">Към Фактура №{$source_num} / {$source_date}</div>
            </td>
            <td style="text-align:right;vertical-align:bottom;padding-bottom:8px;">
              <div style="font-size:8pt;color:{$mu};">Номер:</div>
              <div style="font-size:13pt;font-weight:bold;color:{$t};letter-spacing:0.04em;">{$num}</div>
              <div style="font-size:8pt;color:{$mu};margin-top:2px;">Дата: <strong style="color:#1a1a2e;">{$date}</strong></div>
            </td>
          </tr>
        </table>

        <!-- Parties -->
        <table style="width:100%;margin-bottom:14px;">
          <tr>
            <td style="width:49%;vertical-align:top;padding-right:8px;">
              <div class="label" style="margin-bottom:5px;">Получател</div>
              <div class="party-box">
                <div class="party-name">{$recipient_name}</div>
                {$this->partyRow('Адрес', $recipient_address)}
                {$this->partyRow('МОЛ', $recipient_mol)}
                {$this->partyRow('ЕИК / Булстат', $recipient_eik)}
                {$this->partyRow('ДДС №', $recipient_vat)}
              </div>
            </td>
            <td style="width:2%;"></td>
            <td style="width:49%;vertical-align:top;padding-left:8px;">
              <div class="label" style="margin-bottom:5px;">Доставчик</div>
              <div class="party-box">
                <div class="party-name">{$f['name']}</div>
                {$this->partyRow('Адрес', $f['address'])}
                {$this->partyRow('МОЛ', $f['mol'])}
                {$this->partyRow('ЕИК', $f['eik'])}
                {$this->partyRow('Банка', $f['bank'])}
                {$this->partyRow('IBAN', $f['iban'])}
                {$this->partyRow('BIC', $f['bic'])}
              </div>
            </td>
          </tr>
        </table>

        <!-- Items -->
        <table class="items-table" style="margin-bottom:10px;">
          <thead>
            <tr>
              <th style="width:45%;">Наименование</th>
              <th style="width:8%;text-align:center;">Мярка</th>
              <th style="width:10%;text-align:center;">К-во</th>
              <th class="right" style="width:17%;">Ед. цена</th>
              <th class="right" style="width:20%;">Стойност</th>
            </tr>
          </thead>
          <tbody>
            {$rows_html}
          </tbody>
        </table>

        <!-- Totals -->
        <table style="width:100%;margin-bottom:12px;">
          <tr>
            <td style="width:55%;vertical-align:top;">
              <div style="font-size:8pt;color:{$mu};margin-bottom:3px;">Основание за неначисляване на ДДС:</div>
              <div style="font-size:8.5pt;">Начислен ДДС, ставка 0%</div>
            </td>
            <td style="width:45%;vertical-align:top;">
              <table style="width:100%;font-size:9pt;border:1px solid {$br};border-radius:4px;">
                <tr>
                  <td style="padding:5px 10px;border-bottom:1px solid {$br};color:{$mu};">Данъчна основа</td>
                  <td style="padding:5px 10px;border-bottom:1px solid {$br};text-align:right;">{$this->fmtEur($credit_total)}</td>
                </tr>
                <tr>
                  <td style="padding:5px 10px;border-bottom:1px solid {$br};color:{$mu};">ДДС (0%)</td>
                  <td style="padding:5px 10px;border-bottom:1px solid {$br};text-align:right;">0,00 €</td>
                </tr>
                <tr>
                  <td style="padding:7px 10px;font-weight:bold;color:{$t};">Сума по кредитното известие</td>
                  <td style="padding:7px 10px;text-align:right;font-weight:bold;color:{$t};font-size:11pt;">{$this->fmtEur($credit_total)}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <!-- Amount in words -->
        <div style="background:{$bg};border:1px solid {$br};border-radius:4px;padding:7px 12px;margin-bottom:14px;font-size:8.5pt;">
          <span style="color:{$mu};">Словом:</span> <strong>{$amount_words}</strong>
        </div>

        <!-- Date -->
        <table style="width:100%;font-size:8.5pt;color:{$mu};margin-bottom:18px;">
          <tr>
            <td style="text-align:right;">Дата на данъчното събитие: <strong style="color:#1a1a2e;">{$date}</strong></td>
          </tr>
        </table>

        </body>
        </html>
        HTML;
    }

    private function partyRow(string $label, string $value): string {
        if ($value === '') return '';
        $mu = self::COLORS['muted'];
        return "<div style=\"font-size:8pt;margin-top:3px;\"><span style=\"color:{$mu};\">{$label}:</span> {$value}</div>";
    }
}
