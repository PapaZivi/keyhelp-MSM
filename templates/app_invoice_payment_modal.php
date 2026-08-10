<div class="modal fade" id="invoicePaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" class="invoice-payment-form">
                <div class="modal-header">
                    <h2 class="modal-title h5"><?= h(t('billing.payment_title')) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="_action" value="billing_customer_payment">
                    <input type="hidden" name="_return" value="<?= h($returnPath) ?>">
                    <input type="hidden" name="user_id" data-payment-user-id>
                    <p class="billing-muted" data-payment-invoice-label></p>
                    <label class="form-label">
                        <?= h(t('billing.payment_amount')) ?>
                        <input class="form-control" name="payment_amount" type="number" step="0.01" required>
                    </label>
                    <label class="form-label">
                        <?= h(t('billing.payment_date')) ?>
                        <input class="form-control" name="paid_at" type="date" required>
                    </label>
                    <label class="form-label">
                        <?= h(t('billing.payment_reference')) ?>
                        <input class="form-control" name="payment_reference">
                    </label>
                    <label class="form-label">
                        <?= h(t('billing.payment_note')) ?>
                        <textarea class="form-control" name="payment_note" rows="3"></textarea>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('common.cancel')) ?></button>
                    <button type="submit" class="btn btn-primary"><?= h(t('common.save')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
