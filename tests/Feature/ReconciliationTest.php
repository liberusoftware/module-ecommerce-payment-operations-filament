<?php

use Liberu\Ecommerce\PaymentOperations\Enums\CallbackStatus;
use Liberu\Ecommerce\PaymentOperations\Exceptions\CurrencyMismatch;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource\Pages\ListProviderCallbacks;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Models\ProviderCallback;
use Liberu\Ecommerce\PaymentOperations\Queries\PaymentQuery;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

// The two operator queues the domain publishes and the runbook says somebody has
// to read, plus the one question this module refuses to answer.

/**
 * An impossible ledger is only reachable through a provider-origin row, because
 * everything written on a caller's behalf is guarded. A provider reporting a
 * capture larger than the reservation is either a fact about our money or a bug in
 * theirs, and the domain records it either way.
 */
it('shows a ledger that does not add up, without correcting it', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment(minor: 5_000);

    aCallback('evt-over-1', 'payment.captured', $payment->provider_reference, 9_000);

    $payment->refresh()->load('entries');

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertTableColumnStateSet('reconciliation', 'Impossible ledger', $payment);

    Livewire::test(ViewPayment::class, ['record' => $payment->reference])
        ->assertOk()
        ->assertSee('More has been taken than was ever reserved')
        ->assertSee('Nothing is corrected automatically');

    // Not clamped. The totals still say the impossible thing, because a wrong
    // number nobody is told about is the worst outcome available here.
    expect($payment->state()->capturedMinor)->toBe(9_000)
        ->and($payment->state()->authorizedMinor)->toBe(5_000)
        ->and($payment->state()->needsReconciliation())->toBeTrue()
        // Capturable is floored at zero rather than reported negative, so an
        // over-captured payment admits no further captures — the safe direction.
        ->and($payment->state()->capturable()->minor)->toBe(0);
});

it('names the other two ways a ledger goes wrong', function () {
    $this->actorForTeam(TEAM);

    // Money back that never went out: a refund issued from the provider's own
    // dashboard against a capture we never recorded.
    $refundedTooMuch = aPayment(orderId: 9_000_511, minor: 5_000);
    aCallback('evt-back-1', 'payment.refunded', $refundedTooMuch->provider_reference, 2_000);

    // And an entry in a currency this payment is not in. The write path refuses
    // one; this is what happens if a migration, a restore or a second writer
    // produces one anyway.
    $wrongCurrency = aPayment(orderId: 9_000_512, minor: 5_000);
    aCallback('evt-eur-1', 'payment.captured', $wrongCurrency->provider_reference, 1_000, 'EUR');

    expect(PaymentResource::reconciliationNotes($refundedTooMuch->refresh()->load('entries'))[0])
        ->toContain('More has gone back than was ever taken')
        ->and(PaymentResource::reconciliationNotes($wrongCurrency->refresh()->load('entries'))[0])
        ->toContain('1 entry is in a currency this payment is not in')
        // Excluded from the totals rather than added to unlike units, which keeps
        // the fold total without making the totals lies.
        ->and($wrongCurrency->state()->capturedMinor)->toBe(0)
        ->and($wrongCurrency->state()->mismatchedCurrencyEntries)->toBe(1);
});

it('says so plainly when a ledger is fine', function () {
    $this->actorForTeam(TEAM);

    // An empty section, or a blank cell, cannot be told apart from a column that
    // failed to render.
    expect(PaymentResource::reconciliationNotes(captured(aPayment())))
        ->toBe(['It adds up. Taken never exceeds reserved, given back never exceeds taken, and every entry is in this payment\'s own currency.']);
});

it('filters to the queue and counts it beside the navigation item', function () {
    $this->actorForTeam(TEAM);

    $broken = aPayment(orderId: 9_000_521, minor: 5_000);
    aCallback('evt-over-2', 'payment.captured', $broken->provider_reference, 9_000);

    $fine = captured(aPayment(orderId: 9_000_522, minor: 5_000));

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$broken, $fine])
        ->filterTable('needs_reconciliation')
        ->assertCanSeeTableRecords([$broken])
        ->assertCanNotSeeTableRecords([$fine]);

    // A queue nobody is reading is one of the three failures nobody gets paged
    // about. A number next to the navigation item is the cheapest available fix.
    expect(PaymentResource::getNavigationBadge())->toBe('1')
        ->and(PaymentResource::getNavigationBadgeColor())->toBe('danger');
});

it('offers no badge and no queue to somebody working in no team', function () {
    $this->actingAs(TestUser::factory()->create());

    $broken = aPayment(minor: 5_000);
    aCallback('evt-over-3', 'payment.captured', $broken->provider_reference, 9_000);

    // Asking the domain query with a null team would scan across tenants to
    // produce a number, and a count is a leak like any other read.
    expect(PaymentResource::getNavigationBadge())->toBeNull()
        ->and(ProviderCallbackResource::getNavigationBadge())->toBeNull()
        // And the list underneath is empty rather than everybody's: the scope is
        // an explicit `whereRaw('1 = 0')`, because `where('team_id', null)`
        // compiles to `is null` and would hand over precisely the orphans every
        // policy denies. The page itself is refused too — `viewAny` is false
        // for an actor working in no team.
        ->and(PaymentResource::getEloquentQuery()->count())->toBe(0)
        ->and(ProviderCallbackResource::getEloquentQuery()->count())->toBe(0)
        ->and(Payment::query()->whereKey($broken->id)->exists())->toBeTrue();
});

