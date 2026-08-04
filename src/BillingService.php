<?php
final class BillingService
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private array $config, private Repository $repo) {}

    public static function moneyToCents(string|int|float|null $value): int
    {
        $value = trim(str_replace(',', '.', (string)($value ?? '0')));
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value)) {
            throw new RuntimeException('Ungueltiger Geldbetrag: ' . $value);
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$euros, $cents] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = substr(str_pad($cents, 3, '0'), 0, 3);
        $amount = ((int)$euros * 100) + (int)substr($cents, 0, 2);
        if ((int)$cents[2] >= 5) {
            $amount++;
        }
        return $negative ? -$amount : $amount;
    }

    public static function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function applyDiscounts(int $baseCents, float ...$discounts): array
    {
        $current = max(0, $baseCents);
        $effectiveFactor = 1.0;
        foreach ($discounts as $discount) {
            $discount = min(100.0, max(0.0, $discount));
            $factor = (100.0 - $discount) / 100.0;
            $current = (int)round($current * $factor, 0, PHP_ROUND_HALF_UP);
            $effectiveFactor *= $factor;
        }
        $effectiveDiscount = $baseCents <= 0 ? 0.0 : (100.0 - ($effectiveFactor * 100.0));
        return [$current, round(max(0.0, min(100.0, $effectiveDiscount)), 3)];
    }

    public static function taxForNet(int $netCents, float $taxPercent): int
    {
        return (int)round($netCents * max(0.0, $taxPercent) / 100.0, 0, PHP_ROUND_HALF_UP);
    }

    public function run(string $actor = 'system'): string
    {
        if (!$this->repo->acquireBillingLock()) {
            throw new RuntimeException(t('billing.locked'));
        }
        try {
            $now = new DateTimeImmutable('now');
            $lastRun = new DateTimeImmutable($this->repo->billingLastRunAt());
            $runId = $this->repo->createBillingRun($lastRun, $now);
            $createdInvoices = 0;
            $queuedItems = 0;
            $this->repo->transaction(function () use ($lastRun, $now, $actor, &$createdInvoices, &$queuedItems): void {
                $queuedItems += $this->queueDueDomainItems($lastRun, $now);
                $queuedItems += $this->queueDueUserItems($now);
                $createdInvoices = $this->createDueInvoices($lastRun, $now, $actor);
                $this->repo->saveBillingLastRunAt($now);
            });
            $message = t('billing.run_result', ['invoices' => $createdInvoices, 'items' => $queuedItems]);
            $this->repo->finishBillingRun($runId, 'done', $message);
            $this->repo->audit($actor, 'billing_run_finished', 'billing_run', $runId, ['invoices' => $createdInvoices, 'items' => $queuedItems]);
            if ($createdInvoices > 0) {
                $this->notifyAdmins($createdInvoices);
            }
            return $message;
        } catch (Throwable $e) {
            if (isset($runId)) {
                $this->repo->finishBillingRun($runId, 'failed', $e->getMessage());
            }
            $this->repo->audit($actor, 'billing_run_failed', 'billing_run', $runId ?? null, ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            $this->repo->releaseBillingLock();
        }
    }

    public function approveAndSend(int $invoiceId, string $actor = 'admin'): string
    {
        $this->repo->setInvoiceStatus($invoiceId, 'approved');
        $this->repo->audit($actor, 'invoice_approved', 'invoice', $invoiceId);
        return $this->sendInvoice($invoiceId, $actor);
    }

    public function queueInvoice(int $invoiceId, string $actor = 'admin'): string
    {
        $this->repo->setInvoiceStatus($invoiceId, 'queued');
        $this->repo->audit($actor, 'invoice_queued', 'invoice', $invoiceId);
        return t('billing.invoice_queued');
    }

    public function cancelInvoice(int $invoiceId, string $actor = 'admin'): string
    {
        $this->repo->setInvoiceStatus($invoiceId, 'cancelled');
        $this->requeueInvoiceItems($invoiceId, $actor);
        $this->repo->audit($actor, 'invoice_cancelled', 'invoice', $invoiceId);
        return t('billing.invoice_cancelled');
    }

    public function deleteInvoice(int $invoiceId, string $actor = 'admin'): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        if (!in_array((string)$invoice['status'], ['draft', 'pending_approval', 'failed', 'cancelled'], true) || !empty($invoice['immutable_at'])) {
            throw new RuntimeException(t('billing.invoice_delete_not_allowed'));
        }
        $items = $this->repo->invoiceItems($invoiceId);
        foreach ($items as $item) {
            $this->restoreDomainBillingDateFromInvoiceItem($item);
        }
        $pdfPath = trim((string)($invoice['pdf_path'] ?? ''));
        $this->repo->deleteInvoice($invoiceId);
        if ($pdfPath !== '' && is_file($pdfPath)) {
            @unlink($pdfPath);
        }
        $this->repo->audit($actor, 'invoice_deleted', 'invoice', $invoiceId, [
            'invoice_number' => $invoice['invoice_number'] ?? '',
            'items' => count($items),
        ]);
        return t('billing.invoice_deleted');
    }

    public function requeueCancelledInvoice(int $invoiceId, string $actor = 'admin'): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        if (($invoice['status'] ?? '') !== 'cancelled') {
            throw new RuntimeException(t('billing.invoice_requeue_only_cancelled'));
        }
        $count = $this->requeueInvoiceItems($invoiceId, $actor);
        return t('billing.invoice_requeued', ['count' => $count]);
    }

    public function sendQueued(string $actor = 'admin'): string
    {
        $sent = 0;
        foreach ($this->repo->queuedInvoices() as $invoice) {
            try {
                $this->sendInvoice((int)$invoice['id'], $actor);
                $sent++;
            } catch (Throwable) {
                continue;
            }
        }
        return t('billing.queue_sent', ['count' => $sent]);
    }

    public function sendInvoice(int $invoiceId, string $actor = 'admin'): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        if (($invoice['status'] ?? '') === 'sent') {
            return t('billing.invoice_already_sent');
        }
        $pdfPath = $this->writeInvoicePdf($invoiceId);
        $ok = $this->mailInvoice($invoice, $pdfPath);
        if (!$ok) {
            $this->repo->markInvoiceFailed($invoiceId, t('billing.mail_failed'));
            $this->repo->audit($actor, 'invoice_send_failed', 'invoice', $invoiceId);
            throw new RuntimeException(t('billing.mail_failed'));
        }
        $this->repo->markInvoiceSent($invoiceId, $pdfPath);
        $this->repo->audit($actor, 'invoice_sent', 'invoice', $invoiceId);
        return t('billing.invoice_sent');
    }

    public function backbillDomains(array $data, string $actor = 'admin'): string
    {
        $userId = (int)($data['user_id'] ?? 0);
        $user = $this->repo->user($userId);
        if (!$user) {
            throw new InvalidArgumentException(t('billing.user_required'));
        }
        $from = $this->dateOnly($data['period_from'] ?? null);
        $to = $this->dateOnly($data['period_to'] ?? null);
        $useDomainSchedule = !$from && !$to;
        if (!$useDomainSchedule && (!$from || !$to || $from > $to)) {
            throw new InvalidArgumentException(t('billing.period_invalid'));
        }
        $domainIds = array_values(array_filter(array_map('intval', (array)($data['domain_ids'] ?? []))));
        if ($domainIds === []) {
            throw new InvalidArgumentException(t('billing.domains_required'));
        }
        $manualPrice = trim((string)($data['manual_price'] ?? ''));
        $useManualPrice = ($data['price_source'] ?? 'tld') === 'manual';
        if ($useManualPrice && $manualPrice === '') {
            throw new InvalidArgumentException(t('billing.price_required'));
        }

        $domains = [];
        foreach ($this->repo->domains() as $domain) {
            if (in_array((int)$domain['id'], $domainIds, true)) {
                $domains[(int)$domain['id']] = $domain;
            }
        }
        $tldPrices = $this->repo->billingTldPricesByTld();
        $overrides = $this->repo->billingDomainOverridesByDomainId();
        $settings = $this->repo->billingUserSettingsByUserId();
        $userDiscount = (float)($settings[$userId]['discount_percent'] ?? 0);
        $created = 0;

        foreach ($domains as $domain) {
            $tld = $this->tld((string)$domain['domain']);
            $price = $tldPrices[$tld] ?? null;
            if (!$useManualPrice && (!$price || !(int)($price['active'] ?? 0))) {
                continue;
            }
            $override = $overrides[(int)$domain['id']] ?? [];
            $tax = $this->taxRate($override['tax_rate_id'] ?? ($price['tax_rate_id'] ?? null));
            $frequency = $this->billingFrequency((string)($domain['billing_frequency'] ?? 'yearly'));
            $current = $useDomainSchedule ? $this->dateOnly($domain['next_billing_at'] ?? null) : $from;
            $stop = $useDomainSchedule ? new DateTimeImmutable('today') : $to;
            while ($current && ($useDomainSchedule ? $current < $stop : $current <= $stop)) {
                $hasOverridePrice = !$useManualPrice && ($override['yearly_price'] ?? '') !== '' && ($override['yearly_price'] ?? null) !== null;
                $base = match (true) {
                    $useManualPrice => self::moneyToCents($manualPrice),
                    $hasOverridePrice => self::moneyToCents($override['yearly_price']),
                    default => self::moneyToCents($price['yearly_price'] ?? '0'),
                };
                $base = $this->domainPeriodPrice($base, $frequency);
                $domainDiscount = $useManualPrice || $hasOverridePrice ? 0.0 : (float)($override['discount_percent'] ?? 0);
                [$net, $discount] = self::applyDiscounts($base, $domainDiscount);
                $taxCents = self::taxForNet($net, (float)$tax['rate_percent']);
                $period = $this->billingPeriod($current, $frequency);
                $added = $this->repo->addPendingBillingItem([
                    'user_id' => $userId,
                    'source_type' => 'domain_backbill',
                    'source_id' => (int)$domain['id'],
                    'description' => t('billing.item_domain_backbill') . ': ' . $domain['domain'] . "\n" . '(' . $period['label'] . ')',
                    'unit_price' => self::centsToMoney($base),
                    'discount_percent' => $discount,
                    'tax_rate_id' => $tax['id'] ?? null,
                    'tax_rate_percent' => (float)$tax['rate_percent'],
                    'net_total' => self::centsToMoney($net),
                    'tax_total' => self::centsToMoney($taxCents),
                    'gross_total' => self::centsToMoney($net + $taxCents),
                    'service_date' => $current->format('Y-m-d'),
                    'billing_reference' => 'domain_backbill:' . (int)$domain['id'] . ':' . $current->format('Y-m-d'),
                ]);
                $created += $added;
                $current = $this->nextItemDate($current, $frequency);
            }
        }

        if ($created > 0) {
            $this->repo->markUserInvoiceDue($userId);
            $invoice = $this->createInvoiceFromPendingItems($user, new DateTimeImmutable('today'), new DateTimeImmutable('now'), $actor, $useDomainSchedule);
        } else {
            $invoice = null;
        }
        $this->repo->audit($actor, 'domain_backbilling_created', 'billing_pending_item', null, ['items' => $created, 'user_id' => $userId]);
        if ($invoice) {
            return t('billing.backbill_invoice_result', [
                'count' => $created,
                'invoice' => $invoice['number'],
            ]);
        }
        return t('billing.backbill_result', ['count' => $created]);
    }

    public function invoicePdfPath(int $invoiceId): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        $path = trim((string)($invoice['pdf_path'] ?? ''));
        if ($path === '' || !is_file($path) || ($invoice['status'] ?? '') !== 'sent') {
            $path = $this->writeInvoicePdf($invoiceId);
            $this->repo->setInvoicePdf($invoiceId, $path);
        }
        return $path;
    }

    private function queueDueDomainItems(DateTimeImmutable $lastRun, DateTimeImmutable $now): int
    {
        $count = 0;
        $users = $this->repo->usersFlatByServerExternalId();
        $tldPrices = $this->repo->billingTldPricesByTld();
        $overrides = $this->repo->billingDomainOverridesByDomainId();
        $userSettings = $this->repo->billingUserSettingsByUserId();
        foreach ($this->repo->domains() as $domain) {
            $userKey = (int)$domain['server_id'] . ':' . (string)($domain['owner_external_id'] ?? '');
            if (!isset($users[$userKey])) {
                continue;
            }
            $tld = $this->tld((string)$domain['domain']);
            if ($tld === '' || empty($tldPrices[$tld]) || !(int)$tldPrices[$tld]['active']) {
                continue;
            }
            $user = $users[$userKey];
            $userDiscount = (float)($userSettings[(int)$user['id']]['discount_percent'] ?? 0);
            $override = $overrides[(int)$domain['id']] ?? [];
            $tax = $this->taxRate($override['tax_rate_id'] ?? $tldPrices[$tld]['tax_rate_id'] ?? null);
            $frequency = $this->billingFrequency((string)($domain['billing_frequency'] ?? 'yearly'));
            if ($this->dateInWindow($domain['registered_at'] ?? null, $lastRun, $now)) {
                $count += $this->queueDomainItem($user, $domain, 'domain_registration', $domain['registered_at'], $tldPrices[$tld], $override, $userDiscount, $tax);
            }
            $next = $this->dateOnly($domain['next_billing_at'] ?? null);
            while ($next && $next < $now) {
                $count += $this->queueDomainItem($user, $domain, 'domain_yearly', $next->format('Y-m-d'), $tldPrices[$tld], $override, $userDiscount, $tax, $frequency);
                $next = $this->nextItemDate($next, $frequency);
            }
            if (!empty($domain['last_change_at']) && $this->dateInWindow($domain['last_change_at'], $lastRun, $now)) {
                $count += $this->queueDomainItem($user, $domain, 'domain_change', $domain['last_change_at'], $tldPrices[$tld], $override, $userDiscount, $tax);
            }
        }
        return $count;
    }

    private function queueDomainItem(array $user, array $domain, string $type, ?string $serviceDate, array $tldPrice, array $override, float $userDiscount, array $tax, string $frequency = 'yearly'): int
    {
        $field = match ($type) {
            'domain_registration' => 'registration_price',
            'domain_change' => 'change_price',
            default => 'yearly_price',
        };
        $base = self::moneyToCents($tldPrice[$field] ?? '0');
        $domainDiscount = 0.0;
        if ($type === 'domain_yearly') {
            if (($override['yearly_price'] ?? '') !== '' && $override['yearly_price'] !== null) {
                $base = self::moneyToCents($override['yearly_price']);
            } else {
                $domainDiscount = (float)($override['discount_percent'] ?? 0);
            }
            $base = $this->domainPeriodPrice($base, $frequency);
        } elseif (($override[$field] ?? '') !== '' && $override[$field] !== null) {
            $base = self::moneyToCents($override[$field]);
        }
        [$net, $discount] = self::applyDiscounts($base, $domainDiscount);
        $taxCents = self::taxForNet($net, (float)$tax['rate_percent']);
        $label = match ($type) {
            'domain_registration' => t('billing.item_domain_registration'),
            'domain_change' => t('billing.item_domain_change'),
            default => t('billing.item_domain_periodic'),
        };
        $description = $label . ': ' . $domain['domain'];
        if ($type === 'domain_yearly' && $serviceDate) {
            $description .= "\n" . '(' . $this->billingPeriod(new DateTimeImmutable($serviceDate), $frequency)['label'] . ')';
        }
        return $this->repo->addPendingBillingItem([
            'user_id' => (int)$user['id'],
            'source_type' => $type,
            'source_id' => (int)$domain['id'],
            'description' => $description,
            'unit_price' => self::centsToMoney($base),
            'discount_percent' => $discount,
            'tax_rate_id' => $tax['id'] ?? null,
            'tax_rate_percent' => (float)$tax['rate_percent'],
            'net_total' => self::centsToMoney($net),
            'tax_total' => self::centsToMoney($taxCents),
            'gross_total' => self::centsToMoney($net + $taxCents),
            'service_date' => $serviceDate,
            'billing_reference' => $type . ':' . (int)$domain['id'] . ':' . ($serviceDate ?: 'none'),
        ]);
    }

    private function billingPeriod(DateTimeImmutable $start, string $frequency): array
    {
        $end = $this->nextItemDate($start, $this->billingFrequency($frequency))->modify('-1 day');
        return [
            'start' => $start,
            'end' => $end,
            'label' => $start->format('d.m.Y') . ' - ' . $end->format('d.m.Y'),
        ];
    }

    private function domainPeriodPrice(int $yearlyCents, string $frequency): int
    {
        $divisor = match ($this->billingFrequency($frequency)) {
            'monthly' => 12,
            'bimonthly' => 6,
            'quarterly' => 4,
            'halfyearly' => 2,
            default => 1,
        };
        return (int)round($yearlyCents / $divisor, 0, PHP_ROUND_HALF_UP);
    }

    private function queueDueUserItems(DateTimeImmutable $now): int
    {
        $count = 0;
        $settings = $this->repo->billingUserSettingsByUserId();
        foreach ($this->repo->billingUserItems(true) as $item) {
            $dueDates = $this->dueItemDates($item, $now);
            foreach ($dueDates as $dueDate) {
                $userDiscount = (float)($settings[(int)$item['user_id']]['discount_percent'] ?? 0);
                $tax = $this->taxRate($item['tax_rate_id'] ?? null);
                $base = self::moneyToCents($item['amount']);
                [$net, $discount] = self::applyDiscounts($base);
                $taxCents = self::taxForNet($net, (float)$tax['rate_percent']);
                $added = $this->repo->addPendingBillingItem([
                    'user_id' => (int)$item['user_id'],
                    'source_type' => 'user_item',
                    'source_id' => (int)$item['id'],
                    'description' => (string)$item['description'],
                    'unit_price' => self::centsToMoney($base),
                    'discount_percent' => $discount,
                    'tax_rate_id' => $tax['id'] ?? null,
                    'tax_rate_percent' => (float)$tax['rate_percent'],
                    'net_total' => self::centsToMoney($net),
                    'tax_total' => self::centsToMoney($taxCents),
                    'gross_total' => self::centsToMoney($net + $taxCents),
                    'service_date' => $dueDate->format('Y-m-d'),
                    'billing_reference' => 'user_item:' . (int)$item['id'] . ':' . $dueDate->format('Y-m-d'),
                ]);
                if ($added) {
                    $count++;
                }
                $this->repo->updateUserItemBilling((int)$item['id'], $dueDate->format('Y-m-d'), $this->nextItemDate($dueDate, (string)$item['frequency'])->format('Y-m-d'));
                if ($item['frequency'] === 'once') {
                    $this->repo->deactivateUserItem((int)$item['id']);
                    break;
                }
            }
        }
        return $count;
    }

    private function dueItemDates(array $item, DateTimeImmutable $now): array
    {
        $frequency = (string)$item['frequency'];
        $next = $this->dateOnly($item['next_billing_at'] ?: $item['last_billed_at'] ?: $item['booking_date']);
        if (!$next || $next > $now) {
            return [];
        }
        if ($frequency === 'once') {
            return empty($item['last_billed_at']) ? [$next] : [];
        }
        $dates = [];
        $guard = 0;
        while ($next <= $now && $guard < 60) {
            $dates[] = $next;
            $next = $this->nextItemDate($next, $frequency);
            $guard++;
        }
        return $dates;
    }

    private function createDueInvoices(DateTimeImmutable $lastRun, DateTimeImmutable $now, string $actor): int
    {
        $created = 0;
        foreach ($this->repo->billingUsersWithPendingItems() as $user) {
            $settings = $this->repo->billingUserSetting((int)$user['id']);
            $invoice = $this->createInvoiceFromPendingItems($user, $lastRun, $now, $actor, true);
            if (!$invoice) {
                continue;
            }
            $this->repo->updateUserInvoiceSchedule((int)$user['id'], $settings['invoice_frequency'] ?? 'monthly', $now);
            $created++;
        }
        return $created;
    }

    private function requeueInvoiceItems(int $invoiceId, string $actor): int
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        $count = 0;
        foreach ($this->repo->invoiceItems($invoiceId) as $item) {
            $count += $this->repo->addPendingBillingItem([
                'user_id' => (int)$invoice['user_id'],
                'source_type' => (string)$item['source_type'],
                'source_id' => (int)$item['source_id'],
                'description' => (string)$item['description'],
                'unit_price' => (string)$item['unit_price'],
                'discount_percent' => (string)$item['discount_percent'],
                'tax_rate_id' => $item['tax_rate_id'] ?? null,
                'tax_rate_percent' => (string)$item['tax_rate_percent'],
                'net_total' => (string)$item['net_total'],
                'tax_total' => (string)$item['tax_total'],
                'gross_total' => (string)$item['gross_total'],
                'service_date' => $item['service_date'] ?? null,
                'billing_reference' => (string)$item['billing_reference'],
            ]);
            $this->restoreDomainBillingDateFromInvoiceItem($item);
        }
        $this->repo->audit($actor, 'invoice_items_requeued', 'invoice', $invoiceId, ['items' => $count]);
        return $count;
    }

    private function restoreDomainBillingDateFromInvoiceItem(array $item): void
    {
        if (!in_array((string)($item['source_type'] ?? ''), ['domain_yearly', 'domain_backbill'], true)) {
            return;
        }
        $domainId = (int)($item['source_id'] ?? 0);
        $serviceDate = $this->dateOnly($item['service_date'] ?? null);
        if ($domainId <= 0 || !$serviceDate) {
            return;
        }
        $domain = $this->repo->domain($domainId);
        if (!$domain) {
            return;
        }
        $current = $this->dateOnly($domain['next_billing_at'] ?? null);
        if (!$current || $serviceDate < $current) {
            $this->repo->updateDomainNextBilling($domainId, $serviceDate->format('Y-m-d'));
        }
    }

    private function createInvoiceFromPendingItems(array $user, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, string $actor, bool $advanceDomainBilling): ?array
    {
        $items = $this->repo->pendingBillingItemsForUser((int)$user['id']);
        if ($items === []) {
            return null;
        }
        $referenceStatuses = $this->repo->invoiceItemReferenceStatuses(array_column($items, 'billing_reference'));
        $blockedItems = [];
        if ($referenceStatuses !== []) {
            $billableItems = [];
            foreach ($items as $item) {
                $reference = (string)$item['billing_reference'];
                if (!isset($referenceStatuses[$reference])) {
                    $billableItems[] = $item;
                    continue;
                }
                if (($referenceStatuses[$reference]['status'] ?? '') === 'cancelled') {
                    $billableItems[] = $item;
                    continue;
                }
                $blockedItems[] = [
                    'reference' => $reference,
                    'invoice_number' => $referenceStatuses[$reference]['invoice_number'] ?? '',
                    'status' => $referenceStatuses[$reference]['status'] ?? '',
                ];
            }
            $items = $billableItems;
            if ($blockedItems !== []) {
                $this->repo->audit($actor, 'pending_items_already_invoiced', 'billing_pending_item', null, [
                    'user_id' => (int)$user['id'],
                    'items' => $blockedItems,
                ]);
            }
            if ($items === []) {
                return null;
            }
        }
        $firstServiceDate = $this->firstServiceDate($items);
        if ($firstServiceDate) {
            $periodStart = $firstServiceDate;
        }
        $number = $this->nextInvoiceNumber($user, $periodEnd);
        foreach ($items as &$item) {
            $reference = (string)$item['billing_reference'];
            if (($referenceStatuses[$reference]['status'] ?? '') === 'cancelled') {
                $item['billing_reference'] = $this->rebillReference($reference, $number);
            }
        }
        unset($item);
        $invoiceId = $this->repo->createInvoice(
            (int)$user['id'],
            $number,
            $items,
            $periodStart,
            $periodEnd,
            $this->invoiceRecipientSnapshot($user),
            $this->repo->billingSetting('invoice_sender', '')
        );
        $pdf = $this->writeInvoicePdf($invoiceId);
        $this->repo->setInvoicePdf($invoiceId, $pdf);
        if ($advanceDomainBilling) {
            $this->advanceDomainBillingFromItems($items);
        }
        $this->repo->deletePendingBillingItems(array_map(static fn(array $item): int => (int)$item['id'], $items));
        $this->repo->audit($actor, 'invoice_created', 'invoice', $invoiceId, ['invoice_number' => $number]);
        return [
            'id' => $invoiceId,
            'number' => $number,
        ];
    }

    private function advanceDomainBillingFromItems(array $items): void
    {
        $nextByDomain = [];
        foreach ($items as $item) {
            if (!in_array((string)($item['source_type'] ?? ''), ['domain_yearly', 'domain_backbill'], true)) {
                continue;
            }
            $domainId = (int)($item['source_id'] ?? 0);
            $serviceDate = $this->dateOnly($item['service_date'] ?? null);
            if ($domainId <= 0 || !$serviceDate) {
                continue;
            }
            $domain = $this->repo->domain($domainId);
            if (!$domain) {
                continue;
            }
            $frequency = $this->billingFrequency((string)($domain['billing_frequency'] ?? 'yearly'));
            $next = $this->nextItemDate($serviceDate, $frequency);
            if (!isset($nextByDomain[$domainId]) || $next > $nextByDomain[$domainId]) {
                $nextByDomain[$domainId] = $next;
            }
        }
        foreach ($nextByDomain as $domainId => $nextDate) {
            $this->repo->updateDomainNextBilling((int)$domainId, $nextDate->format('Y-m-d'));
        }
    }

    private function rebillReference(string $reference, string $invoiceNumber): string
    {
        $suffix = ':rebill:' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $invoiceNumber);
        return substr($reference, 0, 190 - strlen($suffix)) . $suffix;
    }

    private function firstServiceDate(array $items): ?DateTimeImmutable
    {
        $first = null;
        foreach ($items as $item) {
            $date = $this->dateOnly($item['service_date'] ?? null);
            if ($date && (!$first || $date < $first)) {
                $first = $date;
            }
        }
        return $first;
    }

    private function nextInvoiceNumber(array $user, DateTimeImmutable $now): string
    {
        $format = $this->repo->billingSetting('invoice_number_format', '{{JAHR}}{{MONAT}}{{TAG}}-{{LFNR}}');
        $sequence = $this->repo->nextInvoiceSequence($now->format('Y-m-d'));
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $number = $this->formatInvoiceNumber($format, $sequence + $attempt, $user, $now);
            if (!$this->repo->invoiceNumberExists($number)) {
                return $number;
            }
        }
        throw new RuntimeException(t('billing.invoice_number_failed'));
    }

    private function formatInvoiceNumber(string $format, int $sequence, array $user, DateTimeImmutable $now): string
    {
        $username = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)$user['username']);
        return preg_replace_callback('/\{\{([A-Z_]+)(?::(\d+))?\}\}/', static function (array $match) use ($now, $sequence, $user, $username): string {
            $value = match ($match[1]) {
                'JAHR' => $now->format('Y'),
                'MONAT' => $now->format('m'),
                'TAG' => $now->format('d'),
                'LFNR' => (string)$sequence,
                'USERID' => (string)$user['id'],
                'USERNAME' => $username,
                default => '',
            };
            if (isset($match[2]) && in_array($match[1], ['LFNR', 'USERID'], true)) {
                return str_pad($value, max(1, (int)$match[2]), '0', STR_PAD_LEFT);
            }
            if ($match[1] === 'LFNR') {
                return str_pad($value, 4, '0', STR_PAD_LEFT);
            }
            return $value;
        }, $format) ?? $format;
    }

    private function writeInvoicePdf(int $invoiceId): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        if (($invoice['status'] ?? '') !== 'sent') {
            $user = $this->repo->user((int)($invoice['user_id'] ?? 0));
            if ($user) {
                $snapshot = $this->invoiceRecipientSnapshot($user);
                $currentSnapshot = json_decode((string)($invoice['recipient_snapshot'] ?? ''), true) ?: [];
                if (array_key_exists('billing_discount_percent', $currentSnapshot)) {
                    $snapshot['billing_discount_percent'] = (float)$currentSnapshot['billing_discount_percent'];
                }
                $this->repo->updateInvoiceRecipientSnapshot($invoiceId, $snapshot);
                $invoice = $this->repo->invoice($invoiceId) ?: $invoice;
            }
        }
        $items = $this->repo->invoiceItems($invoiceId);
        $dir = dirname(__DIR__) . '/storage/invoices';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$invoice['invoice_number']) . '.pdf';
        (new InvoicePdfRenderer($this->config))->write(
            $invoice,
            $items,
            $path,
            $this->repo->billingSetting('invoice_sender', ''),
            $this->repo->billingSetting('invoice_template_html', InvoicePdfRenderer::defaultTemplate()),
            $this->repo->billingSetting('payment_account_details', '')
        );
        return $path;
    }

    private function mailInvoice(array $invoice, string $pdfPath): bool
    {
        $email = trim((string)($invoice['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_file($pdfPath)) {
            return false;
        }
        $from = $this->repo->billingSetting('invoice_sender', '');
        $boundary = 'khmsm-' . bin2hex(random_bytes(12));
        $headers = [];
        if ($from !== '') {
            $headers[] = 'From: ' . $from;
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= "Ihre Rechnung befindet sich im Anhang.\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: application/pdf; name="' . basename($pdfPath) . '"' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . basename($pdfPath) . '"' . "\r\n\r\n";
        $body .= chunk_split(base64_encode((string)file_get_contents($pdfPath))) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";
        return mail($email, 'Rechnung ' . $invoice['invoice_number'], $body, implode("\r\n", $headers));
    }

    private function notifyAdmins(int $createdInvoices): void
    {
        $recipients = $this->notificationRecipients();
        if ($recipients === []) {
            return;
        }
        $message = t('billing.notification_body', ['count' => $createdInvoices]);
        foreach ($recipients as $recipient) {
            @mail($recipient, t('billing.notification_subject'), $message);
        }
    }

    private function notificationRecipients(): array
    {
        $raw = $this->repo->billingSetting('invoice_sender', '') . ',' . $this->repo->billingSetting('invoice_notification_recipients', '');
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $matches);
        $emails = [];
        foreach ($matches[0] as $email) {
            $email = strtolower($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }
        }
        return array_values($emails);
    }

    private function invoiceRecipientSnapshot(array $user): array
    {
        $raw = [];
        if (isset($user['raw_json']) && is_string($user['raw_json']) && trim($user['raw_json']) !== '') {
            $raw = json_decode($user['raw_json'], true) ?: [];
        }
        $contact = $raw['contact_data'] ?? $user['contact_data'] ?? [];
        if (!is_array($contact)) {
            $contact = [];
        }
        $settings = $this->repo->billingUserSetting((int)$user['id']);
        return [
            'id' => (int)$user['id'],
            'server_id' => (int)$user['server_id'],
            'server_name' => $user['server_name'] ?? '',
            'username' => $user['username'] ?? '',
            'email' => $contact['email'] ?? $user['email'] ?? '',
            'company' => $contact['company'] ?? '',
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'address' => $contact['address'] ?? '',
            'postcode' => $contact['zip'] ?? $contact['postcode'] ?? '',
            'city' => $contact['city'] ?? '',
            'region' => $contact['state'] ?? $contact['region'] ?? '',
            'country' => $contact['country'] ?? '',
            'customer_number' => $contact['client_id'] ?? '',
            'billing_discount_percent' => (float)($settings['discount_percent'] ?? 0),
        ];
    }

    private function taxRate(int|string|null $id): array
    {
        $rates = $this->repo->billingTaxRatesById();
        if ($id !== null && $id !== '' && isset($rates[(int)$id])) {
            return $rates[(int)$id];
        }
        foreach ($rates as $rate) {
            if ((int)($rate['is_default'] ?? 0) === 1) {
                return $rate;
            }
        }
        return ['id' => null, 'rate_percent' => 0];
    }

    private function tld(string $domain): string
    {
        $parts = explode('.', strtolower(trim($domain, '.')));
        return count($parts) > 1 ? end($parts) : '';
    }

    private function dateInWindow(mixed $date, DateTimeImmutable $lastRun, DateTimeImmutable $now): bool
    {
        $value = $this->dateOnly($date);
        if (!$value) {
            return false;
        }
        return $value >= $lastRun && $value < $now;
    }

    private function dateOnly(mixed $date): ?DateTimeImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }
        return (new DateTimeImmutable((string)$date))->setTime(0, 0);
    }

    private function nextItemDate(DateTimeImmutable $date, string $frequency): DateTimeImmutable
    {
        return match ($frequency) {
            'bimonthly' => $date->modify('+2 months'),
            'quarterly' => $date->modify('+3 months'),
            'halfyearly' => $date->modify('+6 months'),
            'yearly' => $date->modify('+1 year'),
            default => $date->modify('+1 month'),
        };
    }

    private function billingFrequency(string $frequency): string
    {
        return in_array($frequency, Repository::billingFrequencyOptions(), true) ? $frequency : 'yearly';
    }
}
