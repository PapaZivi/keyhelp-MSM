<?php
$billing = $billing ?? $billingData ?? [];
$taxRates = $billing['taxRates'] ?? [];
$tldPrices = $billing['tldPrices'] ?? [];
$domainOverrides = $billing['domainOverrides'] ?? [];
$userSettings = $billing['userSettings'] ?? [];
$userItems = $billing['userItems'] ?? [];
$pendingItems = $billing['pendingItems'] ?? [];
$invoices = $billing['invoices'] ?? [];
$settings = $billing['settings'] ?? [];
$domains = $domains ?? [];
$users = [];
foreach ($userGroups as $group) {
    foreach (($group['users'] ?? []) as $user) {
        $users[] = $user;
    }
}
$billingFrequencyLabel = static function (?string $frequency): string {
    $key = $frequency === 'halfyearly' ? 'semiannual' : ($frequency ?: 'yearly');
    return t('billing.frequency_' . $key);
};
$splitDescription = static function (string $description): array {
    $parts = preg_split('/\R/', $description, 2);
    return [
        trim((string)($parts[0] ?? '')),
        trim((string)($parts[1] ?? '')),
    ];
};
$formatPercent = static function (mixed $value): string {
    $number = (float)str_replace(',', '.', (string)($value ?? 0));
    if (abs($number) < 0.0005) {
        return '';
    }
    return rtrim(rtrim(number_format($number, 3, ',', '.'), '0'), ',') . ' %';
};
$taxOptions = static function (array $taxRates, mixed $selected = null): void {
    echo '<option value="">' . h(t('billing.no_tax')) . '</option>';
    foreach ($taxRates as $tax) {
        $isSelected = (string)($selected ?? '') === (string)$tax['id'];
        echo '<option value="' . (int)$tax['id'] . '" ' . ($isSelected ? 'selected' : '') . '>' . h($tax['name'] . ' (' . $tax['rate_percent'] . '%)') . '</option>';
    }
};
?>
<section class="billing-page">
    <div class="billing-toolbar">
        <form method="post"><input type="hidden" name="_action" value="billing_run"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="btn btn-primary"><?= h(t('billing.run_now')) ?></button></form>
        <form method="post"><input type="hidden" name="_action" value="billing_send_queue"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="btn btn-outline-primary"><?= h(t('billing.send_queue')) ?></button></form>
        <span class="billing-muted"><?= h(t('billing.last_run')) ?>: <?= h(format_date_local($settings['last_run_at'] ?? '')) ?></span>
    </div>


    <section class="card billing-card">
        <div class="card-body">
            <h2 class="h5"><?= h(t('billing.invoices')) ?></h2>
            <div class="table-responsive">
                <table class="table billing-table">
                    <thead>
                        <tr>
                            <th><?= h(t('billing.invoice_number')) ?></th>
                            <th><?= h(t('js.user')) ?></th>
                            <th><?= h(t('billing.status')) ?></th>
                            <th><?= h(t('billing.total')) ?></th>
                            <th><?= h(t('common.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><?= h($invoice['invoice_number']) ?></td>
                            <td><?= h($invoice['server_name'] . ' / ' . $invoice['username']) ?></td>
                            <td>
                                <span class="billing-status billing-status-<?= h($invoice['status']) ?>">
                                    <?= h(t('billing.status_' . $invoice['status'])) ?>
                                </span>
                            </td>
                            <td><?= h($invoice['total']) ?></td>
                            <td class="billing-actions">
                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="/?invoice_pdf=<?= (int)$invoice['id'] ?>"
                                    target="invoice-<?= h($invoice['invoice_number']) ?>"
                                >
                                    <?= h(t('billing.view_invoice')) ?>
                                </a>
                                <?php if (!in_array($invoice['status'], ['sent', 'cancelled'], true)): ?>
                                <form method="post">
                                    <input type="hidden" name="_action" value="billing_invoice_send">
                                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <button class="btn btn-sm btn-primary"><?= h(t('billing.approve_send')) ?></button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="_action" value="billing_invoice_queue">
                                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <button class="btn btn-sm btn-outline-primary"><?= h(t('billing.queue')) ?></button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="_action" value="billing_invoice_cancel">
                                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <button class="btn btn-sm btn-outline-danger"><?= h(t('billing.cancel')) ?></button>
                                </form>
                                <?php endif; ?>
                                <?php if (($invoice['status'] ?? '') === 'cancelled'): ?>
                                <form method="post">
                                    <input type="hidden" name="_action" value="billing_invoice_requeue">
                                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <button class="btn btn-sm btn-outline-primary"><?= h(t('billing.requeue_items')) ?></button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array(($invoice['status'] ?? ''), ['draft', 'pending_approval', 'failed', 'cancelled'], true)): ?>
                                <form method="post" onsubmit="return confirm('<?= h(t('billing.confirm_delete_invoice')) ?>');">
                                    <input type="hidden" name="_action" value="billing_invoice_delete">
                                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <button class="btn btn-sm btn-outline-danger"><?= h(t('common.delete')) ?></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
</section>

    <section class="card billing-card">
        <div class="card-body">
            <h2 class="h5"><?= h(t('billing.backbill_domains')) ?></h2>
            <form method="post" class="billing-form-grid billing-backbill-form">
                <input type="hidden" name="_action" value="billing_backbill_domains">
                <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                <p class="billing-muted grid-full">
                    <?= h(t('billing.backbill_domain_schedule_hint')) ?>
                </p>
                <label class="form-label">
                    <?= h(t('js.user')) ?>
                    <select class="form-select" name="user_id" data-backbill-user required>
                        <option value=""><?= h(t('billing.select_user')) ?></option>
                        <?php foreach ($users as $user): ?>
                        <option
                            value="<?= (int)$user['id'] ?>"
                            data-owner-key="<?= h((int)$user['server_id'] . ':' . (string)$user['external_id']) ?>"
                        >
                            <?= h(($user['server_name'] ?? '') . ' / ' . ($user['username'] ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-label">
                    <?= h(t('billing.period_from')) ?>
                    <input class="form-control" name="period_from" type="date">
                </label>
                <label class="form-label">
                    <?= h(t('billing.period_to')) ?>
                    <input class="form-control" name="period_to" type="date">
                </label>
                <label class="form-label">
                    <?= h(t('billing.price_source')) ?>
                    <select class="form-select" name="price_source" data-backbill-price-source>
                        <option value="tld"><?= h(t('billing.price_source_tld')) ?></option>
                        <option value="manual"><?= h(t('billing.price_source_manual')) ?></option>
                    </select>
                </label>
                <label class="form-label">
                    <?= h(t('billing.manual_price')) ?>
                    <input class="form-control" name="manual_price" type="number" step="0.01" min="0" data-backbill-manual-price disabled>
                </label>
                <label class="form-label grid-full">
                    <?= h(t('domains.title')) ?>
                    <select class="form-select" name="domain_ids[]" multiple size="8" data-backbill-domains required>
                        <?php foreach ($domains as $domain): ?>
                        <option
                            value="<?= (int)$domain['id'] ?>"
                            data-owner-key="<?= h((int)$domain['server_id'] . ':' . (string)($domain['owner_external_id'] ?? '')) ?>"
                        >
                            <?= h(
                                ($domain['server_name'] ?? '')
                                . ' / '
                                . ($domain['domain'] ?? '')
                                . ' - '
                                . $billingFrequencyLabel($domain['billing_frequency'] ?? 'yearly')
                                . ' / '
                                . format_date_local($domain['next_billing_at'] ?? '')
                            ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit"><?= h(t('billing.collect_items')) ?></button>
            </form>
        </div>
    </section>

    <section class="card billing-card">
        <div class="card-body">
            <h2 class="h5"><?= h(t('billing.pending_items')) ?></h2>
            <div class="table-responsive">
                <table class="table table-sm billing-table">
                    <thead>
                        <tr>
                            <th><?= h(t('js.user')) ?></th>
                            <th><?= h(t('billing.description')) ?></th>
                            <th><?= h(t('billing.discount')) ?></th>
                            <th><?= h(t('billing.total')) ?></th>
                            <th><?= h(t('billing.reference')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingItems as $item): ?>
                        <?php [$description, $description2] = $splitDescription((string)($item['description'] ?? '')); ?>
                        <tr>
                            <td><?= h($item['server_name'] . ' / ' . $item['username']) ?></td>
                            <td>
                                <?= h($description) ?>
                                <?php if ($description2 !== ''): ?>
                                <div class="billing-muted"><?= h($description2) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($formatPercent($item['discount_percent'] ?? 0)) ?></td>
                            <td><?= h($item['gross_total']) ?></td>
                            <td><?= h($item['billing_reference']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</section>
