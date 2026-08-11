<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;

/**
 * No header actions, because there is no `CreateAction` to put in them.
 *
 * A payment is raised by a checkout completing or by a caller holding an
 * idempotency key of its own, and `PaymentPolicy::create()` denies the ability
 * outright. A button here would mint a fresh key on every press, and on this table
 * that means reserving a customer's money twice.
 */
class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /**
     * What this surface will and will not do, in one sentence, because a refusal
     * inferred from a missing button is a puzzle.
     */
    public function getSubheading(): string
    {
        return 'A payment has no status field and nothing here can set one: every figure is a sum over the ledger, computed as this page was drawn. The only writes are the three movements the domain publishes — take the money, release the reservation, give it back — and each writes a new row rather than changing an old one.';
    }
}
