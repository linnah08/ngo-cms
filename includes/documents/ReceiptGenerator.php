<?php
require_once __DIR__ . '/DocumentGenerator.php';

class ReceiptGenerator extends DocumentGenerator {

    protected function buildHtml(array $order, array $items, array $document): string {
        $f    = self::FOUNDATION;
        $date = self::fmtDate($order['created_at']);
        $num  = $document['formatted_number'];
        $c    = self::COLORS;

        $product_items  = array_filter($items, fn($i) => ($i['type'] ?? '') !== 'donation');
        $donation_items = array_filter($items, fn($i) => ($i['type'] ?? '') === 'donation');

        $subtotal = (float) $order['subtotal_eur'];
        $shipping = (float) $order['shipping_eur'];
        $total    = (float) $order['total_eur'];

        $donation_total  = array_sum(array_column($donation_items, 'amount_eur'));
        $receipt_total   = $total - $donation_total;

        $pay_labels = [
            'cod'           => 'Наложен платеж',
            'card'          => 'Банкова карта',
            'bank_transfer' => 'Банков превод',
        ];
        $pay_label = $pay_labels[$order['payment_method'] ?? ''] ?? 'В брой';

        $css = self::sharedCss();
        $t   = $c['teal'];
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
                <td style=\"text-align:center;\">{$qty}</td>
                <td style=\"text-align:center;\">0%</td>
                <td class=\"right\">" . self::fmtEur($price) . "</td>
                <td class=\"right\">" . self::fmtEur($sub) . "</td>
            </tr>";
        }
        if ($shipping > 0) {
            $class = (++$i % 2 === 0) ? ' class="even"' : '';
            $rows_html .= "
            <tr{$class}>
                <td>Доставка</td>
                <td style=\"text-align:center;\">1</td>
                <td style=\"text-align:center;\">0%</td>
                <td class=\"right\">" . self::fmtEur($shipping) . "</td>
                <td class=\"right\">" . self::fmtEur($shipping) . "</td>
            </tr>";
        }

        $order_number = self::h($order['order_number']);
        $cust_name    = self::h($order['customer_name']);
        $cust_email   = self::h($order['customer_email']);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <style>
        {$css}
        .header-rule { border: none; border-top: 2px solid {$t}; margin: 8px 0 14px 0; }
        .receipt-title { font-size: 14pt; font-weight: bold; color: {$t}; text-align: center; margin-bottom: 2px; }
        .receipt-num   { font-size: 9pt; color: {$mu}; text-align: center; margin-bottom: 12px; }
        .two-col td { vertical-align: top; width: 50%; padding: 10px 12px; font-size: 9pt; border: 1px solid {$br}; }
        </style>
        </head>
        <body>

        <!-- Foundation header -->
        <table style="width:100%;margin-bottom:6px;">
          <tr>
            <td style="vertical-align:middle;">
              <div style="font-size:13pt;font-weight:bold;color:{$t};">{$f['name']}</div>
              <div style="font-size:8pt;color:{$mu};margin-top:2px;">{$f['address']} &nbsp;|&nbsp; {$f['phone']} &nbsp;|&nbsp; {$f['email']}</div>
            </td>
          </tr>
        </table>
        <hr class="header-rule">

        <!-- Receipt title + number -->
        <div class="receipt-title">Електронна бележка</div>
        <div class="receipt-num">#{$num} &nbsp;от дата&nbsp; {$date}</div>

        <!-- Two columns: supplier + transaction details -->
        <table class="two-col" style="width:100%;margin-bottom:14px;border-collapse:collapse;">
          <tr>
            <td style="border-right:none;">
              <div class="label" style="margin-bottom:6px;">Доставчик</div>
              <div><strong>{$f['name']}</strong></div>
              <div style="margin-top:3px;font-size:8.5pt;color:{$mu};">{$f['address']}</div>
              <div style="margin-top:2px;font-size:8.5pt;"><span style="color:{$mu};">ЕИК:</span> {$f['eik']}</div>
              <div style="margin-top:2px;font-size:8.5pt;"><span style="color:{$mu};">МОЛ:</span> {$f['mol']}</div>
            </td>
            <td style="border-left:none;">
              <div class="label" style="margin-bottom:6px;">Клиент &amp; поръчка</div>
              <div><strong>{$cust_name}</strong></div>
              <div style="margin-top:3px;font-size:8.5pt;color:{$mu};">{$cust_email}</div>
              <div style="margin-top:6px;font-size:8.5pt;"><span style="color:{$mu};">Поръчка №:</span> {$order_number}</div>
              <div style="margin-top:2px;font-size:8.5pt;"><span style="color:{$mu};">Дата:</span> {$date}</div>
              <div style="margin-top:2px;font-size:8.5pt;"><span style="color:{$mu};">Плащане:</span> {$pay_label}</div>
            </td>
          </tr>
        </table>

        <!-- Items -->
        <div class="label" style="margin-bottom:5px;">Артикули</div>
        <table class="items-table" style="margin-bottom:12px;">
          <thead>
            <tr>
              <th style="width:47%;">Описание</th>
              <th style="width:10%;text-align:center;">К-во</th>
              <th style="width:10%;text-align:center;">ДДС</th>
              <th class="right" style="width:17%;">Ед. цена</th>
              <th class="right" style="width:16%;">Общо</th>
            </tr>
          </thead>
          <tbody>
            {$rows_html}
            <tr class="subtotal">
              <td colspan="4" class="right" style="padding-top:8px;color:{$mu};">Общо</td>
              <td class="right" style="padding-top:8px;font-size:11pt;color:{$t};">{$this->fmtEur($receipt_total)}</td>
            </tr>
          </tbody>
        </table>

        </body>
        </html>
        HTML;
    }
}
