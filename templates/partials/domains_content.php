<?php
$billingDomainOverrides = $billingDomainOverrides ?? [];
$normalizedOverrides = [];
foreach ($billingDomainOverrides as $key => $override) {
    if (is_array($override)) {
        $normalizedOverrides[(int)($override['domain_id'] ?? $key)] = $override;
    }
}
$billingDomainOverrides = $normalizedOverrides;
$billingTaxRates = $billingTaxRates ?? [];
$billingFrequencyOptions = Repository::billingFrequencyOptions();
$billingFrequencyLabel = static function (?string $frequency): string {
    $key = $frequency === 'halfyearly' ? 'semiannual' : ($frequency ?: 'yearly');
    return t('billing.frequency_' . $key);
};
?>
<div class="domain-content" data-domain-content>
    <div class="section-head">
        <h2 class="h5 mb-0"><?= h(t('domains.title')) ?></h2>
        <form method="post" class="ajax-domain-import-form">
            <input type="hidden" name="_action" value="import_domains">
            <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
            <button class="btn btn-primary"><?= h(t('domains.import')) ?></button>
        </form>
    </div>
    <div class="table-responsive mt-3">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th><?= h(t('domains.domain')) ?></th>
                    <th><?= h(t('domains.server')) ?></th>
                    <th><?= h(t('domains.owner')) ?></th>
                    <th><?= h(t('domains.registered')) ?></th>
                    <th><?= h(t('domains.next_billing')) ?></th>
                    <th><?= h(t('domains.billing_frequency')) ?></th>
                    <th><?= h(t('domains.registrar')) ?></th>
                    <th><?= h(t('domains.deletion')) ?></th>
                    <th><?= h(t('domains.subdomains')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domains as $domain): ?>
                <?php $domainPayload = $domain; $domainPayload['_billing_override'] = $billingDomainOverrides[(int)$domain['id']] ?? []; ?>
                <tr class="domain-row <?= h(domain_row_class($domain)) ?>" data-domain-id="<?= (int)$domain['id'] ?>" data-domain-name="<?= h($domain['domain']) ?>" data-domain-json="<?= h(json_encode($domainPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <td><span class="name-with-status"><span class="domain-name-text"><?= h($domain['domain']) ?></span><?= domain_name_status_html($domain) ?></span></td>
                    <td><?= h($domain['server_name']) ?></td>
                    <td><?= h($domain['owner_name'] ?: ($domain['owner_external_id'] ? 'User #' . $domain['owner_external_id'] : '')) ?></td>
                    <td data-domain-cell="registered_at"><?= h(format_date_local($domain['registered_at'] ?? '')) ?></td>
                    <td data-domain-cell="next_billing_at"><?= h(format_date_local($domain['next_billing_at'] ?? '')) ?></td>
                    <td data-domain-cell="billing_frequency"><?= h($billingFrequencyLabel($domain['billing_frequency'] ?? 'yearly')) ?></td>
                    <td data-domain-cell="registrar"><?= h($domain['registrar'] ?? '') ?></td>
                    <td class="domain-status-cell"><?= domain_status_html($domain) ?></td>
                    <td><button type="button" class="btn btn-outline-secondary btn-sm icon-only status-tooltip subdomain-toggle" data-server-id="<?= (int)$domain['server_id'] ?>" data-domain="<?= h($domain['domain']) ?>"<?= icon_button_attrs(t('common.show')) ?>><?= icon_svg('eye') ?></button></td>
                    <td>
                        <div class="domain-actions">
                            <button type="button" class="btn btn-outline-secondary btn-sm icon-only status-tooltip domain-refresh"<?= icon_button_attrs(t('domains.refresh')) ?>><?= icon_svg('refresh') ?></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm icon-only status-tooltip domain-settings-open" data-bs-toggle="modal" data-bs-target="#domainSettingsModal"<?= icon_button_attrs(t('common.edit')) ?>><?= icon_svg('edit') ?></button>
                        </div>
                    </td>
                </tr>
                <tr class="subdomain-row" id="subdomains-<?= (int)$domain['id'] ?>" hidden>
                    <td colspan="10"><div class="subdomain-box"><?= h(t('common.loading')) ?></div></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="modal fade" id="domainSettingsModal" tabindex="-1" aria-labelledby="domainSettingsModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form class="ajax-domain-settings-form">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="domainSettingsModalLabel"><?= h(t('domains.settings_title')) ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="_action" value="update_domain"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input type="hidden" name="id" data-domain-settings-id><input type="hidden" name="billing_override_present" value="1">
                        <p class="text-secondary fw-bold" data-domain-settings-label></p>
                        <ul class="nav nav-tabs user-create-tabs" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#domain-tab-general" type="button" role="tab"><?= h(t('users.tab_general')) ?></button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#domain-tab-billing" type="button" role="tab"><?= h(t('users.tab_billing')) ?></button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#domain-tab-contacts" type="button" role="tab"><?= h(t('domains.contacts')) ?></button></li>
                        </ul>
                        <div class="tab-content user-create-body">
                            <div class="tab-pane fade show active" id="domain-tab-general" role="tabpanel">
                                <div class="billing-form-grid">
                                    <label class="form-label"><?= h(t('domains.registered')) ?><input class="form-control" name="registered_at" type="date"></label>
                                    <label class="form-label"><?= h(t('domains.next_billing')) ?><input class="form-control" name="next_billing_at" type="date"></label>
                                    <label class="form-label">
                                        <?= h(t('domains.billing_frequency')) ?>
                                        <select class="form-select" name="billing_frequency">
                                            <?php foreach ($billingFrequencyOptions as $frequency): ?>
                                            <option value="<?= h($frequency) ?>">
                                                <?= h($billingFrequencyLabel($frequency)) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="form-label"><?= h(t('domains.last_change')) ?><input class="form-control" name="last_change_at" type="date"></label>
                                    <label class="form-label"><?= h(t('domains.registrar')) ?><input class="form-control" name="registrar"></label>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="domain-tab-billing" role="tabpanel">
                                <div class="billing-form-grid">
                                    <label class="form-label"><?= h(t('billing.yearly_special_price')) ?><input class="form-control" name="yearly_price" type="number" step="0.01" min="0"></label>
                                    <label class="form-label"><?= h(t('billing.domain_discount')) ?><input class="form-control" name="discount_percent" type="number" step="0.001" min="0" max="100"></label>
                                    <label class="form-label"><?= h(t('billing.registration_price')) ?><input class="form-control" name="registration_price" type="number" step="0.01" min="0"></label>
                                    <label class="form-label"><?= h(t('billing.change_price')) ?><input class="form-control" name="change_price" type="number" step="0.01" min="0"></label>
                                    <label class="form-label"><?= h(t('billing.tax_rate')) ?><select class="form-select" name="tax_rate_id"><option value=""><?= h(t('billing.no_tax')) ?></option><?php foreach ($billingTaxRates as $tax): ?><option value="<?= (int)$tax['id'] ?>"><?= h($tax['name'] . ' (' . $tax['rate_percent'] . '%)') ?></option><?php endforeach; ?></select></label>
                                    <label class="form-check billing-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked> <?= h(t('server.active')) ?></label>
                                </div>
                                <p class="billing-muted"><?= h(t('billing.fixed_or_discount_hint')) ?></p>
                            </div>
                            <div class="tab-pane fade" id="domain-tab-contacts" role="tabpanel">
                                <label class="form-label"><?= h(t('domains.owner_contact')) ?><textarea class="form-control" name="domain_owner_contact" rows="3"></textarea></label>
                                <label class="form-label">Admin-C<textarea class="form-control" name="domain_admin_c" rows="3"></textarea></label>
                                <label class="form-label">Tech-C<textarea class="form-control" name="domain_tech_c" rows="3"></textarea></label>
                                <label class="form-label">Zone-C<textarea class="form-control" name="domain_zone_c" rows="3"></textarea></label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('common.cancel')) ?></button>
                        <button type="submit" class="btn btn-primary icon-only status-tooltip"<?= icon_button_attrs(t('common.save')) ?>><?= icon_svg('save') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
