<div class="modal fade" id="localUserModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form class="ajax-local-user-form" novalidate>
                <div class="modal-header">
                    <h2 class="modal-title h5"><?= h(t('users.local_user_edit_title')) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger" data-local-user-form-error hidden></div>
                    <input type="hidden" name="_action" value="update_local_user">
                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <input type="hidden" name="id" data-local-user-id>
                    <input type="hidden" name="billing_item_allow_past_booking_date" value="0" data-billing-allow-past-date>

                    <ul class="nav nav-tabs user-create-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#local-user-tab-contact" type="button" role="tab">
                                <?= h(t('users.tab_contact')) ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#local-user-tab-billing" type="button" role="tab">
                                <?= h(t('users.tab_billing')) ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#local-user-tab-remotes" type="button" role="tab">
                                <?= h(t('users.remote_users')) ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#local-user-tab-domains" type="button" role="tab">
                                <?= h(t('nav.domains')) ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#local-user-tab-account" type="button" role="tab">
                                <?= h(t('billing.customer_account')) ?>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content user-create-body">
                        <div class="tab-pane fade show active" id="local-user-tab-contact" role="tabpanel">
                            <div class="billing-form-grid">
                                <label class="form-label">
                                    <?= h(t('users.local_user')) ?>
                                    <input class="form-control" name="display_name" required>
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.email')) ?>
                                    <input class="form-control" name="email" type="email">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.first_name')) ?>
                                    <input class="form-control" name="first_name">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.last_name')) ?>
                                    <input class="form-control" name="last_name">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.company')) ?>
                                    <input class="form-control" name="company">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.phone')) ?>
                                    <input class="form-control" name="phone">
                                </label>
                                <label class="form-label grid-full">
                                    <?= h(t('users.address')) ?>
                                    <textarea class="form-control" name="address" rows="3"></textarea>
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.postcode')) ?>
                                    <input class="form-control" name="postcode">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.city')) ?>
                                    <input class="form-control" name="city">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.region')) ?>
                                    <input class="form-control" name="region">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.country')) ?>
                                    <input class="form-control" name="country">
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.customer_number')) ?>
                                    <input class="form-control" name="customer_number">
                                </label>
                                <label class="form-label grid-full">
                                    <?= h(t('users.notes')) ?>
                                    <textarea class="form-control" name="notes" rows="3"></textarea>
                                </label>
                                <label class="form-check grid-full">
                                    <input class="form-check-input" type="checkbox" name="sync_remote_contacts" value="1" data-sync-remote-contacts>
                                    <?= h(t('users.sync_contact_to_remote_users')) ?>
                                </label>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="local-user-tab-billing" role="tabpanel">
                            <div class="billing-form-grid">
                                <label class="form-label grid-full">
                                    <?= h(t('billing.invoice_email')) ?>
                                    <input class="form-control" name="invoice_email" type="email">
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.user_discount')) ?>
                                    <input class="form-control" name="billing_discount_percent" type="number" step="0.001" min="0" max="100" value="0">
                                </label>
                                <input type="hidden" name="billing_invoice_frequency" value="monthly">
                            </div>
                        </div>

                        <div class="tab-pane fade" id="local-user-tab-remotes" role="tabpanel">
                            <div data-local-user-remotes></div>
                        </div>

                        <div class="tab-pane fade" id="local-user-tab-domains" role="tabpanel">
                            <div data-local-user-domains></div>
                        </div>

                        <div class="tab-pane fade" id="local-user-tab-account" role="tabpanel">
                            <div class="billing-form-grid mb-3">
                                <div class="metric-card">
                                    <strong data-customer-account-balance>0.00</strong>
                                    <span><?= h(t('billing.customer_credit')) ?></span>
                                </div>
                                <div class="metric-card">
                                    <strong data-customer-account-pending>0.00</strong>
                                    <span><?= h(t('billing.customer_pending')) ?></span>
                                </div>
                                <div class="grid-full d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-primary" data-customer-payment-toggle>
                                        <?= h(t('billing.mark_paid')) ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" data-billing-item-toggle>
                                        <?= h(t('billing.add_item')) ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" data-local-user-billing-run>
                                        <?= h(t('billing.run_user')) ?>
                                    </button>
                                </div>
                            </div>
                            <div class="billing-form-grid mb-3" data-customer-payment-panel hidden>
                                <label class="form-label">
                                    <?= h(t('billing.payment_amount')) ?>
                                    <input class="form-control" type="number" step="0.01" data-payment-amount>
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.payment_date')) ?>
                                    <input class="form-control" type="date" value="<?= h(date('Y-m-d')) ?>" data-payment-date>
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.payment_reference')) ?>
                                    <input class="form-control" data-payment-reference>
                                </label>
                                <label class="form-label grid-full">
                                    <?= h(t('billing.payment_note')) ?>
                                    <textarea class="form-control" rows="3" data-payment-note></textarea>
                                </label>
                                <div class="grid-full">
                                    <button type="button" class="btn btn-primary" data-customer-payment-submit>
                                        <?= h(t('common.save')) ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-customer-payment-cancel>
                                        <?= h(t('common.cancel')) ?>
                                    </button>
                                </div>
                            </div>
                            <div class="billing-form-grid mb-3" data-billing-item-panel hidden>
                                <label class="form-label">
                                    <?= h(t('billing.item_description')) ?>
                                    <input class="form-control" name="billing_item_description">
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.net_amount')) ?>
                                    <input class="form-control" name="billing_item_amount" type="number" step="0.01">
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.tax_rate')) ?>
                                    <select class="form-select" name="billing_item_tax_rate_id">
                                        <option value=""><?= h(t('billing.no_tax')) ?></option>
                                        <?php foreach ($billingTaxRates as $tax): ?>
                                        <option value="<?= (int)$tax['id'] ?>">
                                            <?= h($tax['name'] . ' (' . $tax['rate_percent'] . '%)') ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.booking_date')) ?>
                                    <input class="form-control" name="billing_item_booking_date" type="date" value="<?= h(date('Y-m-d')) ?>">
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.interval')) ?>
                                    <select class="form-select" name="billing_item_frequency">
                                        <option value="once"><?= h(t('billing.frequency_once')) ?></option>
                                        <option value="monthly"><?= h(t('billing.frequency_monthly')) ?></option>
                                        <option value="bimonthly"><?= h(t('billing.frequency_bimonthly')) ?></option>
                                        <option value="quarterly"><?= h(t('billing.frequency_quarterly')) ?></option>
                                        <option value="halfyearly"><?= h(t('billing.frequency_semiannual')) ?></option>
                                        <option value="yearly"><?= h(t('billing.frequency_yearly')) ?></option>
                                    </select>
                                </label>
                                <label class="form-check billing-check">
                                    <input class="form-check-input" type="checkbox" name="billing_item_active" value="1" checked>
                                    <?= h(t('server.active')) ?>
                                </label>
                                <div class="grid-full">
                                    <button type="button" class="btn btn-primary" data-billing-item-submit>
                                        <?= h(t('common.save')) ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-billing-item-cancel>
                                        <?= h(t('common.cancel')) ?>
                                    </button>
                                </div>
                            </div>
                            <div class="billing-existing-items" data-billing-existing-items></div>
                            <div class="billing-existing-items" data-customer-account-entries></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('common.cancel')) ?></button>
                    <button type="submit" class="btn btn-primary icon-only status-tooltip" <?= icon_button_attrs(t('common.save')) ?>>
                        <?= icon_svg('save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
