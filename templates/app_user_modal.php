<div class="modal fade" id="userCreateModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form class="ajax-user-create-form" data-mode="create" novalidate>
                <div class="modal-header"><h2 class="modal-title h5"><?= h(t('users.create_title')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="_action" value="create_user"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input type="hidden" name="server_id" data-user-create-server-id><input type="hidden" name="user_id" data-user-create-local-id>
                    <p class="text-warning fw-bold" data-user-create-server-label></p>
                    <ul class="nav nav-tabs user-create-tabs" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#user-tab-general" type="button" role="tab"><?= h(t('users.tab_general')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-contact" type="button" role="tab"><?= h(t('users.tab_contact')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-resources" type="button" role="tab"><?= h(t('users.tab_resources')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-permissions" type="button" role="tab"><?= h(t('users.tab_permissions')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-php" type="button" role="tab"><?= h(t('users.tab_php')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-php-fpm" type="button" role="tab"><?= h(t('users.tab_php_fpm')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-advanced" type="button" role="tab"><?= h(t('users.tab_advanced')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#user-tab-billing" type="button" role="tab"><?= h(t('users.tab_billing')) ?></button></li>
                    </ul>
                    <div class="tab-content user-create-body">
                        <div class="tab-pane fade show active" id="user-tab-general" role="tabpanel">
                            <label class="form-label"><?= h(t('users.username')) ?><div class="input-group"><input class="form-control" name="username" required data-username-input><button class="btn btn-outline-secondary icon-only status-tooltip" type="button" data-username-suggest<?= icon_button_attrs(t('users.suggest_username')) ?>><?= icon_svg('refresh') ?></button></div><small class="form-text" data-username-status></small></label>
                            <label class="form-label"><?= h(t('common.language')) ?><select class="form-select" name="language"><option value="de">Deutsch - German</option><option value="en">English</option><option value="es">Español - Spanish</option><option value="fr">Français - French</option><option value="it">Italiano - Italian</option><option value="nl">Nederlands - Dutch</option><option value="pl">Polski - Polish</option><option value="pt">Português - Portuguese</option><option value="sv">Svenska - Swedish</option><option value="tr">Türkçe - Turkish</option><option value="ru">Russian - Russian</option><option value="ar">Arabic - Arabic</option><option value="zh_CN">Chinese simplified</option><option value="zh_TW">Chinese traditional</option></select></label>
                            <label class="form-label"><?= h(t('users.email')) ?><input class="form-control" name="email" type="email" required></label>
                            <label class="form-label"><?= h(t('users.password')) ?><div class="input-group"><input class="form-control" name="password" type="password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-generate><?= h(t('users.generate_password')) ?></button></div></label>
                            <label class="form-label"><?= h(t('users.password_confirm')) ?><input class="form-control" name="password_confirmation" type="password" autocomplete="new-password" required></label>
                            <label class="form-label"><?= h(t('users.notes')) ?><textarea class="form-control" name="notes" rows="3"></textarea></label>
                        </div>
                        <div class="tab-pane fade" id="user-tab-contact" role="tabpanel"><div class="billing-form-grid"><label class="form-label"><?= h(t('users.first_name')) ?><input class="form-control" name="first_name"></label><label class="form-label"><?= h(t('users.last_name')) ?><input class="form-control" name="last_name"></label><label class="form-label"><?= h(t('users.company')) ?><input class="form-control" name="company"></label><label class="form-label"><?= h(t('users.phone')) ?><input class="form-control" name="phone"></label><label class="form-label grid-full"><?= h(t('users.address')) ?><textarea class="form-control" name="address" rows="3"></textarea></label><label class="form-label"><?= h(t('users.postcode')) ?><input class="form-control" name="postcode"></label><label class="form-label"><?= h(t('users.city')) ?><input class="form-control" name="city"></label><label class="form-label"><?= h(t('users.region')) ?><input class="form-control" name="region"></label><label class="form-label"><?= h(t('users.country')) ?><input class="form-control" name="country"></label><label class="form-label"><?= h(t('users.customer_number')) ?><input class="form-control" name="customer_number"></label></div></div>
                        <div class="tab-pane fade" id="user-tab-resources" role="tabpanel">
                            <label class="form-label"><?= h(t('hosting.title')) ?><select class="form-select" name="hosting_plan_id" data-hosting-plan-select><option value="" data-server-id="" data-limits="{}"><?= h(t('users.custom_resources')) ?></option><?php foreach ($packages as $package): ?><?php $packageKeyhelpId = (string)($package['external_id'] ?? '') !== '' ? (string)$package['external_id'] : (string)$package['id']; ?><option value="<?= h($packageKeyhelpId) ?>" data-server-id="<?= h((string)($package['server_id'] ?? '')) ?>" data-limits="<?= h((string)($package['limits_json'] ?? '{}')) ?>"><?= h($package['name']) ?><?= $package['server_name'] ? ' (' . h($package['server_name']) . ')' : '' ?></option><?php endforeach; ?></select></label>
                            <?php $renderResourceFields($resourceFields); ?>
                        </div>
                        <div class="tab-pane fade" id="user-tab-permissions" role="tabpanel"><?php $renderPermissionFields($permissionFields); ?></div>
                        <div class="tab-pane fade" id="user-tab-php" role="tabpanel" data-api-readonly><?php $renderPhpFields(true); ?></div>
                        <div class="tab-pane fade" id="user-tab-php-fpm" role="tabpanel" data-api-readonly><label class="form-label"><?= h(t('users.php_fpm_pm')) ?><select class="form-select" name="php_fpm_pm" data-api-readonly-control><option value="static">static</option><option value="ondemand" selected>ondemand</option><option value="dynamic">dynamic</option></select></label><label class="form-label"><?= h(t('users.php_fpm_max_children')) ?><input class="form-control" name="php_fpm_max_children" type="number" value="3" data-api-readonly-control></label><label class="form-label"><?= h(t('users.php_fpm_max_requests')) ?><input class="form-control" name="php_fpm_max_requests" type="number" value="0" data-api-readonly-control></label><label class="form-check"><input class="form-check-input" type="checkbox" name="php_fpm_status_enabled" value="1" data-api-readonly-control> <?= h(t('users.php_fpm_status')) ?></label><label class="form-label"><?= h(t('users.php_fpm_status_ips')) ?><input class="form-control" name="php_fpm_status_ips" data-api-readonly-control></label></div>
                        <div class="tab-pane fade" id="user-tab-advanced" role="tabpanel"><label class="form-check"><input class="form-check-input" type="checkbox" name="account_locked" value="1"> <?= h(t('users.account_locked')) ?></label><label class="form-label"><?= h(t('users.lock_on')) ?><input class="form-control" name="lock_on" type="date"></label><label class="form-label"><?= h(t('users.delete_on')) ?><input class="form-control" name="delete_on" type="date"></label></div>
                        <div class="tab-pane fade" id="user-tab-billing" role="tabpanel">
                            <input type="hidden" name="billing_user_id" data-billing-user-id>
                            <input type="hidden" name="billing_item_allow_past_booking_date" value="0" data-billing-allow-past-date>
                            <p class="billing-muted" data-billing-create-note>
                                <?= h(t('billing.user_after_create_note')) ?>
                            </p>
                            <div class="billing-form-grid">
                                <label class="form-label">
                                    <?= h(t('billing.user_discount')) ?>
                                    <input class="form-control" name="billing_discount_percent" type="number" step="0.001" min="0" max="100" value="0">
                                </label>
                                <label class="form-label">
                                    <?= h(t('billing.invoice_frequency')) ?>
                                    <select class="form-select" name="billing_invoice_frequency">
                                        <option value="instant"><?= h(t('billing.frequency_instant')) ?></option>
                                        <option value="weekly"><?= h(t('billing.frequency_weekly')) ?></option>
                                        <option value="monthly" selected><?= h(t('billing.frequency_monthly')) ?></option>
                                    </select>
                                </label>
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
                            </div>
                            <div class="billing-existing-items" data-billing-existing-items></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('common.cancel')) ?></button><button type="submit" class="btn btn-primary icon-only status-tooltip"<?= icon_button_attrs(t('common.save')) ?>><?= icon_svg('save') ?></button></div>
            </form>
        </div>
    </div>
</div>
