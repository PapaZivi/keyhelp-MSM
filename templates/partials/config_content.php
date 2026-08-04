<?php
$taxRates = $billingData['taxRates'] ?? [];
$tldPrices = $billingData['tldPrices'] ?? [];
$settings = $billingData['settings'] ?? [];
$taxOptions = static function (array $taxRates, mixed $selected = null): void {
    echo '<option value="">' . h(t('billing.no_tax')) . '</option>';
    foreach ($taxRates as $tax) {
        $isSelected = (string)($selected ?? '') === (string)$tax['id'];
        echo '<option value="' . (int)$tax['id'] . '" ' . ($isSelected ? 'selected' : '') . '>' . h($tax['name'] . ' (' . $tax['rate_percent'] . '%)') . '</option>';
    }
};
?>
<section class="card config-page">
    <div class="card-body">
        <div class="section-head">
            <h2 class="h5 mb-0"><?= h(t('config.title')) ?></h2>
        </div>

        <ul class="nav nav-tabs config-tabs mt-3" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#config-tab-interface" type="button" role="tab"><?= h(t('config.tab_interface')) ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#config-tab-system" type="button" role="tab"><?= h(t('config.tab_system')) ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#config-tab-billing" type="button" role="tab"><?= h(t('config.tab_billing')) ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#config-tab-tax" type="button" role="tab"><?= h(t('billing.tax_rates')) ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#config-tab-tld" type="button" role="tab"><?= h(t('billing.tld_prices')) ?></button></li>
        </ul>

        <div class="tab-content config-tab-content">
            <div class="tab-pane fade show active" id="config-tab-interface" role="tabpanel">
                <form method="post" class="settings-form"><input type="hidden" name="_action" value="update_config"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <label class="form-label" for="locale"><?= h(t('common.language')) ?></label>
                    <select class="form-select" id="locale" name="locale"><?php foreach ($supportedLocales as $localeCode => $localeLabel): ?><option value="<?= h($localeCode) ?>" <?= $localeCode === $appLocale ? 'selected' : '' ?>><?= h($localeLabel) ?></option><?php endforeach; ?></select>
                    <label class="form-label" for="theme_mode"><?= h(t('config.theme_mode')) ?></label>
                    <select class="form-select" id="theme_mode" name="theme_mode"><?php foreach ($themeModeOptions as $option): ?><option value="<?= h($option) ?>" <?= $option === $themeMode ? 'selected' : '' ?>><?= h(t('theme.' . $option)) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-primary icon-only status-tooltip" type="submit"<?= icon_button_attrs(t('config.save')) ?>><?= icon_svg('save') ?></button>
                </form>
            </div>

            <div class="tab-pane fade" id="config-tab-system" role="tabpanel">
                <form method="post" class="settings-form"><input type="hidden" name="_action" value="update_config"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <input type="hidden" name="locale" value="<?= h($appLocale) ?>">
                    <input type="hidden" name="theme_mode" value="<?= h($themeMode) ?>">
                    <label class="form-label" for="server_refresh_interval"><?= h(t('config.refresh_interval')) ?></label>
                    <select class="form-select" id="server_refresh_interval" name="server_refresh_interval"><?php foreach ($serverRefreshIntervalOptions as $option): ?><option value="<?= (int)$option ?>" <?= (int)$option === (int)$serverRefreshInterval ? 'selected' : '' ?>><?= (int)$option === 0 ? h(t('common.off')) : (int)$option . ' ' . h(t('common.seconds')) ?></option><?php endforeach; ?></select>
                    <label class="form-label" for="username_pattern"><?= h(t('config.username_pattern')) ?></label>
                    <input class="form-control" id="username_pattern" name="username_pattern" value="<?= h($usernamePattern) ?>">
                    <p class="form-text"><?= h(t('config.username_pattern_help')) ?></p>
                    <button class="btn btn-primary icon-only status-tooltip" type="submit"<?= icon_button_attrs(t('config.save')) ?>><?= icon_svg('save') ?></button>
                </form>
            </div>

            <div class="tab-pane fade" id="config-tab-billing" role="tabpanel">
                <form method="post" class="config-billing-form">
                    <input type="hidden" name="_action" value="billing_save_settings">
                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <div class="billing-form-grid">
                        <label class="form-label">
                            <?= h(t('billing.invoice_sender')) ?>
                            <input
                                class="form-control"
                                name="invoice_sender"
                                value="<?= h($settings['invoice_sender'] ?? '') ?>"
                                placeholder="Firma <rechnung@example.com>"
                            >
                        </label>
                        <label class="form-label">
                            <?= h(t('billing.notification_recipients')) ?>
                            <textarea class="form-control" name="invoice_notification_recipients" rows="2"><?= h($settings['invoice_notification_recipients'] ?? '') ?></textarea>
                        </label>
                        <label class="form-label">
                            <?= h(t('billing.payment_account_details')) ?>
                            <textarea
                                class="form-control"
                                name="payment_account_details"
                                rows="4"
                                placeholder="Bank:&#10;IBAN:&#10;BIC:"
                            ><?= h($settings['payment_account_details'] ?? '') ?></textarea>
                        </label>
                        <label class="form-label">
                            <?= h(t('billing.invoice_number_format')) ?>
                            <input
                                class="form-control"
                                name="invoice_number_format"
                                value="<?= h($settings['invoice_number_format'] ?? '{{JAHR}}{{MONAT}}{{TAG}}-{{LFNR}}') ?>"
                            >
                        </label>
                        <p class="form-text"><?= h(t('billing.invoice_number_help')) ?></p>
                    </div>

                    <p class="form-text mt-3"><?= h(t('billing.template_variables_help')) ?></p>
                    <div class="template-editor-grid">
                        <label class="form-label">
                            <?= h(t('billing.invoice_template')) ?>
                            <textarea
                                class="form-control code-textarea"
                                name="invoice_template_html"
                                rows="24"
                                data-invoice-template-editor
                            ><?= h($settings['invoice_template_html'] ?? InvoicePdfRenderer::defaultTemplate()) ?></textarea>
                        </label>
                        <div>
                            <div class="template-preview-title"><?= h(t('billing.template_preview')) ?></div>
                            <iframe
                                class="template-preview-frame"
                                data-invoice-template-preview
                                sandbox
                                title="<?= h(t('billing.template_preview')) ?>"
                            ></iframe>
                        </div>
                    </div>

                    <div class="template-editor-grid mt-4">
                        <label class="form-label">
                            <?= h(t('billing.dunning_template')) ?>
                            <textarea
                                class="form-control code-textarea"
                                name="dunning_template_html"
                                rows="18"
                                data-dunning-template-editor
                            ><?= h($settings['dunning_template_html'] ?? InvoicePdfRenderer::defaultDunningTemplate()) ?></textarea>
                        </label>
                        <div>
                            <div class="template-preview-title"><?= h(t('billing.template_preview')) ?></div>
                            <iframe
                                class="template-preview-frame"
                                data-dunning-template-preview
                                sandbox
                                title="<?= h(t('billing.template_preview')) ?>"
                            ></iframe>
                        </div>
                    </div>

                    <button class="btn btn-primary icon-only status-tooltip mt-3" type="submit"<?= icon_button_attrs(t('common.save')) ?>>
                        <?= icon_svg('save') ?>
                    </button>
                </form>
            </div>

            <div class="tab-pane fade" id="config-tab-tax" role="tabpanel">
                <form method="post" class="billing-inline-form config-tax-form">
                    <input type="hidden" name="_action" value="billing_save_tax_rate"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <input class="form-control" name="name" placeholder="<?= h(t('billing.name')) ?>" required>
                    <input class="form-control" name="rate_percent" type="number" step="0.001" min="0" placeholder="19.00" required>
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="is_default" value="1"> <?= h(t('billing.default')) ?></label>
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked> <?= h(t('server.active')) ?></label>
                    <button class="btn btn-primary icon-only status-tooltip" type="submit"<?= icon_button_attrs(t('common.save')) ?>><?= icon_svg('save') ?></button>
                </form>
                <div class="table-responsive mt-4"><table class="table table-sm billing-table"><thead><tr><th><?= h(t('billing.name')) ?></th><th>%</th><th><?= h(t('server.active')) ?></th></tr></thead><tbody><?php foreach ($taxRates as $tax): ?><tr><td><?= h($tax['name']) ?><?= (int)$tax['is_default'] ? ' *' : '' ?></td><td><?= h($tax['rate_percent']) ?></td><td><?= (int)$tax['active'] ? h(t('server.active')) : h(t('server.inactive')) ?></td></tr><?php endforeach; ?></tbody></table></div>
            </div>

            <div class="tab-pane fade" id="config-tab-tld" role="tabpanel">
                <form method="post" class="billing-form-grid billing-form-wide config-tld-form">
                    <input type="hidden" name="_action" value="billing_save_tld_price"><input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <input type="hidden" name="id" value="">
                    <label class="form-label">TLD<input class="form-control" name="tld" placeholder="de" required></label>
                    <label class="form-label"><?= h(t('billing.registration_price')) ?><input class="form-control" name="registration_price" type="number" step="0.01" min="0" value="0.00"></label>
                    <label class="form-label"><?= h(t('billing.yearly_price')) ?><input class="form-control" name="yearly_price" type="number" step="0.01" min="0" value="0.00"></label>
                    <label class="form-label"><?= h(t('billing.change_price')) ?><input class="form-control" name="change_price" type="number" step="0.01" min="0" value="0.00"></label>
                    <label class="form-label"><?= h(t('billing.tax_rate')) ?><select class="form-select" name="tax_rate_id"><?php $taxOptions($taxRates); ?></select></label>
                    <label class="form-check billing-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked> <?= h(t('server.active')) ?></label>
                    <button class="btn btn-primary icon-only status-tooltip" type="submit"<?= icon_button_attrs(t('common.save')) ?>><?= icon_svg('save') ?></button>
                </form>
                <?php render_partial('config_tld_prices', compact('tldPrices')); ?>
            </div>
        </div>
    </div>
</section>
