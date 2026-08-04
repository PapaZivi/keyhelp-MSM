<div class="table-responsive mt-4" data-tld-prices-content>
    <table class="table billing-table">
        <thead>
            <tr>
                <th>TLD</th>
                <th><?= h(t('billing.registration_price')) ?></th>
                <th><?= h(t('billing.yearly_price')) ?></th>
                <th><?= h(t('billing.change_price')) ?></th>
                <th><?= h(t('billing.tax_rate')) ?></th>
                <th class="text-end"><?= h(t('common.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tldPrices as $price): ?>
            <tr data-tld-price-row data-tld-price-json="<?= h(json_encode($price, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                <td>.<?= h($price['tld']) ?></td>
                <td><?= h($price['registration_price']) ?></td>
                <td><?= h($price['yearly_price']) ?></td>
                <td><?= h($price['change_price']) ?></td>
                <td><?= h($price['tax_name'] ?? '') ?></td>
                <td class="text-end">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary icon-only status-tooltip tld-price-edit"
                        <?= icon_button_attrs(t('common.edit')) ?>
                    >
                        <?= icon_svg('edit') ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
