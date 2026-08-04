<?php
$billingUserSettings = $billingUserSettings ?? [];
$billingUserItems = $billingUserItems ?? [];
?>
<div class="users-content" data-users-content>
    <div class="section-head">
        <h2 class="h5 mb-0"><?= h(t('users.title')) ?></h2>
        <form method="post" class="ajax-user-import-form"><input type="hidden" name="_action" value="import_users"><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><button class="btn btn-primary"><?= h(t('users.import')) ?></button></form>
    </div>
    <div class="users-result mt-3">
        <?php foreach ($userGroups as $group): ?>
        <section class="server-user-group">
            <div class="section-head">
                <h3><?= h($group['server']['name'] ?? t('domains.server')) ?></h3>
                <button type="button" class="btn btn-primary btn-sm user-create-open" data-server-id="<?= (int)($group['server']['id'] ?? 0) ?>" data-server-name="<?= h($group['server']['name'] ?? t('domains.server')) ?>" data-bs-toggle="modal" data-bs-target="#userCreateModal"><?= h(t('users.create_on_server')) ?></button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle compact-table">
                    <thead>
                        <tr>
                            <th><?= h(t('js.user')) ?></th>
                            <th><?= h(t('js.email')) ?></th>
                            <th><?= h(t('js.id')) ?></th>
                            <th><?= h(t('domains.deletion')) ?></th>
                            <th class="text-end"><?= h(t('common.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($group['users']): ?>
                        <?php foreach ($group['users'] as $user): ?>
                        <?php
                        $userPayload = json_decode((string)($user['raw_json'] ?? '{}'), true) ?: [];
                        $userPayload['_billing'] = $billingUserSettings[(int)$user['id']] ?? [
                            'discount_percent' => 0,
                            'invoice_frequency' => 'monthly',
                        ];
                        $userPayload['_billing_items'] = $billingUserItems[(int)$user['id']] ?? [];
                        ?>
                        <tr class="<?= h(user_row_class($user)) ?>" data-user-row data-user-id="<?= (int)$user['id'] ?>" data-user-server-id="<?= (int)$user['server_id'] ?>" data-user-server-name="<?= h($user['server_name'] ?? ($group['server']['name'] ?? t('domains.server'))) ?>" data-user-json="<?= h(json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <td><span class="name-with-status"><a href="#" class="user-login-link" data-user-login><?= h($user['username'] ?? '') ?></a><?= user_name_status_html($user) ?></span></td>
                            <td><?= h($user['email'] ?? '') ?></td>
                            <td><?= h($user['external_id'] ?? '') ?></td>
                            <td class="status-cell"><?= user_status_html($user) ?></td>
                            <td class="text-end"><div class="btn-group btn-group-sm user-row-actions" role="group"><button type="button" class="btn btn-outline-secondary icon-only status-tooltip user-edit-open" data-bs-toggle="modal" data-bs-target="#userCreateModal"<?= icon_button_attrs(t('common.edit')) ?>><?= icon_svg('edit') ?></button><button type="button" class="btn btn-outline-danger icon-only status-tooltip user-delete"<?= icon_button_attrs(t('common.delete')) ?>><?= icon_svg('trash') ?></button></div></td>
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
