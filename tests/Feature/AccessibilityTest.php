<?php

use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Data\Settlement;
use Liberu\Ecommerce\PaymentOperations\Enums\CallbackStatus;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryOrigin;
use Liberu\Ecommerce\PaymentOperations\Enums\PaymentStatus;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\RelationManagers\EntriesRelationManager;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource\Pages\ListProviderCallbacks;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Amount;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Wording;
use Livewire\Livewire;

// What a keyboard and a screen reader get out of these surfaces. The two things
// worth a test are the two that regress silently: a state rendered as colour with
// no words, and a number rendered by dividing.

it('says what state a payment is in, in words, rather than in a badge colour', function () {
    $this->actorForTeam(TEAM);

    $reserved = aPayment(orderId: 9_000_701, minor: 5_000);
    $partly = captured(aPayment(orderId: 9_000_702, minor: 5_000), minor: 2_000);
    $taken = captured(aPayment(orderId: 9_000_703, minor: 5_000));

    // Amber and grey are the same badge to a screen reader and to anybody who
    // cannot separate the two. "Reserved, not taken" is also what `authorized`
    // actually means to somebody who has to decide what to do next, which
    // "Authorized" does not say.
    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertTableColumnFormattedStateSet('status', 'Reserved, not taken', $reserved)
        ->assertTableColumnFormattedStateSet('status', 'Partly taken', $partly)
        ->assertTableColumnFormattedStateSet('status', 'Taken', $taken);
});

it('has a word for every state, kind, origin and callback outcome the domain has', function () {
    // No `default` arm anywhere in `Wording`, deliberately: a new case arriving in
    // a later release of the domain raises `UnhandledMatchError` on the first
    // render rather than showing a state with no name. This is the test that makes
    // the build find out instead of an operator.
    foreach (PaymentStatus::cases() as $status) {
        expect(Wording::status($status))->not->toBeEmpty()
            ->and(Wording::statusColour($status))->not->toBeEmpty();
    }

    foreach (EntryKind::cases() as $kind) {
        expect(Wording::kind($kind))->not->toBeEmpty()
            ->and(Wording::kindColour($kind))->not->toBeEmpty();
    }

    foreach (EntryOrigin::cases() as $origin) {
        expect(Wording::origin($origin))->not->toBeEmpty();
    }

    foreach (CallbackStatus::cases() as $status) {
        expect(Wording::callbackStatus($status))->not->toBeEmpty()
            ->and(Wording::callbackColour($status))->not->toBeEmpty();
    }

    expect(Wording::statusOptions())->toHaveCount(count(PaymentStatus::cases()))
        ->and(Wording::callbackOptions())->toHaveCount(count(CallbackStatus::cases()));
});

it('renders money from the integer without ever dividing', function () {
    // `(int) (19.99 * 100)` is 1998 and `1999 / 100` is where the penny goes. The
    // conversion lives in the domain's `Money` and this package formats what it
    // returns rather than writing a second copy that agrees on nearly every value.
    expect(Amount::format(new Money(1_999, 'GBP')))->toBe('GBP 19.99')
        ->and(Amount::format(new Money(5, 'GBP')))->toBe('GBP 0.05')
        ->and(Amount::format(new Money(0, 'GBP')))->toBe('GBP 0.00')
        // The exponent travels inside the amount rather than being assumed to be
        // two: a zero-exponent currency rendered as 1999 → 19.99 has divided
        // somebody's charge by a hundred.
        ->and(Amount::format(new Money(1_999, 'JPY', 0)))->toBe('JPY 1999')
        ->and(Amount::format(new Money(1_999, 'BHD', 3)))->toBe('BHD 1.999')
        // Prefixed with the ISO code and not a symbol: a panel serving several
        // merchants shows several currencies in one column, and two symbols do not
        // tell a screen reader which is which.
        ->and(Amount::of(null))->toBeNull();
});

it('shows a settlement beside the charge and never instead of it', function () {
    // Recorded, exported, reported — never converted, never summed, never used to
    // check an invariant. The rate is a string the whole way, because a rate has
    // more decimal places than a float holds exactly.
    expect(Amount::settlement(new Settlement(new Money(2_310, 'EUR'), '1.1550')))
        ->toBe('EUR 23.10 at 1.1550')
        ->and(Amount::settlement(null))->toBeNull();
});

it('heads its computed columns with words rather than with attribute names', function () {
    $this->actorForTeam(TEAM);

    aPayment();

    // Filament humanises a column name into a heading when none is given, which is
    // how `amount_minor` becomes "Amount minor" and `order_id` becomes "Order id".
    // Neither is what the column holds, and the label is the only thing keeping
    // them out.
    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertSee('Raised for')
        ->assertSee('Taken')
        ->assertSee('Reconciliation')
        ->assertDontSee('Amount minor')
        ->assertDontSee('Order id');
});

it('states on each list what the surface will and will not let anybody do', function () {
    $this->actorForTeam(TEAM);

    aPayment();

    // Refusals inferred from missing buttons are a puzzle. Saying it is one
    // sentence, and this is the one people arrive asking about: where is the field
    // that sets a payment to paid.
    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertSee('has no status field')
        ->assertSee('every figure is a sum over the ledger');

    Livewire::test(ListProviderCallbacks::class)
        ->assertOk()
        ->assertSee('Nothing here can be edited, deleted or replayed');
});

it('says which movements are available from here, and what is left to move', function () {
    $this->actorForTeam(TEAM);

    $reserved = aPayment(orderId: 9_000_711, minor: 5_000);

    expect(PaymentResource::nextMovements($reserved))->toBe([
        'Take the money — GBP 50.00 is still reserved.',
        'Release the reservation — nothing has been taken yet.',
    ]);

    // And when there is nothing left, an empty header is not left to be
    // interpreted — the page says so, and says there is no field to set instead.
    $finished = refunded(captured(aPayment(orderId: 9_000_712, minor: 5_000)));

    expect(PaymentResource::nextMovements($finished))
        ->toBe(['None. This payment has nothing left to move, and there is no field on it anybody may set instead.']);

    Livewire::test(ViewPayment::class, ['record' => $finished->reference])
        ->assertOk()
        ->assertSee('there is no field on it anybody may set instead');
});

it('says a settlement is in the same currency rather than leaving the cell empty', function () {
    $this->actorForTeam(TEAM);

    $payment = captured(aPayment());

    // Zero, "not applicable" and "nobody told us" are three different facts, and an
    // empty cell cannot be told apart from a column that failed to render.
    Livewire::test(EntriesRelationManager::class, [
        'ownerRecord' => $payment,
        'pageClass' => ViewPayment::class,
    ])
        ->assertOk()
        // The ordinary case: the provider settled in the currency the customer was
        // charged in, so there is nothing to say and the column says that.
        ->assertSee('Same currency');

    Livewire::test(ListPayments::class)
        ->assertOk()
        // A healthy ledger has an empty reconciliation cell, which is precisely the
        // cell somebody would read as "not checked".
        ->assertSee('Adds up');
});
