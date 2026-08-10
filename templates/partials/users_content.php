<?php
$billingUserSettings = $billingUserSettings ?? [];
$billingUserItems = $billingUserItems ?? [];
$remoteUsersByLocalUserId = $remoteUsersByLocalUserId ?? [];
$domainsByLocalUserId = $domainsByLocalUserId ?? [];
$unassignedRemoteUsers = $unassignedRemoteUsers ?? [];
$localUserDeleteBlockers = $localUserDeleteBlockers ?? [];
$customerAccountBalances = $customerAccountBalances ?? [];
$customerAccountPendingTotals = $customerAccountPendingTotals ?? [];
$customerAccountEntries = $customerAccountEntries ?? [];
$localUserDeleteBlockerLabels = [
    'remote_users' => t('users.local_user_delete_has_remote_users'),
    'domains' => t('users.local_user_delete_has_domains'),
    'pending_billing' => t('users.local_user_delete_has_open_billing'),
    'open_invoices' => t('users.local_user_delete_has_open_invoices'),
    'invoices' => t('users.local_user_delete_has_invoices'),
    'customer_account' => t('users.local_user_delete_has_customer_account'),
];
?>
<div class="users-content" data-users-content>
    <section class="server-user-group">
        <div class="section-head">
            <h3><?= h(t('users.local_users')) ?></h3>
            <button
                type="button"
                class="btn btn-link icon-only status-tooltip"
                data-local-user-create-toggle
                <?= icon_button_attrs(t('users.create_local_user')) ?>
            >
                <?= icon_svg('person-plus') ?>
            </button>
        </div>
        <form method="post" class="billing-form-grid mb-3 ajax-local-user-create-form" data-local-user-create-panel hidden>
            <input type="hidden" name="_action" value="create_local_user">
            <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
            <label class="form-label">
                <?= h(t('users.local_user')) ?>
                <input class="form-control" name="display_name" required>
            </label>
            <label class="form-label">
                <?= h(t('users.email')) ?>
                <input class="form-control" name="email" type="email">
            </label>
            <button class="btn btn-primary" type="submit"><?= h(t('common.save')) ?></button>
        </form>
        <div class="table-responsive">
            <table class="table table-sm align-middle compact-table">
                <thead>
                    <tr>
                        <th><?= h(t('users.local_user')) ?></th>
                        <th><?= h(t('users.email')) ?></th>
                        <th><?= h(t('users.customer_number')) ?></th>
                        <th><?= h(t('billing.customer_balance')) ?></th>
                        <th><?= h(t('users.remote_users')) ?></th>
                        <th><?= h(t('nav.domains')) ?></th>
                        <th class="text-end"><?= h(t('common.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($localUsers ?? []) as $localUser): ?>
                    <?php
                    $localUserId = (int)$localUser['id'];
                    $assignedRemoteUsers = $remoteUsersByLocalUserId[$localUserId] ?? [];
                    $assignedDomains = $domainsByLocalUserId[$localUserId] ?? [];
                    $assignedDomains = array_map(static function (array $domain): array {
                        $domain['_row_class'] = domain_row_class($domain);
                        $domain['_status_html'] = domain_name_status_html($domain);
                        return $domain;
                    }, $assignedDomains);
                    $deleteBlockers = $localUserDeleteBlockers[$localUserId] ?? [];
                    $deleteReason = implode(' ', array_map(
                        static fn(string $blocker): string => $localUserDeleteBlockerLabels[$blocker] ?? '',
                        $deleteBlockers
                    ));
                    $localUserPayload = $localUser;
                    $localUserPayload['_billing'] = $billingUserSettings[$localUserId] ?? [
                        'discount_percent' => 0,
                        'invoice_frequency' => 'monthly',
                    ];
                    $localUserPayload['_billing_items'] = $billingUserItems[$localUserId] ?? [];
                    $localUserPayload['_remote_users'] = $assignedRemoteUsers;
                    $localUserPayload['_domains'] = $assignedDomains;
                    $localUserPayload['_account_balance'] = $customerAccountBalances[$localUserId] ?? '0.00';
                    $localUserPayload['_account_pending_total'] = $customerAccountPendingTotals[$localUserId] ?? '0.00';
                    $localUserPayload['_account_entries'] = $customerAccountEntries[$localUserId] ?? [];
                    $pendingTotal = (float)($customerAccountPendingTotals[$localUserId] ?? 0);
                    if ($pendingTotal <= 0) {
                        foreach ($localUserPayload['_account_entries'] as $accountEntry) {
                            $status = (string)($accountEntry['status'] ?? '');
                            if (
                                ($accountEntry['entry_type'] ?? '') === 'invoice'
                                && in_array($status, ['draft', 'pending_approval', 'failed'], true)
                            ) {
                                $pendingTotal += abs((float)($accountEntry['amount'] ?? 0));
                            }
                        }
                        $localUserPayload['_account_pending_total'] = number_format($pendingTotal, 2, '.', '');
                    }
                    ?>
                    <tr
                        data-local-user-row
                        data-local-user-id="<?= $localUserId ?>"
                        data-local-user-json="<?= h(json_encode($localUserPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    >
                        <td>
                            <?= h($localUser['username'] ?? '') ?>
                        </td>
                        <td>
                            <?= h($localUser['email'] ?? '') ?>
                        </td>
                        <td>
                            <?= h($localUser['customer_number'] ?? '') ?>
                        </td>
                        <td>
                            <div class="customer-balance-cell">
                                <span><?= h($customerAccountBalances[$localUserId] ?? '0.00') ?></span>
                            <?php if ($pendingTotal > 0): ?>
                                <span class="billing-muted customer-balance-pending">
                                    (<?= h(number_format($pendingTotal, 2, '.', '')) ?>)
                                </span>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?= count($assignedRemoteUsers) ?>
                        </td>
                        <td>
                            <?= count($assignedDomains) ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group">
                                <button
                                    type="button"
                                    class="btn btn-link icon-only status-tooltip local-user-edit-open"
                                    data-bs-toggle="modal"
                                    data-bs-target="#localUserModal"
                                    <?= icon_button_attrs(t('common.edit')) ?>
                                >
                                    <?= icon_svg('edit') ?>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link icon-only status-tooltip remote-user-wizard-open"
                                    data-bs-toggle="modal"
                                    data-bs-target="#remoteUserWizardModal"
                                    <?= icon_button_attrs(t('users.create_remote_user')) ?>
                                >
                                    <?= icon_svg('plus') ?>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link icon-only status-tooltip local-user-delete"
                                    data-local-user-id="<?= $localUserId ?>"
                                    data-local-user-name="<?= h($localUser['username'] ?? '') ?>"
                                    <?= icon_button_attrs($deleteReason !== '' ? $deleteReason : t('common.delete')) ?>
                                    <?= $deleteBlockers !== [] ? 'disabled' : '' ?>
                                >
                                    <?= icon_svg('trash') ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($localUsers)): ?>
                    <tr><td colspan="7" class="empty"><?= h(t('js.no_users_found')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($unassignedRemoteUsers): ?>
    <section class="server-user-group">
        <div class="section-head">
            <h3><?= h(t('users.unassigned_remote_users')) ?></h3>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle compact-table">
                <thead>
                    <tr>
                        <th><?= h(t('domains.server')) ?></th>
                        <th><?= h(t('js.user')) ?></th>
                        <th><?= h(t('users.email')) ?></th>
                        <th><?= h(t('users.local_user')) ?></th>
                        <th class="text-end"><?= h(t('common.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unassignedRemoteUsers as $remoteUser): ?>
                    <?php $remoteFormId = 'unassigned-remote-user-' . (int)$remoteUser['id']; ?>
                    <tr data-user-row data-user-id="<?= (int)$remoteUser['id'] ?>">
                        <td><?= h($remoteUser['server_name'] ?? '') ?></td>
                        <td>
                            <span class="name-with-status">
                                <?= h($remoteUser['username'] ?? '') ?>
                                <?= user_name_status_html($remoteUser) ?>
                                <?= user_status_html($remoteUser) ?>
                            </span>
                        </td>
                        <td><?= h($remoteUser['email'] ?? '') ?></td>
                        <td class="remote-assignment-cell" data-current-local-user-id="">
                            <button type="button" class="remote-assignment-display" data-remote-assignment-display>
                                <?= h(t('users.not_assigned')) ?>
                            </button>
                            <form id="<?= h($remoteFormId) ?>" method="post" class="remote-assignment-editor ajax-remote-assignment-form" hidden>
                                <input type="hidden" name="_action" value="assign_remote_user">
                                <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                <input type="hidden" name="remote_user_id" value="<?= (int)$remoteUser['id'] ?>">
                                <select class="form-select form-select-sm" name="local_user_id">
                                    <option value=""><?= h(t('users.not_assigned')) ?></option>
                                <?php foreach (($localUsers ?? []) as $localUser): ?>
                                    <option value="<?= (int)$localUser['id'] ?>">
                                        <?= h($localUser['username'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                                </select>
                                <button
                                    type="submit"
                                    class="btn btn-link icon-only status-tooltip"
                                    <?= icon_button_attrs(t('users.assign_local_user')) ?>
                                >
                                    <?= icon_svg('save') ?>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link icon-only status-tooltip remote-assignment-cancel"
                                    <?= icon_button_attrs(t('common.cancel')) ?>
                                >
                                    <?= icon_svg('x') ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group">
                                <button
                                    type="button"
                                    class="btn btn-link icon-only status-tooltip local-user-create-from-remote"
                                    data-remote-user-id="<?= (int)$remoteUser['id'] ?>"
                                    data-remote-user-name="<?= h($remoteUser['username'] ?? '') ?>"
                                    <?= icon_button_attrs(t('users.create_local_from_remote')) ?>
                                >
                                    <?= icon_svg('plus') ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <div class="section-head">
        <h2 class="h5 mb-0"><?= h(t('users.title')) ?></h2>
        <form method="post" class="ajax-user-import-form">
            <input type="hidden" name="_action" value="import_users">
            <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
            <button class="btn btn-primary"><?= h(t('users.import')) ?></button>
        </form>
    </div>
    <div class="users-result mt-3">
        <?php foreach ($userGroups as $group): ?>
        <section class="server-user-group">
            <div class="section-head">
                <h3><?= h($group['server']['name'] ?? t('domains.server')) ?></h3>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle compact-table">
                    <thead>
                        <tr>
                            <th><?= h(t('js.user')) ?></th>
                            <th><?= h(t('users.local_user')) ?></th>
                            <th><?= h(t('js.email')) ?></th>
                            <th><?= h(t('js.id')) ?></th>
                            <th class="text-end"><?= h(t('common.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($group['users']): ?>
                        <?php foreach ($group['users'] as $user): ?>
                        <?php
                        $billingUserId = (int)($user['local_user_id'] ?? 0);
                        $remoteUserId = (int)$user['id'];
                        $assignFormId = 'assign-remote-user-' . $remoteUserId;
                        $assignedLocalUserName = '';
                        foreach (($localUsers ?? []) as $localUserOption) {
                            if ((int)$localUserOption['id'] === $billingUserId) {
                                $assignedLocalUserName = (string)($localUserOption['username'] ?? '');
                                break;
                            }
                        }
                        $userPayload = json_decode((string)($user['raw_json'] ?? '{}'), true) ?: [];
                        $userPayload['local_user_id'] = $billingUserId;
                        $userPayload['_billing'] = $billingUserId > 0 && isset($billingUserSettings[$billingUserId]) ? $billingUserSettings[$billingUserId] : [
                            'discount_percent' => 0,
                            'invoice_frequency' => 'monthly',
                        ];
                        $userPayload['_billing_items'] = $billingUserId > 0 ? ($billingUserItems[$billingUserId] ?? []) : [];
                        ?>
                        <tr
                            class="<?= h(user_row_class($user)) ?>"
                            data-user-row
                            data-user-id="<?= (int)$user['id'] ?>"
                            data-user-server-id="<?= (int)$user['server_id'] ?>"
                            data-user-server-name="<?= h($user['server_name'] ?? ($group['server']['name'] ?? t('domains.server'))) ?>"
                            data-user-json="<?= h(json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                        >
                            <td>
                                <span class="name-with-status">
                                    <a href="#" class="user-login-link" data-user-login>
                                        <?= h($user['username'] ?? '') ?>
                                    </a>
                                    <?= user_name_status_html($user) ?>
                                    <?= user_status_html($user) ?>
                                </span>
                            </td>
                            <td class="remote-assignment-cell" data-current-local-user-id="<?= $billingUserId ?>">
                                <button type="button" class="remote-assignment-display" data-remote-assignment-display>
                                    <?= h($assignedLocalUserName !== '' ? $assignedLocalUserName : t('users.not_assigned')) ?>
                                </button>
                                <form id="<?= h($assignFormId) ?>" method="post" class="remote-assignment-editor ajax-remote-assignment-form" hidden>
                                    <input type="hidden" name="_action" value="assign_remote_user">
                                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                                    <input type="hidden" name="remote_user_id" value="<?= $remoteUserId ?>">
                                    <select class="form-select form-select-sm" name="local_user_id">
                                        <option value=""><?= h(t('users.not_assigned')) ?></option>
                                    <?php foreach (($localUsers ?? []) as $localUser): ?>
                                        <option value="<?= (int)$localUser['id'] ?>" <?= (int)$localUser['id'] === $billingUserId ? 'selected' : '' ?>>
                                            <?= h($localUser['username'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                    </select>
                                    <button
                                        type="submit"
                                        class="btn btn-link icon-only status-tooltip"
                                        <?= icon_button_attrs(t('users.assign_local_user')) ?>
                                    >
                                        <?= icon_svg('save') ?>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-link icon-only status-tooltip remote-assignment-cancel"
                                        <?= icon_button_attrs(t('common.cancel')) ?>
                                    >
                                        <?= icon_svg('x') ?>
                                    </button>
                                </form>
                            </td>
                            <td><?= h($user['email'] ?? '') ?></td>
                            <td><?= h($user['external_id'] ?? '') ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm user-row-actions" role="group">
                                    <button
                                        type="button"
                                        class="btn btn-link icon-only status-tooltip user-edit-open"
                                        data-bs-toggle="modal"
                                        data-bs-target="#userCreateModal"
                                        <?= icon_button_attrs(t('common.edit')) ?>
                                    >
                                        <?= icon_svg('edit') ?>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-link icon-only status-tooltip user-delete"
                                        <?= icon_button_attrs(t('common.delete')) ?>
                                    >
                                        <?= icon_svg('trash') ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="5" class="empty"><?= h(t('js.no_users_found')) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endforeach; ?>
        <?php if (!$userGroups): ?>
        <p class="empty"><?= h(t('js.no_active_servers')) ?></p>
        <?php endif; ?>
    </div>
</div>
