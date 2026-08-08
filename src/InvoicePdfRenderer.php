<?php
final class InvoicePdfRenderer
{
    public function __construct(private array $config) {}

    public static function defaultTemplate(): string
    {
        return <<<'HTML'
<style>
    body { color: #212529; font-family: dejavusans, sans-serif; font-size: 10pt; }
    .invoice-header { border-bottom: 1px solid #e1e5eb; padding-bottom: 16px; }
    .invoice-title { font-size: 24pt; font-weight: bold; text-align: right; }
    .invoice-number { color: #5b636f; text-align: right; }
    .columns { width: 100%; margin-top: 30px; }
    .columns td { vertical-align: top; }
    .muted { color: #5b636f; font-size: 8pt; }
    .meta td { padding: 2px 0; }
    .items { width: 100%; border-collapse: collapse; margin-top: 28px; }
    .items th { background: #f6f8fb; color: #5b636f; border-bottom: 1px solid #e1e5eb; font-size: 8pt; padding: 7px 6px; text-align: left; }
    .items td { border-bottom: 1px solid #e1e5eb; padding: 7px 6px; vertical-align: top; }
    .item-description2 { color: #5b636f; font-size: 8pt; margin-top: 2px; }
    .right { text-align: right; }
    .totals { width: 42%; margin-left: auto; margin-top: 18px; }
    .totals td { padding: 4px 0; }
    .grand-total td { border-top: 1px solid #212529; font-size: 12pt; font-weight: bold; padding-top: 7px; }
</style>

<div class="invoice-header">
    <table width="100%">
        <tr>
            <td width="45%"><img src="{{logo_src}}" width="170"></td>
            <td width="55%">
                <div class="invoice-title">Rechnung</div>
                <div class="invoice-number">{{invoice.number}}</div>
            </td>
        </tr>
    </table>
</div>

<table class="columns">
    <tr>
        <td width="58%">
            <div class="muted">{{sender.name}}</div>
            <p>{{recipient.address_html}}</p>
        </td>
        <td width="42%">
            <table class="meta" width="100%">
                <tr><td class="muted">Rechnungsdatum</td><td class="right"><strong>{{invoice.date}}</strong></td></tr>
                <tr><td class="muted">Leistungszeitraum</td><td class="right"><strong>{{invoice.period}}</strong></td></tr>
                <tr><td class="muted">Kundennummer</td><td class="right"><strong>{{customer.number}}</strong></td></tr>
                <tr><td class="muted">Status</td><td class="right"><strong>{{invoice.status}}</strong></td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th width="40%">Beschreibung</th>
            <th width="10%" class="right">Menge</th>
            <th width="13%" class="right">Einzel</th>
            <th width="11%" class="right">Rabatt</th>
            <th width="12%" class="right">Netto</th>
            <th width="14%" class="right">Brutto</th>
        </tr>
    </thead>
    <tbody>
        {{#items}}
        <tr>
            <td>
                {{item.description}}
                <div class="item-description2">{{item.description2}}</div>
            </td>
            <td class="right">{{item.quantity}}</td>
            <td class="right">{{item.unit_price}}</td>
            <td class="right">{{item.discount_percent}}</td>
            <td class="right">{{item.net_total}}</td>
            <td class="right">{{item.gross_total}}</td>
        </tr>
        {{/items}}
    </tbody>
</table>

<table class="totals">
    <tr><td>Netto</td><td class="right">{{invoice.subtotal}}</td></tr>
    {{invoice.user_discount_row}}
    <tr><td>Steuer</td><td class="right">{{invoice.tax_total}}</td></tr>
    <tr class="grand-total"><td>Gesamt</td><td class="right">{{invoice.total}}</td></tr>
</table>
HTML;
    }

    public static function defaultDunningTemplate(): string
    {
        return <<<'HTML'
<style>
    body { color: #212529; font-family: dejavusans, sans-serif; font-size: 10pt; }
    .letter-header { border-bottom: 1px solid #e1e5eb; padding-bottom: 16px; }
    .letter-title { font-size: 22pt; font-weight: bold; text-align: right; }
    .muted { color: #5b636f; font-size: 8pt; }
    .content { margin-top: 34px; line-height: 1.55; }
    .amount { font-size: 14pt; font-weight: bold; }
</style>

<div class="letter-header">
    <table width="100%">
        <tr>
            <td width="45%"><img src="{{logo_src}}" width="170"></td>
            <td width="55%">
                <div class="letter-title">Mahnung</div>
                <div class="muted">Zur Rechnung {{invoice.number}}</div>
            </td>
        </tr>
    </table>
</div>

<p class="muted">{{sender.name}}</p>
<p>{{recipient.address_html}}</p>

<div class="content">
    <p>Sehr geehrte Damen und Herren,</p>
    <p>für die Rechnung <strong>{{invoice.number}}</strong> vom {{invoice.date}} ist noch ein offener Betrag vorhanden.</p>
    <p class="amount">Offener Betrag: {{invoice.total}}</p>
    <p>Bitte gleichen Sie den Betrag kurzfristig aus.</p>
</div>
HTML;
    }

    public function write(array $invoice, array $items, string $path, string $sender, ?string $template = null, string $paymentAccountDetails = ''): void
    {
        $this->loadTcpdf();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends TCPDF {
            public string $paymentAccountDetails = '';

            public function Footer(): void
            {
                $text = trim($this->paymentAccountDetails);
                if ($text === '') {
                    return;
                }
                $this->SetY(-24);
                $left = $this->getMargins()['left'] ?? 18;
                $right = $this->getMargins()['right'] ?? 18;
                $width = $this->getPageWidth() - $left - $right;
                $this->SetDrawColor(225, 229, 235);
                $this->Line($left, $this->GetY(), $left + $width, $this->GetY());
                $this->Ln(3);
                $this->SetTextColor(91, 99, 111);
                $this->SetFont('dejavusans', '', 7);
                $this->MultiCell($width, 3.8, $text, 0, 'C', false, 1, $left);
                if ($this->getNumPages() > 1) {
                    $this->SetY(-9);
                    $this->SetFont('dejavusans', '', 7);
                    $this->Cell($width, 4, 'Seite ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
                }
                $this->SetTextColor(33, 37, 41);
            }
        };
        $pdf->paymentAccountDetails = trim($paymentAccountDetails);
        $pdf->SetCreator($this->config['app']['name'] ?? 'KeyHelp MSM');
        $pdf->SetAuthor($this->senderName($sender));
        $pdf->SetTitle('Rechnung ' . (string)$invoice['invoice_number']);
        $pdf->SetSubject('Rechnung');
        $pdf->SetMargins(18, 18, 18);
        $pdf->SetAutoPageBreak(true, $pdf->paymentAccountDetails === '' ? 22 : 34);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter($pdf->paymentAccountDetails !== '');
        $pdf->AddPage();
        $pdf->writeHTML($this->renderTemplate($template ?: self::defaultTemplate(), $invoice, $items, $sender, true), true, false, true, false, '');

        $pdf->Output($path, 'F');
    }

    public function renderPreviewHtml(?string $template = null): string
    {
        return $this->renderTemplate($template ?: self::defaultTemplate(), $this->sampleInvoice(), $this->sampleItems(), 'Musterfirma GmbH <rechnung@example.com>', false);
    }

    private function renderTemplate(string $template, array $invoice, array $items, string $sender, bool $pdfMode): string
    {
        $variables = $this->templateVariables($invoice, $sender, $pdfMode);
        $html = preg_replace_callback('/\{\{#items\}\}(.*?)\{\{\/items\}\}/s', function (array $match) use ($items): string {
            $block = $match[1];
            $rows = '';
            foreach ($items as $item) {
                $rows .= $this->replaceVariables($block, $this->itemTemplateVariables($item));
            }
            return $rows;
        }, $template) ?? $template;
        return $this->replaceVariables($html, $variables);
    }

    private function replaceVariables(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', static function (array $match) use ($variables): string {
            return (string)($variables[$match[1]] ?? '');
        }, $template) ?? $template;
    }

    private function templateVariables(array $invoice, string $sender, bool $pdfMode): array
    {
        $createdAt = substr((string)($invoice['created_at'] ?? ''), 0, 10);
        $periodStart = substr((string)($invoice['period_start'] ?? ''), 0, 10);
        $periodEnd = substr((string)($invoice['period_end'] ?? ''), 0, 10);
        $variablesSnapshot = json_decode((string)($invoice['recipient_snapshot'] ?? ''), true) ?: [];
        $recipient = $this->recipientLines($invoice);
        $userDiscountPercent = (float)($variablesSnapshot['billing_discount_percent'] ?? 0);
        $userDiscountAmount = (float)($invoice['subtotal'] ?? 0) * min(100.0, max(0.0, $userDiscountPercent)) / 100.0;
        return [
            'logo_src' => $pdfMode ? dirname(__DIR__) . '/public/assets/khmsm_fulllogo_512.png' : '/assets/khmsm_fulllogo_512.png',
            'sender.name' => $this->e($this->senderName($sender)),
            'sender.full' => nl2br($this->e(trim($sender))),
            'recipient.address_html' => implode('<br>', array_map(fn(string $line): string => $this->e($line), $recipient)),
            'invoice.number' => $this->e((string)($invoice['invoice_number'] ?? '')),
            'invoice.date' => $this->e($this->formatDate($createdAt)),
            'invoice.period' => $this->e(trim($this->formatDate($periodStart) . ' - ' . $this->formatDate($periodEnd), ' -')),
            'invoice.status' => $this->e((string)($invoice['status'] ?? '')),
            'invoice.subtotal' => $this->e($this->money($invoice['subtotal'] ?? 0)),
            'invoice.user_discount_percent' => $this->e($this->percent($userDiscountPercent)),
            'invoice.user_discount_total' => $this->e($this->money(-$userDiscountAmount)),
            'invoice.user_discount_row' => $userDiscountPercent > 0
                ? '<tr><td>Gesamtrabatt (' . $this->e($this->percent($userDiscountPercent)) . ')</td><td class="right">' . $this->e($this->money(-$userDiscountAmount)) . '</td></tr>'
                : '',
            'invoice.tax_total' => $this->e($this->money($invoice['tax_total'] ?? 0)),
            'invoice.total' => $this->e($this->money($invoice['total'] ?? 0)),
            'customer.number' => $this->e((string)($variablesSnapshot['customer_number'] ?? $invoice['username'] ?? '')),
            'customer.email' => $this->e((string)($variablesSnapshot['email'] ?? $invoice['email'] ?? '')),
            'customer.server' => $this->e((string)($variablesSnapshot['server_name'] ?? $invoice['server_name'] ?? '')),
        ];
    }

    private function itemTemplateVariables(array $item): array
    {
        [$description, $description2] = $this->splitDescription((string)($item['description'] ?? ''));
        return [
            'item.description' => $this->e($description),
            'item.description2' => $this->e($description2),
            'item.quantity' => $this->e($this->formatQuantity($item['quantity'] ?? 1)),
            'item.unit_price' => $this->e($this->money($item['unit_price'] ?? 0)),
            'item.discount_percent' => $this->e($this->percent($item['discount_percent'] ?? 0)),
            'item.net_total' => $this->e($this->money($item['net_total'] ?? 0)),
            'item.tax_total' => $this->e($this->money($item['tax_total'] ?? 0)),
            'item.gross_total' => $this->e($this->money($item['gross_total'] ?? 0)),
            'item.service_date' => $this->e($this->formatDate((string)($item['service_date'] ?? ''))),
            'item.reference' => $this->e((string)($item['billing_reference'] ?? '')),
        ];
    }

    private function splitDescription(string $description): array
    {
        $parts = preg_split('/\R/', $description, 2);
        return [
            trim((string)($parts[0] ?? '')),
            trim((string)($parts[1] ?? '')),
        ];
    }

    private function percent(mixed $value): string
    {
        $number = (float)str_replace(',', '.', (string)($value ?? 0));
        if (abs($number) < 0.0005) {
            return '';
        }
        $formatted = $this->formatNumber($number, 3);
        $formatted = rtrim(rtrim($formatted, '0'), $this->decimalSeparator());
        return $formatted . ' %';
    }

    private function sampleInvoice(): array
    {
        return [
            'invoice_number' => '20260804-0001',
            'created_at' => date('Y-m-d H:i:s'),
            'period_start' => date('Y-m-01 00:00:00'),
            'period_end' => date('Y-m-d H:i:s'),
            'status' => 'Vorschau',
            'subtotal' => '100.00',
            'tax_total' => '0.00',
            'total' => '0.00',
            'username' => 'kunde_001',
            'email' => 'kunde@example.com',
            'server_name' => 'Server01',
            'recipient_snapshot' => json_encode([
                'company' => 'Beispiel GmbH',
                'first_name' => 'Erika',
                'last_name' => 'Mustermann',
                'address' => 'Musterstraße 12',
                'postcode' => '12345',
                'city' => 'Musterstadt',
                'country' => 'DE',
                'billing_discount_percent' => 100,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function sampleItems(): array
    {
        return [
            ['description' => 'Hostingpaket Business', 'quantity' => 1, 'unit_price' => '79.00', 'discount_percent' => '0', 'net_total' => '79.00', 'tax_total' => '15.01', 'gross_total' => '94.01', 'service_date' => date('Y-m-d'), 'billing_reference' => 'sample-hosting'],
            ['description' => "Domain beispiel.de\n(02.09.2020 - 01.10.2020)", 'quantity' => 1, 'unit_price' => '21.00', 'discount_percent' => '0', 'net_total' => '21.00', 'tax_total' => '3.99', 'gross_total' => '24.99', 'service_date' => date('Y-m-d'), 'billing_reference' => 'sample-domain'],
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function loadTcpdf(): void
    {
        if (class_exists('TCPDF')) {
            return;
        }
        $configuredPath = trim((string)($this->config['billing']['tcpdf_path'] ?? $this->config['pdf']['tcpdf_path'] ?? ''));
        $candidates = array_filter([
            $configuredPath,
            '/usr/share/php/tcpdf/autoload.php',
            '/usr/share/php/tcpdf/tcpdf.php',
            '/usr/share/php/TCPDF/tcpdf.php',
            'tcpdf/autoload.php',
            'tcpdf/tcpdf.php',
        ]);
        $tried = [];
        $errors = [];
        foreach ($candidates as $file) {
            $resolved = is_file($file) ? $file : stream_resolve_include_path($file);
            $tried[] = $file . ($resolved && $resolved !== $file ? ' => ' . $resolved : '');
            if (!$resolved || !is_readable($resolved)) {
                continue;
            }
            $loaded = @include_once $resolved;
            if ($loaded === false) {
                $errors[] = $resolved . ': include_once fehlgeschlagen';
                continue;
            }
            if (class_exists('TCPDF')) {
                return;
            }
            $errors[] = $resolved . ': TCPDF-Klasse wurde nach dem Laden nicht gefunden';
        }
        if (!class_exists('TCPDF')) {
            $message = 'TCPDF ist nicht installiert oder konnte nicht geladen werden. Geprüfte Pfade: ' . implode(', ', $tried);
            if ($errors !== []) {
                $message .= '. Ladefehler: ' . implode(' | ', $errors);
            }
            throw new RuntimeException($message);
        }
    }

    private function renderHeader(TCPDF $pdf, array $invoice): void
    {
        $logo = dirname(__DIR__) . '/public/assets/khmsm_fulllogo_512.png';
        if (is_file($logo)) {
            $pdf->Image($logo, 18, 16, 48);
        }
        $pdf->SetFont('dejavusans', 'B', 22);
        $pdf->SetXY(118, 18);
        $pdf->Cell(74, 10, 'Rechnung', 0, 2, 'R');
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(91, 99, 111);
        $pdf->Cell(74, 7, (string)$invoice['invoice_number'], 0, 2, 'R');
        $pdf->SetDrawColor(225, 229, 235);
        $pdf->Line(18, 44, 192, 44);
        $pdf->SetTextColor(33, 37, 41);
    }

    private function renderAddresses(TCPDF $pdf, array $invoice, string $sender): void
    {
        $recipient = $this->recipientLines($invoice);
        $pdf->SetY(54);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(91, 99, 111);
        $pdf->MultiCell(80, 4, $this->senderName($sender), 0, 'L');
        $pdf->SetTextColor(33, 37, 41);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(86, 5, implode("\n", $recipient), 0, 'L');

        $pdf->SetXY(120, 54);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(72, 5, 'Absender', 0, 2, 'L');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(72, 5, trim($sender) ?: $this->senderName(''), 0, 'L');
    }

    private function renderMeta(TCPDF $pdf, array $invoice): void
    {
        $createdAt = substr((string)($invoice['created_at'] ?? ''), 0, 10);
        $periodStart = substr((string)($invoice['period_start'] ?? ''), 0, 10);
        $periodEnd = substr((string)($invoice['period_end'] ?? ''), 0, 10);
        $rows = [
            'Rechnungsdatum' => $this->formatDate($createdAt),
            'Leistungszeitraum' => trim($this->formatDate($periodStart) . ' - ' . $this->formatDate($periodEnd), ' -'),
            'Kundennummer' => (string)($invoice['username'] ?? ''),
            'Status' => (string)($invoice['status'] ?? ''),
        ];

        $pdf->SetXY(120, 92);
        foreach ($rows as $label => $value) {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(91, 99, 111);
            $pdf->Cell(34, 5, $label, 0, 0, 'L');
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetTextColor(33, 37, 41);
            $pdf->Cell(38, 5, $value, 0, 1, 'R');
            $pdf->SetX(120);
        }
    }

    private function renderItems(TCPDF $pdf, array $items): void
    {
        $pdf->SetY(128);
        $this->tableHeader($pdf);
        foreach ($items as $item) {
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                $this->tableHeader($pdf);
            }
            $y = $pdf->GetY();
            $description = (string)($item['description'] ?? '');
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(33, 37, 41);
            $pdf->MultiCell(77, 7, $description, 'B', 'L', false, 0, 18, $y);
            $rowHeight = max(7, $pdf->getStringHeight(77, $description));
            $pdf->SetXY(95, $y);
            $pdf->Cell(18, $rowHeight, $this->formatQuantity($item['quantity'] ?? 1), 'B', 0, 'R');
            $pdf->Cell(25, $rowHeight, $this->money($item['unit_price'] ?? 0), 'B', 0, 'R');
            $pdf->Cell(24, $rowHeight, $this->money($item['net_total'] ?? 0), 'B', 0, 'R');
            $pdf->Cell(30, $rowHeight, $this->money($item['gross_total'] ?? 0), 'B', 1, 'R');
        }
    }

    private function tableHeader(TCPDF $pdf): void
    {
        $pdf->SetFillColor(246, 248, 251);
        $pdf->SetDrawColor(225, 229, 235);
        $pdf->SetTextColor(91, 99, 111);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(77, 8, 'Beschreibung', 'B', 0, 'L', true);
        $pdf->Cell(18, 8, 'Menge', 'B', 0, 'R', true);
        $pdf->Cell(25, 8, 'Einzel', 'B', 0, 'R', true);
        $pdf->Cell(24, 8, 'Netto', 'B', 0, 'R', true);
        $pdf->Cell(30, 8, 'Brutto', 'B', 1, 'R', true);
    }

    private function renderTotals(TCPDF $pdf, array $invoice): void
    {
        $pdf->Ln(8);
        $x = 122;
        $rows = [
            'Netto' => $invoice['subtotal'] ?? '0.00',
            'Steuer' => $invoice['tax_total'] ?? '0.00',
            'Gesamt' => $invoice['total'] ?? '0.00',
        ];
        foreach ($rows as $label => $value) {
            $isTotal = $label === 'Gesamt';
            $pdf->SetX($x);
            $pdf->SetFont('dejavusans', $isTotal ? 'B' : '', $isTotal ? 11 : 9);
            $pdf->Cell(34, 7, $label, 0, 0, 'L');
            $pdf->Cell(36, 7, $this->money($value), 0, 1, 'R');
            if ($isTotal) {
                $pdf->Line($x, $pdf->GetY(), 192, $pdf->GetY());
            }
        }
    }

    private function recipientLines(array $invoice): array
    {
        $snapshot = json_decode((string)($invoice['recipient_snapshot'] ?? ''), true) ?: [];
        $lines = array_filter([
            $snapshot['company'] ?? null,
            trim((string)($snapshot['first_name'] ?? '') . ' ' . (string)($snapshot['last_name'] ?? '')),
            $snapshot['address'] ?? null,
            trim((string)($snapshot['postcode'] ?? '') . ' ' . (string)($snapshot['city'] ?? '')),
            $snapshot['country'] ?? null,
        ]);
        if ($lines === []) {
            $lines = [
                (string)($invoice['username'] ?? ''),
                (string)($invoice['email'] ?? ''),
            ];
        }
        return array_values($lines);
    }

    private function senderName(string $sender): string
    {
        if (preg_match('/^(.+?)\s*<.+>$/', trim($sender), $match)) {
            return trim($match[1], " \t\n\r\0\x0B\"");
        }
        return trim($sender) ?: (string)($this->config['app']['name'] ?? 'KeyHelp MSM');
    }

    private function formatDate(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            $value = new DateTimeImmutable($date);
            return match ((string)($this->config['formats']['date_format'] ?? 'auto')) {
                'mdy' => $value->format('m/d/Y'),
                'ymd' => $value->format('Y-m-d'),
                default => $value->format('d.m.Y'),
            };
        } catch (Throwable) {
            return $date;
        }
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim($this->formatNumber((float)$quantity, 2), '0'), $this->decimalSeparator());
    }

    private function money(mixed $value): string
    {
        $currency = (string)($this->config['formats']['currency'] ?? 'EUR');
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }
        return $this->formatNumber((float)$value, 2) . ' ' . $currency;
    }

    private function formatNumber(float $value, int $decimals): string
    {
        return number_format($value, $decimals, $this->decimalSeparator(), $this->thousandsSeparator());
    }

    private function decimalSeparator(): string
    {
        return (string)($this->config['formats']['decimal_separator'] ?? 'auto') === 'dot' ? '.' : ',';
    }

    private function thousandsSeparator(): string
    {
        return $this->decimalSeparator() === ',' ? '.' : ',';
    }
}
