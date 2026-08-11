<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\PaymentOperations\Actions\CapturePayment;
use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Data\MovementInput;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryOrigin;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Liberu\Ecommerce\PaymentOperations\Filament\Tests\Fixtures\RefusingGateway;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentEntry;
use Livewire\Livewire;

// The write surface, which is three movements and nothing else. Every test here is
// about a row being appended, because that is the only kind of write this package
// can cause.

it('takes everything reserved, and does it by appending a row', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment))
        ->assertNotified('Taken');

    $payment->refresh()->load('entries');

    // Two rows: the authorization and the capture. Nothing was edited — the
    // authorization row is untouched and the ledger is one row longer.
    expect($payment->entries)->toHaveCount(2)
        ->and($payment->state()->capturedMinor)->toBe(5_000)
        ->and($payment->state()->capturable()->minor)->toBe(0)
        ->and($payment->entries->last()->kind)->toBe(EntryKind::Captured)
        ->and($payment->entries->last()->origin)->toBe(EntryOrigin::Caller)
        // The key is derived from the payment and the ledger, never minted. This
        // is the string a second press would derive too, which is why a second
        // press cannot take the money twice.
        ->and($payment->entries->last()->entry_key)->toBe('panel:'.$payment->reference.':capture:5000GBP');
});

it('stops offering to take money the moment there is none left to take', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment))
        ->assertNotified('Taken');

    // The double click, handled by the edge ceasing to exist rather than by hope:
    // `PaymentPolicy::capture()` asks the ledger, and the ledger now says nothing
    // is capturable. The row still has one capture on it.
    Livewire::test(ListPayments::class)
        ->assertActionHidden(TestAction::make('capture')->table($payment->refresh()));

    expect(PaymentEntry::query()->where('payment_id', $payment->id)->where('kind', EntryKind::Captured->value)->count())->toBe(1);
});

it('reports a provider refusing as a refusal, and records it as an attempt', function () {
    config()->set('payment-operations.gateways.card.class', RefusingGateway::class);

    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment))
        // Not a green notification about money that never moved. A decline is
        // recorded rather than thrown, so a surface checking only for an exception
        // would have called this a success.
        ->assertNotified('The provider refused it');

    $payment->refresh()->load('entries');

    expect($payment->entries)->toHaveCount(2)
        ->and($payment->entries->last()->kind)->toBe(EntryKind::Failed)
        // A refused movement moved nothing, so it contributes zero and the money
        // is still reserved.
        ->and($payment->entries->last()->amount_minor)->toBe(0)
        ->and($payment->state()->capturedMinor)->toBe(0)
        ->and($payment->state()->capturable()->minor)->toBe(5_000);
});

/**
 * The replay branch, reached the only way a panel genuinely can.
 *
 * A declined attempt under the key this button derives leaves every total where it
 * was, so the money is still capturable and the button is still offered — and the
 * key is taken. The domain replays it, writes nothing, announces nothing, and the
 * panel says so rather than showing a success for a movement that did not happen.
 */
it('says nothing was written when the key it derives has already recorded the same movement', function () {
    config()->set('payment-operations.gateways.card.class', RefusingGateway::class);

    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    (new CapturePayment())->handle(new MovementInput(
        paymentReference: $payment->reference,
        entryKey: 'panel:'.$payment->reference.':capture:5000GBP',
        amount: new Money(5_000, 'GBP'),
    ));

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment->refresh()))
        ->assertNotified('Already recorded');

    expect(PaymentEntry::query()->where('payment_id', $payment->id)->count())->toBe(2);
});

/**
 * And the conflict branch, told apart by class rather than by reading a message.
 *
 * `PaymentConflict` is permanent and `PaymentInFlight` is transient — opposite
 * instructions to whoever is standing at the screen — which is why the domain ships
 * two classes and why this package catches them separately.
 */
it('says retrying will not help when the same key already recorded different facts', function () {
    config()->set('payment-operations.gateways.card.class', RefusingGateway::class);

    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    (new CapturePayment())->handle(new MovementInput(
        paymentReference: $payment->reference,
        entryKey: 'panel:'.$payment->reference.':capture:5000GBP',
        // A different amount under the same key. The button will derive that key
        // with 5000 in the facts, and the hashes will not match.
        amount: new Money(3_000, 'GBP'),
    ));

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment->refresh()))
        ->assertNotified('That instruction conflicts with one already recorded');

    expect(PaymentEntry::query()->where('payment_id', $payment->id)->count())->toBe(2);
});

it('offers releasing the reservation until money is taken, and then refuses in the domain\'s words', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    Livewire::test(ViewPayment::class, ['record' => $payment->reference])
        ->assertOk()
        ->assertSee('Available. GBP 50.00 is reserved')
        ->callAction(TestAction::make('void'))
        ->assertNotified('Reservation released');

    $payment->refresh()->load('entries');

    expect($payment->state()->voided)->toBeTrue()
        ->and($payment->state()->capturedMinor)->toBe(0)
        ->and($payment->entries->last()->entry_key)->toBe('panel:'.$payment->reference.':void');

    // The other half: once anything is taken there is no reservation to release,
    // and the page says why rather than leaving a missing button to be interpreted.
    $taken = captured(aPayment(orderId: 9_000_502, minor: 4_000));

    Livewire::test(ViewPayment::class, ['record' => $taken->reference])
        ->assertOk()
        ->assertActionHidden(TestAction::make('void'))
        ->assertSee('there is no reservation left to release')
        ->assertSee('the customer sees it on their statement');
});

it('gives back everything refundable, and leaves the capture it reverses standing', function () {
    $this->actorForTeam(TEAM);

    $payment = captured(aPayment(minor: 5_000), minor: 3_000);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('refund')->table($payment))
        ->assertNotified('Given back');

    $payment->refresh()->load('entries');

    expect($payment->state()->capturedMinor)->toBe(3_000)
        ->and($payment->state()->refundedMinor)->toBe(3_000)
        ->and($payment->state()->refundable()->minor)->toBe(0)
        // Three rows and none of them changed: authorized, captured, refunded.
        // Both facts survive, which is what a customer's statement will say too.
        ->and($payment->entries)->toHaveCount(3)
        ->and($payment->entries->last()->entry_key)->toBe('panel:'.$payment->reference.':refund:3000GBP');
});

it('records who pressed the button, when the host\'s ids are numbers', function () {
    $actor = $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment));

    // A refund is a person taking money out of the business, and the domain keeps
    // a column for which person. `PanelActor::id()` guards with `is_numeric`, so a
    // host on ULIDs records nobody rather than recording user 1.
    expect($payment->refresh()->load('entries')->entries->last()->recorded_by)->toBe($actor->getKey());
});

it('surfaces a misconfigured gateway rather than a stack trace', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    // The class the deployment named has gone — a config change, a renamed
    // adapter. The domain refuses to guess and this package repeats it.
    config()->set('payment-operations.gateways.card', []);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment))
        ->assertNotified('Nothing moved');

    expect($payment->refresh()->load('entries')->entries)->toHaveCount(1);
});

it('names the movements for what pressing them does, not for the state they produce', function () {
    // "Captured" is a state; "Take the money" is what the button does. Every one of
    // them also carries an icon, and an icon button with no accessible name is a
    // control a screen reader announces as "button".
    $labels = [];

    foreach (PaymentResource::movementActions() as $action) {
        expect($action->getIcon())->not->toBeNull($action->getName());

        $labels[] = (string) $action->getLabel();
    }

    expect($labels)->toBe(['Take the money', 'Release the reservation', 'Give the money back']);
});
