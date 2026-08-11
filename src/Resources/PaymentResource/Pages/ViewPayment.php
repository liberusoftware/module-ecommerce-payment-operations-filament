<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;

/**
 * One payment: what the ledger folds to, the ledger itself, and the movements
 * still available.
 *
 * A `ViewRecord` rather than an `EditRecord`, which is the difference the whole
 * package is built around. The header carries the three movements and nothing
 * else — no edit, no delete, no replicate — and each is hidden unless the policy
 * allows it, which means unless the ledger allows it too.
 *
 * A payment with nothing left to move therefore has a header with no buttons at
 * all, and the **What can happen next** section says so in words rather than
 * leaving an empty header to be interpreted.
 */
class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return PaymentResource::movementActions();
    }
}
