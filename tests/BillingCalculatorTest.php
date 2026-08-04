<?php
require dirname(__DIR__) . '/src/BillingService.php';

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ' failed: expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSameValue(1000, BillingService::moneyToCents('10.00'), 'moneyToCents regular');
assertSameValue(1001, BillingService::moneyToCents('10.005'), 'moneyToCents rounding');
assertSameValue('15.99', BillingService::centsToMoney(1599), 'centsToMoney');
[$net, $discount] = BillingService::applyDiscounts(1000, 10.0);
assertSameValue(900, $net, 'domain discount');
[$net, $discount] = BillingService::applyDiscounts(1500, 10.0, 20.0);
assertSameValue(1080, $net, 'domain plus user discount');
assertSameValue(205, BillingService::taxForNet(1080, 19.0), 'tax rounding');
[$net, $discount] = BillingService::applyDiscounts(1000, 120.0);
assertSameValue(0, $net, 'discount never negative');

echo "Billing calculator tests passed" . PHP_EOL;