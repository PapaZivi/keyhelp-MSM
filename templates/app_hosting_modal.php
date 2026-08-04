<div class="modal fade" id="hostingPackageModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" class="hosting-package-form">
                <div class="modal-header"><h2 class="modal-title h5"><?= h(t('hosting.create_title')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="_action" value="add_package" data-package-action><input type="hidden" name="_return" value="<?= h($returnPath) ?>"><input type="hidden" name="id" data-package-id>
                    <ul class="nav nav-tabs user-create-tabs" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#package-tab-general" type="button" role="tab"><?= h(t('users.tab_general')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#package-tab-resources" type="button" role="tab"><?= h(t('users.tab_resources')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#package-tab-permissions" type="button" role="tab"><?= h(t('users.tab_permissions')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#package-tab-php" type="button" role="tab"><?= h(t('users.tab_php')) ?></button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#package-tab-php-fpm" type="button" role="tab"><?= h(t('users.tab_php_fpm')) ?></button></li>
                    </ul>
                    <div class="tab-content user-create-body">
                        <div class="tab-pane fade show active" id="package-tab-general" role="tabpanel">
                            <label class="form-label"><?= h(t('hosting.package_name')) ?><input class="form-control" name="name" required data-package-name></label>
                            <label class="form-label"><?= h(t('hosting.description')) ?><textarea class="form-control" name="description" rows="3" data-package-description></textarea></label>
                            <label class="form-label"><?= h(t('domains.server')) ?></label>
                            <div class="dropdown package-server-multiselect" data-package-server-select>
                                <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" data-package-server-summary><?= h(t('common.all_servers')) ?></button>
                                <div class="dropdown-menu p-2 w-100 package-server-menu">
                                    <label class="dropdown-item form-check"><input class="form-check-input me-2" type="checkbox" value="__all" data-package-server-all> <?= h(t('common.all_servers')) ?></label>
                                    <div class="dropdown-divider"></div>
                                    <?php foreach ($servers as $server): ?><label class="dropdown-item form-check"><input class="form-check-input me-2" type="checkbox" name="server_ids[]" value="<?= (int)$server['id'] ?>" data-package-server-option data-server-name="<?= h($server['name']) ?>"> <?= h($server['name']) ?></label><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="package-tab-resources" role="tabpanel"><?php $renderResourceFields($resourceFields); ?></div>
                        <div class="tab-pane fade" id="package-tab-permissions" role="tabpanel"><?php $renderPermissionFields($permissionFields); ?></div>
                        <div class="tab-pane fade" id="package-tab-php" role="tabpanel"><?php $renderPhpFields(false); ?></div>
                        <div class="tab-pane fade" id="package-tab-php-fpm" role="tabpanel"><label class="form-label"><?= h(t('users.php_fpm_pm')) ?><select class="form-select" name="php_fpm_pm"><option value="static">static</option><option value="ondemand" selected>ondemand</option><option value="dynamic">dynamic</option></select></label><label class="form-label"><?= h(t('users.php_fpm_max_children')) ?><input class="form-control" name="php_fpm_max_children" type="number" value="3"></label><label class="form-label"><?= h(t('users.php_fpm_max_requests')) ?><input class="form-control" name="php_fpm_max_requests" type="number" value="0"></label><label class="form-check"><input class="form-check-input" type="checkbox" name="php_fpm_status_enabled" value="1"> <?= h(t('users.php_fpm_status')) ?></label><label class="form-label"><?= h(t('users.php_fpm_status_ips')) ?><input class="form-control" name="php_fpm_status_ips"></label></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('common.cancel')) ?></button><button type="submit" class="btn btn-primary icon-only status-tooltip"<?= icon_button_attrs(t('common.save')) ?>><?= icon_svg('save') ?></button></div>
            </form>
        </div>
    </div>
</div>