it('lists payments raised and never authorized, which is the failure nobody is paged about', function () {
    $this->actorForTeam(TEAM);

    // A crash between the provider approving and this module committing leaves
    // exactly this shape: a payment with no ledger at all, no error anywhere, and
    // a customer who was probably charged. Fifteen minutes is the runbook's own
    // threshold; anything younger is a payment in progress.
    $stalled = Payment::factory()->ofTeam(TEAM)->forOrder(9_000_531)->create([
        'created_at' => now()->subHour(),
    ]);

    $healthy = aPayment(orderId: 9_000_532);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$stalled, $healthy])
        ->filterTable('stalled')
        ->assertCanSeeTableRecords([$stalled])
        ->assertCanNotSeeTableRecords([$healthy]);

    Livewire::test(ViewPayment::class, ['record' => $stalled->reference])
        ->assertOk()
        ->assertSee('Raised, nothing recorded');
});

/**
 * The refusal this module makes rather than answering, surfaced as a sentence.
 *
 * `PaymentQuery::capturedForOrder()` throws `CurrencyMismatch` for a mixed-currency
 * order instead of inventing a rate. Catching that into a blank cell would hide the
 * one thing an operator needs to know.
 */
it('refuses to net across currencies, and says why on the page', function () {
    $this->actorForTeam(TEAM);

    $sterling = captured(aPayment(orderId: 9_000_541, minor: 3_000, currency: 'GBP'));
    captured(aPayment(orderId: 9_000_541, minor: 2_000, currency: 'EUR'));

    expect(fn () => (new PaymentQuery())->capturedForOrder(9_000_541))
        ->toThrow(CurrencyMismatch::class);

    Livewire::test(ViewPayment::class, ['record' => $sterling->reference])
        ->assertOk()
        ->assertSee('paid in more than one currency')
        ->assertSee('This module does no conversion');

    expect(PaymentResource::orderTotalNote($sterling))
        ->toContain('there is no single figure for what it took')
        ->toContain('an accounting decision it deliberately does not make');
});

it('answers the question when an order is in one currency, across every tender', function () {
    $this->actorForTeam(TEAM);

    $card = captured(aPayment(orderId: 9_000_551, minor: 3_000));
    captured(aPayment(orderId: 9_000_551, minor: 2_000));

    expect(PaymentResource::orderTotalNote($card))->toBe('GBP 50.00 taken across every tender against this order.');
});

/**
 * The inbox, and the part of it a team-scoped panel cannot show.
 *
 * A callback that matched no payment carries no team, because the team is copied
 * off the payment it matched. Showing it would mean this package writing a second
 * tenancy answer that contradicts the domain's policy, on a row that might be about
 * anybody's money.
 */
it('shows the callbacks a team owns and refuses the ones nobody does', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    aCallback('evt-mine-1', 'payment.disputed', $payment->provider_reference);
    // A reference nothing in this database has: another environment sharing the
    // provider account, or a payment made before this module was installed.
    aCallback('evt-orphan-1', 'payment.captured', 'ref_nobody_has_this', 1_000);

    $mine = ProviderCallback::query()->where('provider_event_id', 'evt-mine-1')->firstOrFail();
    $orphan = ProviderCallback::query()->where('provider_event_id', 'evt-orphan-1')->firstOrFail();

    expect($orphan->status)->toBe(CallbackStatus::Unmatched)
        ->and($orphan->team_id)->toBeNull();

    Livewire::test(ListProviderCallbacks::class)
        ->assertOk()
        ->filterTable('needs_attention')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$orphan])
        // A queue nobody knows is invisible is worse than one nobody is reading.
        ->assertSee('belong to no team')
        ->assertSee('unhandledCallbacks()');

    // The row exists and the domain's own query finds it — from a console command,
    // where there is no actor and no tenant to leak across.
    expect((new PaymentQuery())->unhandledCallbacks()->pluck('provider_event_id')->all())
        ->toContain('evt-orphan-1')
        ->and((new PaymentQuery())->unhandledCallbacks(TEAM)->pluck('provider_event_id')->all())
        ->toBe(['evt-mine-1'])
        ->and(ProviderCallbackResource::getNavigationBadge())->toBe('1')
        ->and(ProviderCallbackResource::getNavigationBadgeColor())->toBe('warning');
});

it('treats a repeated delivery as the dedupe working rather than as an incident', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    aCallback('evt-twice-1', 'payment.captured', $payment->provider_reference, 5_000);
    aCallback('evt-twice-1', 'payment.captured', $payment->provider_reference, 5_000);

    // One row, one ledger entry. Providers retry until they get a 2xx, and "until"
    // includes a retry a week later after an outage.
    expect(ProviderCallback::query()->where('provider_event_id', 'evt-twice-1')->count())->toBe(1)
        ->and($payment->refresh()->load('entries')->state()->capturedMinor)->toBe(5_000);

    Livewire::test(ListProviderCallbacks::class)
        ->assertOk()
        ->filterTable('needs_attention')
        ->assertCanNotSeeTableRecords(ProviderCallback::query()->where('provider_event_id', 'evt-twice-1')->get());
});
