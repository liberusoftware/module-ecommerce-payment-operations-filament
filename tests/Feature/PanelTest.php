<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\PaymentOperations\Filament\PaymentOperationsPlugin;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\RelationManagers\EntriesRelationManager;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource\Pages\ListProviderCallbacks;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Livewire\Livewire;

it('contributes two resources over four tables, and lists the two it will not', function () {
    $plugin = PaymentOperationsPlugin::make();

    expect($plugin->getId())->toBe('liberu-ecommerce-payment-operations');

    // The ledger is a relation manager on the payment it belongs to, because an
    // entry on its own is a contribution to a fold with no total. Saved
    // instruments are not surfaced at all: the only ability beyond reading that
    // their policy publishes is `detachInstrument`, and the domain publishes no
    // action that performs it.
    expect(array_values(Filament::getPanel('admin')->getResources()))
        ->toHaveCount(2)
        ->toContain(PaymentResource::class)
        ->toContain(ProviderCallbackResource::class);
});

it('lists a payment with what the ledger folds to rather than a stored status', function () {
    $this->actorForTeam(TEAM);

    $payment = captured(aPayment(minor: 5_000), minor: 2_000);

    // There is no `status` column behind any of these. Every one is a sum over
    // the entries, computed as the row was drawn.
    expect(Schema::hasColumn('ecommerce_payment_payments', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('ecommerce_payment_payments', 'captured_minor'))->toBeFalse();

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->assertTableColumnFormattedStateSet('status', 'Partly taken', $payment)
        ->assertTableColumnStateSet('amount', 'GBP 50.00', $payment)
        ->assertTableColumnStateSet('captured', 'GBP 20.00', $payment)
        ->assertTableColumnStateSet('refunded', 'GBP 0.00', $payment);
});

it('walks from a payment to the ledger every number on the page came from', function () {
    $this->actorForTeam(TEAM);

    $payment = refunded(captured(aPayment(minor: 5_000)), minor: 1_000);

    Livewire::test(ViewPayment::class, ['record' => $payment->reference])
        ->assertOk()
        ->assertSee($payment->reference)
        ->assertSee('Partly given back')
        // The fold, spelled out. Reserved 50, taken 50, given back 10, so 40 can
        // still go back and nothing further can be taken.
        ->assertSee('GBP 40.00')
        ->assertSee('None of these is stored');

    Livewire::test(EntriesRelationManager::class, [
        'ownerRecord' => $payment,
        'pageClass' => ViewPayment::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($payment->entries)
        ->assertSee('Reserved')
        ->assertSee('Taken')
        ->assertSee('Given back');

    expect($payment->entries)->toHaveCount(3);
});

it('offers no page anybody could create, edit or delete a payment from', function () {
    // A create page or an edit page appearing here would be the first sign that
    // somebody scaffolded their way past every refusal in the resource — and an
    // edit page is where a status field comes from.
    expect(array_keys(PaymentResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(ProviderCallbackResource::getPages()))->toBe(['index']);
});

it('puts the payment\'s public reference in the URL rather than its id', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    // An incrementing id in a URL enumerates everybody else's payments, and a URL
    // is the part of a page that gets pasted into a support ticket. `Payment` does
    // not override `getRouteKeyName()`, so route generation would fall back to the
    // id without the explicit binding field on the view route.
    expect(PaymentResource::getRecordRouteKeyName())->toBe('reference')
        ->and($payment->getRouteKey())->toBe($payment->id)
        ->and($payment->reference)->toStartWith('PAY-')
        ->and(PaymentResource::getUrl('view', ['record' => $payment]))->toContain($payment->reference);
});

it('shows every tender against one order, because there is no unique key stopping a second', function () {
    $this->actorForTeam(TEAM);

    // A card and a gift card against one order are two payments. The domain has no
    // unique key on `order_id` precisely so this is representable, and a panel that
    // assumed one payment per order would have made the decision decorative.
    $card = aPayment(minor: 3_000);
    $voucher = aPayment(minor: 2_000);

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$card, $voucher]);

    expect(Payment::query()->where('order_id', GHOST_ORDER)->count())->toBe(2);
});

it('lists what a provider delivered, and what this module did about each', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    aCallback('evt-known-1', 'payment.captured', $payment->provider_reference, 5_000);
    // A type the adapter does not map, about a payment we do know. Recorded,
    // answered 2xx, and somebody should look at it.
    aCallback('evt-odd-1', 'payment.disputed', $payment->provider_reference);

    Livewire::test(ListProviderCallbacks::class)
        ->assertOk()
        ->assertSee('Applied to a payment')
        ->assertSee('Event type not mapped')
        ->assertSee('evt-odd-1')
        ->assertSee($payment->reference);
});

it('runs with no module that owns orders or customers installed', function () {
    $this->actorForTeam(TEAM);

    // The boundary this package inherits and does not weaken. The order id and the
    // customer id on every row here name nothing in this database, and no table of
    // either exists to name.
    expect(Schema::hasTable('ecommerce_orders_orders'))->toBeFalse()
        ->and(Schema::hasTable('orders'))->toBeFalse()
        ->and(Schema::hasTable('customers'))->toBeFalse();

    $payment = aPayment();

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$payment]);

    expect($payment->order_id)->toBe(GHOST_ORDER)
        ->and($payment->customer_id)->toBe(GHOST_CUSTOMER);
});
