<?php

use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\PaymentOperations\Actions\AuthorizePayment;
use Liberu\Ecommerce\PaymentOperations\Actions\CapturePayment;
use Liberu\Ecommerce\PaymentOperations\Actions\RecordProviderCallback;
use Liberu\Ecommerce\PaymentOperations\Actions\RefundPayment;
use Liberu\Ecommerce\PaymentOperations\Data\AuthorizationInput;
use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Data\MovementInput;
use Liberu\Ecommerce\PaymentOperations\Filament\Tests\TestCase;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Testing\FakeGateway;

pest()->extend(TestCase::class)->in('Feature');

/**
 * The order and the customer this whole suite bills, and neither exists.
 *
 * ## The ids are at nine million, on purpose
 *
 * `TestUser::factory()` numbers its users from one, and so does every other factory
 * in the tree. A "stranger's" team id of `2` is an id the actor may genuinely hold,
 * and an authorization test written against one passes for the wrong reason — it
 * proves the actor can see their own row, not that they cannot see somebody else's.
 * Nine-million-and-something cannot collide with anything this suite creates.
 *
 * The *absence* matters as much as the range. No module that owns orders or
 * customers is installed in this suite, no table of either exists, and these
 * numbers name nothing at all. They are identifiers, which is the only thing this
 * domain ever holds about its neighbours.
 */
const TEAM = 9_000_007;
const OTHER_TEAM = 9_000_008;
const GHOST_ORDER = 9_000_501;
const GHOST_CUSTOMER = 9_000_301;

/**
 * Raise a payment against an order nothing in this database has heard of, through
 * the domain's own action.
 *
 * Through the action rather than through a factory, because the ledger is what
 * every assertion here is about and a factory is allowed to write a row no
 * production path may. The first ledger entry, the payment hash, the derived entry
 * key and the provider reference are all whatever the domain would really have
 * written.
 *
 * The payment key is unique per call rather than derived from the amount: two
 * tenders of the same size against one order is the multi-tender case, and a key
 * that collided would make the second one a replay of the first.
 */
function aPayment(
    int $orderId = GHOST_ORDER,
    ?int $teamId = TEAM,
    int $minor = 5_000,
    string $currency = 'GBP',
    ?string $token = TestCase::PROVIDER_TOKEN,
): Payment {
    $result = (new AuthorizePayment())->handle(new AuthorizationInput(
        orderId: $orderId,
        paymentKey: 'pay-'.$orderId.'-'.(Payment::query()->count() + 1),
        gateway: 'card',
        amount: new Money($minor, $currency),
        customerId: GHOST_CUSTOMER,
        teamId: $teamId,
        checkoutReference: 'CHK-GHOST'.$orderId,
        instrumentToken: $token,
    ));

    return Payment::query()->with('entries')->findOrFail($result->payment->id);
}

/** Take money the way a caller with its own idempotency key would. */
function captured(Payment $payment, ?int $minor = null, ?string $key = null): Payment
{
    (new CapturePayment())->handle(new MovementInput(
        paymentReference: $payment->reference,
        entryKey: $key ?? 'caller-capture-'.$payment->reference.'-'.($minor ?? 'all'),
        amount: $minor === null ? null : new Money($minor, $payment->currency, $payment->currency_exponent),
    ));

    return $payment->refresh()->load('entries');
}

/** Give money back the same way. */
function refunded(Payment $payment, ?int $minor = null, ?string $key = null): Payment
{
    (new RefundPayment())->handle(new MovementInput(
        paymentReference: $payment->reference,
        entryKey: $key ?? 'caller-refund-'.$payment->reference.'-'.($minor ?? 'all'),
        amount: $minor === null ? null : new Money($minor, $payment->currency, $payment->currency_exponent),
    ));

    return $payment->refresh()->load('entries');
}

/**
 * Deliver a signed provider callback, the way the provider's own retry would.
 *
 * This is the only way to get a **provider-origin** entry into a ledger, and
 * provider-origin entries are the only ones that skip the domain's guards — so it
 * is the only way to build the impossible ledgers the reconciliation queue exists
 * for. Signed properly, because `verify()` is the only method that produces a
 * `ProviderEvent` and there is no way to hold a parsed callback that was not
 * verified.
 */
function aCallback(
    string $eventId,
    string $type = 'payment.captured',
    ?string $reference = null,
    ?int $minor = null,
    string $currency = 'GBP',
): void {
    $body = (string) json_encode(array_filter([
        'id' => $eventId,
        'type' => $type,
        'reference' => $reference,
        'amount' => $minor,
        'currency' => $minor === null ? null : $currency,
    ], fn ($value): bool => $value !== null));

    (new RecordProviderCallback())->handle('card', $body, [
        'signature' => FakeGateway::sign($body, TestCase::SIGNING_SECRET),
    ]);
}

/**
 * Capture what was written to the log, in order.
 *
 * A long closure with `use (&$records)` and not an arrow function: `fn` captures by
 * value at the point it is defined, so it would hand back the empty array this
 * starts as and never see anything the listener appended.
 *
 * @return Closure(): list<array{level: string, message: string, context: array<string, mixed>}>
 */
function captureLog(): Closure
{
    $records = [];

    Log::listen(function ($record) use (&$records) {
        $records[] = ['level' => $record->level, 'message' => $record->message, 'context' => $record->context];
    });

    return function () use (&$records): array {
        return $records;
    };
}
