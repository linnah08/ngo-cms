<?php
require_once __DIR__ . '/DocumentGenerator.php';

/**
 * Приемо-предавателен протокол — hand-over of stock to a distributor
 * (Distribution module, includes/distribution.php).
 *
 * Not order-based: `$order` carries the fields from distribution_protocol_fields()
 * (distributor_*, item_name, allocated_on, quantity, unit_price_eur); `$items`
 * is unused but kept to match the base signature.
 */
class DistributionProtocolGenerator extends DocumentGenerator {

    protected function buildHtml(array $order, array $items, array $document): string {
        $f    = self::foundation('bg');
        $c    = self::COLORS;
        $num  = self::h((string) $document['formatted_number']);
        $date = self::fmtDate((string) $order['allocated_on']);

        // Only print the issuer lines a site has filled in.
        $issuer_lines = '';
        foreach (['address' => 'Адрес', 'mol' => 'МОЛ', 'eik' => 'ЕИК'] as $key => $label) {
            if (($f[$key] ?? '') !== '') {
                $issuer_lines .= '<div style="font-size:8pt;margin-top:3px;"><span class="muted">' . $label . ':</span> ' . self::h($f[$key]) . '</div>';
            }
        }

        $recipient_lines = '';
        foreach ([
            'distributor_eik'     => 'ЕИК',
            'distributor_contact' => 'Лице за контакт',
            'distributor_address' => 'Адрес',
        ] as $key => $label) {
            $v = trim((string) ($order[$key] ?? ''));
            if ($v !== '') {
                $recipient_lines .= '<div style="font-size:8pt;margin-top:3px;"><span class="muted">' . $label . ':</span> ' . self::h($v) . '</div>';
            }
        }

        $recipient  = self::h((string) $order['distributor_name']);
        $item_name  = self::h((string) $order['item_name']);
        $quantity   = (int) $order['quantity'];
        $unit_price = (float) $order['unit_price_eur'];
        $unit_fmt   = self::fmtEur($unit_price);
        $total_fmt  = self::fmtEur($unit_price * $quantity);

        $css = self::sharedCss();
        $t   = $c['teal'];
        $mu  = $c['muted'];
        $br  = $c['border'];

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <style>
        {$css}
        .doc-header { border-bottom: 3px solid {$t}; padding-bottom: 12px; margin-bottom: 14px; }
        .doc-title  { font-size: 15pt; font-weight: bold; color: {$t}; }
        .party-box  { border: 1px solid {$br}; border-radius: 4px; padding: 10px 12px; font-size: 9pt; }
        .party-name { font-size: 11pt; font-weight: bold; margin-bottom: 4px; }
        .sig-line   { border-top: 1px solid #999; padding-top: 4px; font-size: 8pt; color: {$mu}; text-align: center; }
        </style>
        </head>
        <body>

        <table class="doc-header" style="width:100%;margin-bottom:14px;">
          <tr>
            <td style="vertical-align:bottom;">
              <div class="doc-title">ПРИЕМО-ПРЕДАВАТЕЛЕН ПРОТОКОЛ</div>
              <div class="label">за предаване на стоки</div>
            </td>
            <td style="text-align:right;vertical-align:bottom;">
              <div style="font-size:8pt;color:{$mu};">Номер:</div>
              <div style="font-size:13pt;font-weight:bold;color:{$t};">{$num}</div>
              <div style="font-size:8pt;color:{$mu};margin-top:2px;">Дата: <strong>{$date}</strong></div>
            </td>
          </tr>
        </table>

        <table style="width:100%;margin-bottom:14px;">
          <tr>
            <td style="width:49%;vertical-align:top;padding-right:8px;">
              <div class="label" style="margin-bottom:5px;">Предаващ</div>
              <div class="party-box">
                <div class="party-name">{$f['name']}</div>
                {$issuer_lines}
              </div>
            </td>
            <td style="width:2%;"></td>
            <td style="width:49%;vertical-align:top;padding-left:8px;">
              <div class="label" style="margin-bottom:5px;">Приемащ</div>
              <div class="party-box">
                <div class="party-name">{$recipient}</div>
                {$recipient_lines}
              </div>
            </td>
          </tr>
        </table>

        <table class="items-table" style="margin-bottom:14px;">
          <thead>
            <tr>
              <th style="width:40%;">Наименование</th>
              <th style="width:10%;text-align:center;">Мярка</th>
              <th style="width:12%;text-align:center;">К-во</th>
              <th class="right" style="width:18%;">Цена за брой</th>
              <th class="right" style="width:20%;">Стойност</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>{$item_name}</td>
              <td style="text-align:center;">бр.</td>
              <td style="text-align:center;">{$quantity}</td>
              <td class="right">{$unit_fmt}</td>
              <td class="right">{$total_fmt}</td>
            </tr>
          </tbody>
        </table>

        <table style="width:100%;margin-top:60px;">
          <tr>
            <td style="width:45%;" class="sig-line">Предал</td>
            <td style="width:10%;"></td>
            <td style="width:45%;" class="sig-line">Приел</td>
          </tr>
        </table>

        </body>
        </html>
        HTML;
    }
}
