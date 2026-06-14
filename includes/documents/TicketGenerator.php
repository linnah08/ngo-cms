<?php
require_once __DIR__ . '/DocumentGenerator.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * Generates a PDF event ticket for a campaign pledge.
 *
 * Expected $document keys:
 *   ticket_code  — unique ticket identifier (e.g. TKT-20260530-A1B2)
 *   event_name   — string
 *   event_date   — Y-m-d
 *   event_time   — HH:MM (optional)
 *   event_place  — string (optional)
 *
 * Expected $order keys (pledge row):
 *   name, email, pledge_number, amount_eur, created_at
 */
class TicketGenerator extends DocumentGenerator {

    protected function buildHtml(array $order, array $items, array $document): string {
        $f   = self::FOUNDATION;
        $c   = self::COLORS;
        $t   = $c['teal'];
        $tl  = $c['teal_light'];
        $td  = $c['teal_dark'];
        $br  = $c['border'];
        $mu  = $c['muted'];
        $bg  = $c['bg'];

        $ticket_code = htmlspecialchars($document['ticket_code'] ?? '', ENT_QUOTES, 'UTF-8');
        $event_name  = htmlspecialchars($document['event_name']  ?? 'Събитие', ENT_QUOTES, 'UTF-8');
        $event_date_raw = $document['event_date'] ?? '';
        $event_date  = $event_date_raw
            ? (new DateTimeImmutable($event_date_raw))->format('d.m.Y')
            : '';
        $event_time  = htmlspecialchars($document['event_time']  ?? '', ENT_QUOTES, 'UTF-8');
        $event_place = htmlspecialchars($document['event_place'] ?? '', ENT_QUOTES, 'UTF-8');

        $name   = htmlspecialchars($order['name']          ?? '', ENT_QUOTES, 'UTF-8');
        $email  = htmlspecialchars($order['email']         ?? '', ENT_QUOTES, 'UTF-8');
        $pledge = htmlspecialchars($order['pledge_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $amount = number_format((float)($order['amount_eur'] ?? 0), 2, '.', ' ');
        $issued = (new DateTimeImmutable($order['created_at'] ?? 'now'))->format('d.m.Y');

        $when_line = $event_date . ($event_time ? ', ' . $event_time . ' ч.' : '');

        // Generate QR code as base64 PNG data URI
        $qr_img_tag = '';
        $raw_code = $document['ticket_code'] ?? '';
        if ($raw_code !== '') {
            try {
                $options = new QROptions;
                $options->outputType  = QRGdImagePNG::class;
                $options->scale       = 6;
                $options->imageBase64 = true;
                $options->quietzoneSize = 2;
                $qr_data_uri = (new QRCode($options))->render($raw_code);
                $qr_img_tag  = '<img src="' . $qr_data_uri . '" alt="QR" style="width:90px;height:90px;display:block;margin:6px auto 0;">';
            } catch (Throwable $e) {
                // QR generation failed — degrade gracefully, code still shown as text
            }
        }

        // Pre-build optional blocks
        $header_sub = ($when_line || $event_place)
            ? '<div class="event-sub">' . $when_line
              . ($when_line && $event_place ? ' &nbsp;·&nbsp; ' : '')
              . $event_place . '</div>'
            : '';

        $when_row  = $when_line
            ? '<div class="info-row"><span class="info-label">Дата / час</span><span class="info-value">' . $when_line . '</span></div>'
            : '';
        $place_row = $event_place
            ? '<div class="info-row"><span class="info-label">Място</span><span class="info-value">' . $event_place . '</span></div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #1a1a2e; margin: 0; padding: 0; }
.ticket-wrap { border: 3px solid {$t}; border-radius: 10px; overflow: hidden; margin: 10px; }
.ticket-header { background: {$t}; color: #fff; padding: 18px 24px 14px; text-align: center; }
.ticket-header .foundation { font-size: 8pt; opacity: .8; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 6px; }
.ticket-header .event-name { font-size: 18pt; font-weight: bold; letter-spacing: .02em; line-height: 1.2; }
.event-sub { font-size: 10pt; margin-top: 6px; opacity: .9; }
.ticket-body { background: #fff; }
.ticket-main { padding: 20px 24px 16px; border-bottom: 2px dashed {$br}; }
.ticket-stub { padding: 14px 24px; background: {$tl}; text-align: center; }
.info-row { display: flex; gap: 8px; margin-bottom: 14px; align-items: flex-start; }
.info-label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .06em; color: {$mu}; font-weight: bold; min-width: 80px; padding-top: 3px; margin-right: 10px; }
.info-value { font-size: 10pt; font-weight: 600; color: #1a1a2e; }
.divider { border: none; border-top: 1px solid {$br}; margin: 16px 0; }
.code-box { display: inline-block; background: #fff; border: 2px solid {$t}; border-radius: 6px; padding: 8px 18px; font-family: monospace; font-size: 13pt; font-weight: bold; color: {$t}; letter-spacing: .12em; margin: 6px 0; }
.stub-admit { font-size: 9pt; font-weight: bold; color: {$td}; margin-top: 6px; text-transform: uppercase; letter-spacing: .08em; }
.ticket-footer { background: {$bg}; border-top: 1px solid {$br}; padding: 7px 24px; font-size: 7.5pt; color: {$mu}; text-align: center; }
</style>
</head>
<body>
<div class="ticket-wrap">

  <div class="ticket-header">
    <div class="foundation">{$f['name']}</div>
    <div class="event-name">{$event_name}</div>
    {$header_sub}
  </div>

  <div class="ticket-body">
    <div class="ticket-main">
      <div class="info-row"><span class="info-label">Притежател</span><span class="info-value">{$name}</span></div>
      <div class="info-row"><span class="info-label">Имейл</span><span class="info-value" style="font-size:9pt;">{$email}</span></div>
      <hr class="divider">
      <div class="info-row"><span class="info-label">Събитие</span><span class="info-value">{$event_name}</span></div>
      {$when_row}
      {$place_row}
      <hr class="divider">
      <div class="info-row"><span class="info-label">Билет №</span><span class="info-value" style="font-size:9pt;">{$pledge}</span></div>
      <div class="info-row"><span class="info-label">Сума</span><span class="info-value">{$amount} EUR</span></div>
      <div class="info-row"><span class="info-label">Издаден</span><span class="info-value">{$issued}</span></div>
    </div>

    <div class="ticket-stub">
      <div style="font-size:8pt;text-transform:uppercase;letter-spacing:.07em;color:{$mu};margin-bottom:4px;">Код за вход</div>
      <div class="code-box">{$ticket_code}</div>
      {$qr_img_tag}
      <div style="font-size:7.5pt;color:{$mu};margin-top:8px;">1 лице &nbsp;·&nbsp; 1 вход</div>
      <div class="stub-admit">Admit One</div>
    </div>
  </div>

  <div class="ticket-footer">
    {$f['name']} &nbsp;·&nbsp; ЕИК {$f['eik']} &nbsp;·&nbsp; {$f['email']} &nbsp;·&nbsp; {$f['website']}
  </div>

</div>
</body>
</html>
HTML;
    }
}
