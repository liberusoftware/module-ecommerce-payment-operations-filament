<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Tests\Fixtures;

use Liberu\Ecommerce\PaymentOperations\Data\GatewayInstruction;
use Liberu\Ecommerce\PaymentOperations\Data\GatewayOutcome;
use Liberu\Ecommerce\PaymentOperations\Testing\FakeGateway;

/**
 * A gateway that says no to a capture.
 *
 * The domain's own fake approves every movement, which is the right default for a
 * fake — but a provider refusing a capture is an ordinary Tuesday and it is the one
 * outcome a panel most easily reports as a success. A decline is recorded rather
 * than thrown: it becomes a `failed` ledger row of zero and the result carries
 * `approved: false`, so a surface that only checked for an exception would show a
 * green notification about money that never moved.
 *
 * It also leaves the ledger unchanged in every total, which is what makes it the
 * only way to build the two idempotency situations a panel can genuinely reach:
 * an entry key already used with the same facts (a replay), and one already used
 * with different facts (a permanent conflict).
 */
final class RefusingGateway extends FakeGateway
{
    public function capture(GatewayInstruction $instruction): GatewayOutcome
    {
        return GatewayOutcome::declined('do_not_honour', $instruction->providerReference);
    }
}
