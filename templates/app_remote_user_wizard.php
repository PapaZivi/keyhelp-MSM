<?php
$activeServers = array_values(array_filter($servers ?? [], static fn(array $server): bool => (int)($server['active'] ?? 0) === 1));
$singleServer = count($activeServers) === 1 ? $activeServers[0] : null;
?>
<div class="modal fade" id="remoteUserWizardModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form class="ajax-remote-user-wizard-form" data-mode="create" novalidate>
                <div class="modal-header">
                    <h2 class="modal-title h5"><?= h(t('users.create_remote_user')) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="_action" value="create_user">
                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <input type="hidden" name="local_user_id" data-wizard-local-user-id>
                    <input type="hidden" name="user_id">
                    <input type="hidden" name="language" value="<?= h(current_locale()) ?>">
                    <?php if ($singleServer): ?>
                    <input type="hidden" name="server_id" value="<?= (int)$singleServer['id'] ?>" data-wizard-server-id>
                    <?php endif; ?>
                    <input type="hidden" name="first_name">
                    <input type="hidden" name="last_name">
                    <input type="hidden" name="company">
                    <input type="hidden" name="phone">
                    <input type="hidden" name="address">
                    <input type="hidden" name="postcode">
                    <input type="hidden" name="city">
                    <input type="hidden" name="region">
                    <input type="hidden" name="country">
                    <input type="hidden" name="customer_number">
                    <input type="hidden" name="notes">

                    <div class="wizard-steps">
                        <?php if (count($activeServers) > 1): ?>
                        <section class="wizard-step" data-wizard-step="server">
                            <h3 class="h6"><?= h(t('users.wizard_server_title')) ?></h3>
                            <div class="server-choice-list">
                                <?php foreach ($activeServers as $server): ?>
                                <label class="server-choice">
                                    <input class="form-check-input" type="radio" name="server_id" value="<?= (int)$server['id'] ?>" data-wizard-server-option>
                                    <span><?= h($server['name'] ?? '') ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>

                        <section class="wizard-step" data-wizard-step="contact">
                            <h3 class="h6"><?= h(t('users.wizard_contact_title')) ?></h3>
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="inherit_local_contact" value="1" checked data-wizard-inherit-contact>
                                <?= h(t('users.wizard_inherit_contact')) ?>
                            </label>
                            <div class="wizard-contact-preview" data-wizard-contact-preview></div>
                        </section>

                        <section class="wizard-step" data-wizard-step="plan">
                            <h3 class="h6"><?= h(t('users.wizard_plan_title')) ?></h3>
                            <div class="billing-form-grid">
                                <label class="form-label">
                                    <?= h(t('users.username')) ?>
                                    <div class="input-group">
                                        <input class="form-control" name="username" required data-username-input>
                                        <button
                                            class="btn btn-link icon-only status-tooltip text-decoration-none"
                                            type="button"
                                            data-username-suggest
                                            <?= icon_button_attrs(t('users.suggest_username')) ?>
                                        >
                                            <?= icon_svg('refresh') ?>
                                        </button>
                                    </div>
                                    <small class="form-text" data-username-status></small>
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.email')) ?>
                                    <input class="form-control" name="email" type="email" required>
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.password')) ?>
                                    <div class="input-group">
                                        <input class="form-control" name="password" type="password" autocomplete="new-password" required>
                                        <button class="btn btn-link text-decoration-none" type="button" data-password-generate>
                                            <?= h(t('users.generate_password')) ?>
                                        </button>
                                    </div>
                                </label>
                                <label class="form-label">
                                    <?= h(t('users.password_confirm')) ?>
                                    <input class="form-control" name="password_confirmation" type="password" autocomplete="new-password" required>
                                </label>
                                <label class="form-label grid-full">
                                    <?= h(t('hosting.title')) ?>
                                    <select class="form-select" name="hosting_plan_id" data-hosting-plan-select>
                                        <option value="" data-server-id="" data-limits="{}"><?= h(t('users.custom_resources')) ?></option>
                                        <?php foreach ($packages as $package): ?>
                                        <?php $packageKeyhelpId = (string)($package['external_id'] ?? '') !== '' ? (string)$package['external_id'] : (string)$package['id']; ?>
                                        <option
                                            value="<?= h($packageKeyhelpId) ?>"
                                            data-server-id="<?= h((string)($package['server_id'] ?? '')) ?>"
                                            data-limits="<?= h((string)($package['limits_json'] ?? '{}')) ?>"
                                        >
                                            <?= h($package['name']) ?><?= $package['server_name'] ? ' (' . h($package['server_name']) . ')' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="visually-hidden">
                                <?php $renderResourceFields($resourceFields); ?>
                                <?php $renderPermissionFields($permissionFields); ?>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link text-decoration-none" data-wizard-prev>
                        <?= h(t('common.back')) ?>
                    </button>
                    <button type="button" class="btn btn-link text-decoration-none" data-wizard-next>
                        <?= h(t('common.next')) ?>
                    </button>
                    <button type="submit" class="btn btn-link icon-only status-tooltip text-decoration-none" <?= icon_button_attrs(t('common.save')) ?> data-wizard-submit>
                        <?= icon_svg('save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
