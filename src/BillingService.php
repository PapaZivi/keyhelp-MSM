<?php
final class BillingService
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private int $alreadyInvoicedItems = 0;

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
        $current = $baseCents;
        $effectiveFactor = 1.0;
        foreach ($discounts as $discount) {
            if ($current <= 0) {
                break;
            }
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

    public function run(string $actor = 'system', bool $respectInvoiceSchedule = false): string
    {
        if (!$this->repo->acquireBillingLock()) {
            throw new RuntimeException(t('billing.locked'));
        }
        try {
            $this->resetRunCounters();
            $now = new DateTimeImmutable('now');
            $lastRun = new DateTimeImmutable($this->repo->billingLastRunAt());
            $runId = $this->repo->createBillingRun($lastRun, $now);
            $createdInvoices = 0;
            $queuedItems = 0;
            $this->repo->transaction(function () use ($lastRun, $now, $actor, $respectInvoiceSchedule, &$createdInvoices, &$queuedItems): void {
                $queuedItems += $this->queueDueDomainItems($lastRun, $now);
                $queuedItems += $this->queueDueUserItems($now);
                $createdInvoices = $this->createDueInvoices($lastRun, $now, $actor, $respectInvoiceSchedule);
                $this->repo->saveBillingLastRunAt($now);
            });
            $message = $this->appendRunNotes(t('billing.run_result', ['invoices' => $createdInvoices, 'items' => $queuedItems]));
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

    public function runForUser(int $userId, string $actor = 'system', bool $respectInvoiceSchedule = false): string
    {
        $user = $this->repo->localUser($userId);
        if (!$user) {
            throw new InvalidArgumentException(t('billing.user_required'));
        }
        if (!$this->repo->acquireBillingLock()) {
            throw new RuntimeException(t('billing.locked'));
        }
        try {
            $this->resetRunCounters();
            $now = new DateTimeImmutable('now');
            $lastRun = new DateTimeImmutable($this->repo->billingLastRunAt());
            $createdInvoices = 0;
            $queuedItems = 0;
            $this->repo->transaction(function () use ($user, $userId, $lastRun, $now, $actor, $respectInvoiceSchedule, &$createdInvoices, &$queuedItems): void {
                $queuedItems += $this->queueDueDomainItems($lastRun, $now, $userId);
                $queuedItems += $this->queueDueUserItems($now, $userId);
                $settings = $this->repo->billingUserSetting($userId);
                if ($respectInvoiceSchedule && !$this->userInvoiceRunIsDue($settings, $now)) {
                    return;
                }
                $invoice = $this->createInvoiceFromPendingItems($user, $lastRun, $now, $actor, ['domain_yearly']);
                if ($invoice) {
                    $this->repo->updateUserInvoiceSchedule($userId, $settings['invoice_frequency'] ?? 'monthly', $now);
                    $createdInvoices = 1;
                }
            });
            $message = $this->appendRunNotes(t('billing.user_run_result', [
                'user' => $user['display_name'] ?? $user['username'] ?? ('#' . $userId),
                'invoices' => $createdInvoices,
                'items' => $queuedItems,
            ]));
            $this->repo->audit($actor, 'billing_user_run_finished', 'local_user', $userId, [
                'invoices' => $createdInvoices,
                'items' => $queuedItems,
            ]);
            return $message;
        } catch (Throwable $e) {
            $this->repo->audit($actor, 'billing_user_run_failed', 'local_user', $userId, ['error' => $e->getMessage()]);
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

    public function approveInvoice(int $invoiceId, string $actor = 'admin'): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        if (in_array((string)$invoice['status'], ['sent', 'cancelled'], true)) {
            throw new RuntimeException(t('billing.invoice_approve_not_allowed'));
        }
        $this->repo->setInvoiceStatus($invoiceId, 'approved');
        $this->repo->audit($actor, 'invoice_approved', 'invoice', $invoiceId);
        return t('billing.invoice_approved');
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
        $referenceStatuses = $this->repo->invoiceItemReferenceStatuses(array_column($items, 'billing_reference'), $invoiceId);
        foreach ($items as $item) {
            if ($this->invoiceItemHasOtherActiveInvoice($item, $referenceStatuses)) {
                continue;
            }
            $this->restoreDomainBillingDateFromInvoiceItem($item);
            $this->restoreUserBillingItemFromInvoiceItem($item);
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
        try {
            $this->mailInvoice($invoice, $pdfPath);
        } catch (Throwable $e) {
            $this->repo->markInvoiceFailed($invoiceId, $e->getMessage());
            $this->repo->audit($actor, 'invoice_send_failed', 'invoice', $invoiceId, [
                'invoice_number' => $invoice['invoice_number'] ?? '',
                'error' => $e->getMessage(),
            ]);
            throw new InvalidArgumentException(t('billing.mail_failed') . ' ' . $e->getMessage());
        }
        $this->repo->markInvoiceSent($invoiceId, $pdfPath);
        $this->repo->audit($actor, 'invoice_sent', 'invoice', $invoiceId);
        return t('billing.invoice_sent');
    }

    public function markInvoicePaid(int $invoiceId, array $data, string $actor = 'admin'): string
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        $paidAt = $this->dateOnly($data['paid_at'] ?? null);
        if (!$paidAt) {
            throw new InvalidArgumentException(t('billing.payment_date_required'));
        }
        $reference = trim((string)($data['payment_reference'] ?? ''));
        $note = trim((string)($data['payment_note'] ?? ''));
        $this->repo->markInvoicePaid($invoiceId, $paidAt->format('Y-m-d'), $reference, $note);
        $this->repo->audit($actor, 'invoice_paid', 'invoice', $invoiceId, [
            'invoice_number' => $invoice['invoice_number'] ?? '',
            'paid_at' => $paidAt->format('Y-m-d'),
            'reference' => $reference,
        ]);
        return t('billing.invoice_paid_saved');
    }

    public function recordCustomerPayment(int $userId, array $data, string $actor = 'admin'): string
    {
        $user = $this->repo->localUser($userId);
        if (!$user) {
            throw new InvalidArgumentException(t('billing.user_required'));
        }
        $paidAt = $this->dateOnly($data['paid_at'] ?? null);
        if (!$paidAt) {
            throw new InvalidArgumentException(t('billing.payment_date_required'));
        }
        $result = $this->repo->recordCustomerPayment(
            $userId,
            $paidAt->format('Y-m-d'),
            $data['payment_amount'] ?? 0,
            trim((string)($data['payment_reference'] ?? '')),
            trim((string)($data['payment_note'] ?? ''))
        );
        $this->repo->audit($actor, 'customer_payment_recorded', 'user', $userId, $result);
        return t('billing.customer_payment_saved', [
            'paid' => (string)$result['paid_invoices'],
            'partial' => (string)$result['partial_invoices'],
            'credit' => (string)$result['credit'],
        ]);
    }

    public function backbillDomains(array $data, string $actor = 'admin'): string
    {
        $userId = (int)($data['user_id'] ?? 0);
        $user = $this->repo->localUser($userId);
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
            $invoice = $this->createInvoiceFromPendingItems(
                $user,
                new DateTimeImmutable('today'),
                new DateTimeImmutable('now'),
                $actor,
                $useDomainSchedule ? ['domain_backbill'] : []
            );
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

    private function queueDueDomainItems(DateTimeImmutable $lastRun, DateTimeImmutable $now, ?int $onlyUserId = null): int
    {
        $count = 0;
        $users = $this->repo->usersFlatByServerExternalId();
        $localUsers = $this->repo->localUsersById();
        $tldPrices = $this->repo->billingTldPricesByTld();
        $overrides = $this->repo->billingDomainOverridesByDomainId();
        $userSettings = $this->repo->billingUserSettingsByUserId();
        foreach ($this->repo->domains() as $domain) {
            if (!empty($domain['is_deleted'])) {
                continue;
            }
            $userKey = (int)$domain['server_id'] . ':' . (string)($domain['owner_external_id'] ?? '');
            $user = !empty($domain['local_user_id']) ? ($localUsers[(int)$domain['local_user_id']] ?? null) : ($users[$userKey] ?? null);
            if (!$user) {
                continue;
            }
            if ($onlyUserId !== null && (int)$user['id'] !== $onlyUserId) {
                continue;
            }
            $tld = $this->tld((string)$domain['domain']);
            if ($tld === '' || empty($tldPrices[$tld]) || !(int)$tldPrices[$tld]['active']) {
                continue;
            }
            $userDiscount = (float)($userSettings[(int)$user['id']]['discount_percent'] ?? 0);
            $override = $overrides[(int)$domain['id']] ?? [];
            $tax = $this->taxRate($override['tax_rate_id'] ?? $tldPrices[$tld]['tax_rate_id'] ?? null);
            $frequency = $this->billingFrequency((string)($domain['billing_frequency'] ?? 'yearly'));
            if ($this->dateInWindow($domain['registered_at'] ?? null, $lastRun, $now)) {
                $count += $this->queueDomainItem($user, $domain, 'domain_registration', $domain['registered_at'], $tldPrices[$tld], $override, $userDiscount, $tax);
            }
            $next = $this->dateOnly($domain['next_billing_at'] ?? null);
            if ($next && $next < $now) {
                $this->repo->deletePendingDomainBillingItems((int)$domain['id'], ['domain_yearly']);
            }
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
        $reference = $type . ':' . (int)$domain['id'] . ':' . ($serviceDate ?: 'none');
        if ($this->billingReferenceHasActiveInvoice($reference)) {
            if ($type === 'domain_yearly') {
                $this->advanceDomainBillingFromItems([[
                    'source_type' => $type,
                    'source_id' => (int)$domain['id'],
                    'service_date' => $serviceDate,
                ]], [$type]);
            }
            return 0;
        }
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
            'billing_reference' => $reference,
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

    private function queueDueUserItems(DateTimeImmutable $now, ?int $onlyUserId = null): int
    {
        $count = 0;
        $settings = $this->repo->billingUserSettingsByUserId();
        foreach ($this->repo->billingUserItems(true) as $item) {
            if ($onlyUserId !== null && (int)$item['user_id'] !== $onlyUserId) {
                continue;
            }
            $dueDates = $this->dueItemDates($item, $now);
            foreach ($dueDates as $dueDate) {
                $reference = 'user_item:' . (int)$item['id'] . ':' . $dueDate->format('Y-m-d');
                if ($this->billingReferenceHasActiveInvoice($reference) || $this->sourceHasActiveInvoice('user_item', (int)$item['id'], $dueDate->format('Y-m-d'))) {
                    $this->repo->updateUserItemBilling(
                        (int)$item['id'],
                        $dueDate->format('Y-m-d'),
                        $this->nextItemDate($dueDate, (string)$item['frequency'])->format('Y-m-d')
                    );
                    if ($item['frequency'] === 'once') {
                        $this->repo->deactivateUserItem((int)$item['id']);
                        break;
                    }
                    continue;
                }
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
                    'billing_reference' => $reference,
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

    private function createDueInvoices(DateTimeImmutable $lastRun, DateTimeImmutable $now, string $actor, bool $respectInvoiceSchedule): int
    {
        $created = 0;
        foreach ($this->repo->billingUsersWithPendingItems() as $user) {
            $settings = $this->repo->billingUserSetting((int)$user['id']);
            if ($respectInvoiceSchedule && !$this->userInvoiceRunIsDue($settings, $now)) {
                continue;
            }
            $invoice = $this->createInvoiceFromPendingItems($user, $lastRun, $now, $actor, ['domain_yearly']);
            if (!$invoice) {
                continue;
            }
            $this->repo->updateUserInvoiceSchedule((int)$user['id'], $settings['invoice_frequency'] ?? 'monthly', $now);
            $created++;
        }
        return $created;
    }

    private function userInvoiceRunIsDue(array $settings, DateTimeImmutable $now): bool
    {
        $frequency = (string)($settings['invoice_frequency'] ?? 'monthly');
        if ($frequency === 'immediate') {
            $frequency = 'daily';
        }
        if ($frequency === 'daily') {
            return true;
        }
        if ($frequency === 'weekly') {
            $weekday = max(1, min(7, (int)$this->repo->billingSetting('weekly_invoice_weekday', '1')));
            return (int)$now->format('N') === $weekday;
        }
        $day = max(1, min(28, (int)$this->repo->billingSetting('monthly_invoice_day', '1')));
        return (int)$now->format('j') === $day;
    }

    private function requeueInvoiceItems(int $invoiceId, string $actor): int
    {
        $invoice = $this->repo->invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException(t('billing.invoice_not_found'));
        }
        $count = 0;
        $items = $this->repo->invoiceItems($invoiceId);
        $referenceStatuses = $this->repo->invoiceItemReferenceStatuses(array_column($items, 'billing_reference'), $invoiceId);
        foreach ($items as $item) {
            if ($this->invoiceItemHasOtherActiveInvoice($item, $referenceStatuses)) {
                continue;
            }
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
            $this->restoreUserBillingItemFromInvoiceItem($item);
        }
        $this->repo->audit($actor, 'invoice_items_requeued', 'invoice', $invoiceId, ['items' => $count]);
        return $count;
    }

    private function invoiceItemHasOtherActiveInvoice(array $item, array $referenceStatuses): bool
    {
        $reference = (string)($item['billing_reference'] ?? '');
        if ($reference === '' || !isset($referenceStatuses[$reference])) {
            return false;
        }
        return ($referenceStatuses[$reference]['status'] ?? '') !== 'cancelled';
    }

    private function billingReferenceHasActiveInvoice(string $reference): bool
    {
        if ($reference === '') {
            return false;
        }
        $statuses = $this->repo->invoiceItemReferenceStatuses([$reference]);
        return isset($statuses[$reference]) && ($statuses[$reference]['status'] ?? '') !== 'cancelled';
    }

    private function sourceHasActiveInvoice(string $sourceType, int $sourceId, ?string $serviceDate): bool
    {
        $status = $this->repo->invoiceItemSourceStatus($sourceType, $sourceId, $serviceDate);
        return $status !== null && ($status['status'] ?? '') !== 'cancelled';
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

    private function restoreUserBillingItemFromInvoiceItem(array $item): void
    {
        if ((string)($item['source_type'] ?? '') !== 'user_item') {
            return;
        }
        $itemId = (int)($item['source_id'] ?? 0);
        $serviceDate = $this->dateOnly($item['service_date'] ?? null);
        if ($itemId <= 0 || !$serviceDate) {
            return;
        }
        $this->repo->restoreUserItemBillingFromInvoice($itemId, $serviceDate->format('Y-m-d'));
    }

    private function createInvoiceFromPendingItems(array $user, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, string $actor, array $advanceDomainBillingSources): ?array
    {
        $items = $this->repo->pendingBillingItemsForUser((int)$user['id']);
        if ($items === []) {
            return null;
        }
        $referenceStatuses = $this->repo->invoiceItemReferenceStatuses(array_column($items, 'billing_reference'));
        $blockedItems = [];
        $blockedPendingItems = [];
        $billableItems = [];
        foreach ($items as $item) {
            $reference = (string)$item['billing_reference'];
            $sourceStatus = $this->repo->invoiceItemSourceStatus(
                (string)($item['source_type'] ?? ''),
                (int)($item['source_id'] ?? 0),
                $item['service_date'] ?? null
            );
            $referenceStatus = $referenceStatuses[$reference] ?? null;
            $hasActiveReference = $referenceStatus !== null && ($referenceStatus['status'] ?? '') !== 'cancelled';
            $hasActiveSource = $sourceStatus !== null && ($sourceStatus['status'] ?? '') !== 'cancelled';
            if (!$hasActiveReference && !$hasActiveSource) {
                $billableItems[] = $item;
                continue;
            }
            $blockedStatus = $hasActiveReference ? $referenceStatus : $sourceStatus;
            $blockedItems[] = [
                'reference' => $reference,
                'invoice_number' => $blockedStatus['invoice_number'] ?? '',
                'status' => $blockedStatus['status'] ?? '',
            ];
            $blockedPendingItems[] = $item;
        }
        $items = $billableItems;
        if ($blockedItems !== []) {
            $this->repo->audit($actor, 'pending_items_already_invoiced', 'billing_pending_item', null, [
                'user_id' => (int)$user['id'],
                'items' => $blockedItems,
            ]);
            if ($advanceDomainBillingSources !== []) {
                $this->advanceDomainBillingFromItems($blockedPendingItems, $advanceDomainBillingSources);
            }
            $this->alreadyInvoicedItems += count($blockedPendingItems);
        }
        if ($items === []) {
            return null;
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
        if ($advanceDomainBillingSources !== []) {
            $this->advanceDomainBillingFromItems($items, $advanceDomainBillingSources);
        }
        $this->repo->deletePendingBillingItems(array_map(static fn(array $item): int => (int)$item['id'], $items));
        $this->repo->audit($actor, 'invoice_created', 'invoice', $invoiceId, ['invoice_number' => $number]);
        return [
            'id' => $invoiceId,
            'number' => $number,
        ];
    }

    private function advanceDomainBillingFromItems(array $items, array $sourceTypes): void
    {
        $sourceTypes = array_values(array_unique(array_map('strval', $sourceTypes)));
        $maxServiceDateByDomain = [];
        foreach ($items as $item) {
            if (!in_array((string)($item['source_type'] ?? ''), $sourceTypes, true)) {
                continue;
            }
            $domainId = (int)($item['source_id'] ?? 0);
            $serviceDate = $this->dateOnly($item['service_date'] ?? null);
            if ($domainId <= 0 || !$serviceDate) {
                continue;
            }
            if (!isset($maxServiceDateByDomain[$domainId]) || $serviceDate > $maxServiceDateByDomain[$domainId]) {
                $maxServiceDateByDomain[$domainId] = $serviceDate;
            }
        }
        foreach ($maxServiceDateByDomain as $domainId => $maxServiceDate) {
            $domain = $this->repo->domain((int)$domainId);
            if (!$domain) {
                continue;
            }
            $nextDate = $this->dateOnly($domain['next_billing_at'] ?? null);
            if (!$nextDate) {
                continue;
            }
            $frequency = $this->billingFrequency((string)($domain['billing_frequency'] ?? 'yearly'));
            $guard = 0;
            while ($nextDate <= $maxServiceDate && $guard < 240) {
                $nextDate = $this->nextItemDate($nextDate, $frequency);
                $guard++;
            }
            $this->repo->updateDomainNextBilling((int)$domainId, $nextDate->format('Y-m-d'));
        }
    }

    private function resetRunCounters(): void
    {
        $this->alreadyInvoicedItems = 0;
    }

    private function appendRunNotes(string $message): string
    {
        if ($this->alreadyInvoicedItems <= 0) {
            return $message;
        }
        return $message . ' ' . t('billing.already_invoiced_skipped', [
            'count' => $this->alreadyInvoicedItems,
        ]);
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
        $format = $this->repo->billingSetting('invoice_number_format', '{{YEAR}}{{MONTH}}{{DAY}}-{{SEQ}}');
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
                'YEAR' => $now->format('Y'),
                'MONTH' => $now->format('m'),
                'DAY' => $now->format('d'),
                'SEQ' => (string)$sequence,
                'USERID' => (string)$user['id'],
                'USERNAME' => $username,
                default => throw new RuntimeException(t('billing.invalid_invoice_format')),
            };
            if (isset($match[2])) {
                if (!in_array($match[1], ['SEQ', 'USERID'], true)) {
                    throw new RuntimeException(t('billing.invalid_invoice_format'));
                }
                return str_pad($value, max(1, (int)$match[2]), '0', STR_PAD_LEFT);
            }
            if ($match[1] === 'SEQ') {
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
            $user = $this->repo->localUser((int)($invoice['user_id'] ?? 0));
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
        (new InvoicePdfRenderer($this->config + ['formats' => $this->repo->formatSettings()]))->write(
            $invoice,
            $items,
            $path,
            $this->repo->billingSetting('invoice_sender', ''),
            $this->repo->billingSetting('invoice_template_html', InvoicePdfRenderer::defaultTemplate()),
            $this->repo->billingSetting('payment_account_details', '')
        );
        return $path;
    }

    private function mailInvoice(array $invoice, string $pdfPath): void
    {
        $snapshot = json_decode((string)($invoice['recipient_snapshot'] ?? ''), true) ?: [];
        $email = $this->invoiceEmail($invoice, $snapshot);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(t('billing.mail_recipient_invalid', ['email' => $email]));
        }
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException(t('billing.mail_pdf_missing', ['path' => $pdfPath]));
        }
        $from = $this->repo->billingSetting('invoice_sender', '');
        $variables = $this->mailTemplateVariables($invoice, $snapshot);
        $subject = $this->renderMailTemplate(
            $this->repo->billingSetting('invoice_mail_subject', 'Rechnung {{invoice.number}}'),
            $variables
        );
        if ($subject === '') {
            $subject = 'Rechnung ' . (string)($invoice['invoice_number'] ?? '');
        }
        $text = $this->renderMailTemplate(
            $this->repo->billingSetting(
                'invoice_mail_body',
                "Guten Tag {{customer.name}},\n\nIhre Rechnung {{invoice.number}} über {{invoice.total}} befindet sich im Anhang.\n\nMit freundlichen Grüßen\n{{sender.name}}"
            ),
            $variables
        );
        if ($text === '') {
            $text = "Ihre Rechnung befindet sich im Anhang.";
        }
        $boundary = 'khmsm-' . bin2hex(random_bytes(12));
        $headers = [];
        $fromEmail = $this->senderEmail($from);
        $fromHeader = $this->mailAddressHeader($from);
        if ($fromHeader !== '') {
            $headers[] = 'From: ' . $fromHeader;
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $pdfContent = file_get_contents($pdfPath);
        if ($pdfContent === false) {
            throw new RuntimeException(t('billing.mail_pdf_missing', ['path' => $pdfPath]));
        }
        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $text)) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: application/pdf; name="' . basename($pdfPath) . '"' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . basename($pdfPath) . '"' . "\r\n\r\n";
        $body .= chunk_split(base64_encode($pdfContent)) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";
        $parameters = $fromEmail !== '' ? '-f' . escapeshellarg($fromEmail) : '';
        $ok = $parameters !== ''
            ? mail($email, $this->mailSubject($subject), $body, implode("\r\n", $headers), $parameters)
            : mail($email, $this->mailSubject($subject), $body, implode("\r\n", $headers));
        if (!$ok) {
            $lastError = error_get_last();
            $detail = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
            throw new RuntimeException(t('billing.mail_failed_detail', ['detail' => $detail !== '' ? $detail : 'mail() returned false']));
        }
    }

    private function renderMailTemplate(string $template, array $variables): string
    {
        $rendered = preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', static function (array $match) use ($variables): string {
            return (string)($variables[$match[1]] ?? '');
        }, $template) ?? $template;
        return trim($rendered);
    }

    private function mailTemplateVariables(array $invoice, array $snapshot): array
    {
        $customerName = trim(implode(' ', array_filter([
            (string)($snapshot['first_name'] ?? ''),
            (string)($snapshot['last_name'] ?? ''),
        ])));
        if ($customerName === '') {
            $customerName = (string)($snapshot['company'] ?? $invoice['username'] ?? '');
        }
        $periodStart = substr((string)($invoice['period_start'] ?? ''), 0, 10);
        $periodEnd = substr((string)($invoice['period_end'] ?? ''), 0, 10);
        $sender = $this->repo->billingSetting('invoice_sender', '');

        return [
            'sender.name' => $this->senderName($sender),
            'sender.email' => $this->senderEmail($sender),
            'sender.full' => trim($sender),
            'customer.name' => $customerName,
            'customer.number' => (string)($snapshot['customer_number'] ?? $invoice['username'] ?? ''),
            'customer.email' => (string)($snapshot['email'] ?? $invoice['email'] ?? ''),
            'customer.invoice_email' => $this->invoiceEmail($invoice, $snapshot),
            'customer.server' => (string)($snapshot['server_name'] ?? $invoice['server_name'] ?? ''),
            'invoice.number' => (string)($invoice['invoice_number'] ?? ''),
            'invoice.date' => format_date_local(substr((string)($invoice['created_at'] ?? ''), 0, 10)),
            'invoice.period' => trim(format_date_local($periodStart) . ' - ' . format_date_local($periodEnd), ' -'),
            'invoice.subtotal' => $this->mailMoney($invoice['subtotal'] ?? 0),
            'invoice.tax_total' => $this->mailMoney($invoice['tax_total'] ?? 0),
            'invoice.total' => $this->mailMoney($invoice['total'] ?? 0),
        ];
    }

    private function mailMoney(mixed $value): string
    {
        $settings = $this->repo->formatSettings();
        $currency = (string)($settings['currency'] ?? 'EUR');
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }
        return locale_number((float)$value, 2) . ' ' . $currency;
    }

    private function invoiceEmail(array $invoice, array $snapshot): string
    {
        foreach ([
            $snapshot['invoice_email'] ?? null,
            $invoice['invoice_email'] ?? null,
            $snapshot['email'] ?? null,
            $invoice['email'] ?? null,
        ] as $email) {
            $email = trim((string)$email);
            if ($email !== '') {
                return $email;
            }
        }
        return '';
    }

    private function mailSubject(string $subject): string
    {
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        if (preg_match('/[^\x20-\x7E]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }

    private function mailAddressHeader(string $sender): string
    {
        $sender = trim(str_replace(["\r", "\n"], ' ', $sender));
        $email = $this->senderEmail($sender);
        if ($email === '') {
            return '';
        }
        $name = $this->senderName($sender);
        if ($name === '' || $name === $email) {
            return $email;
        }
        return $this->mailSubject($name) . ' <' . $email . '>';
    }

    private function senderName(string $sender): string
    {
        if (preg_match('/^(.+?)\s*<.+>$/', trim($sender), $match)) {
            return trim($match[1], " \t\n\r\0\x0B\"");
        }
        return trim($sender) ?: (string)($this->config['app']['name'] ?? 'KeyHelp MSM');
    }

    private function senderEmail(string $sender): string
    {
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', trim($sender), $match)) {
            $email = trim($match[1]);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        $sender = trim($sender);
        return filter_var($sender, FILTER_VALIDATE_EMAIL) ? $sender : '';
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
        $settings = $this->repo->billingUserSetting((int)$user['id']);
        return [
            'id' => (int)$user['id'],
            'server_id' => (int)($user['server_id'] ?? 0),
            'server_name' => $user['server_name'] ?? '',
            'username' => $user['username'] ?? '',
            'email' => $user['email'] ?? '',
            'invoice_email' => $user['invoice_email'] ?? '',
            'company' => $user['company'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? ($user['display_name'] ?? ''),
            'address' => $user['address'] ?? '',
            'postcode' => $user['postcode'] ?? '',
            'city' => $user['city'] ?? '',
            'region' => $user['region'] ?? '',
            'country' => $user['country'] ?? '',
            'customer_number' => $user['customer_number'] ?? '',
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
