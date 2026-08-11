<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource;

/**
 * What this package contributes to a panel the application composes.
 *
 * **Two resources over four tables**, and the two that are missing are the
 * interesting part.
 *
 * `ecommerce_payment_entries` is a relation manager on the payment it belongs to
 * and never a list of its own. An entry is a contribution to a fold: on its own it
 * is a number with no total, and the only question anybody asks of it — what does
 * this payment currently say — is a question about all of them at once.
 *
 * `ecommerce_payment_instruments` is not surfaced at all, and that is a refusal
 * rather than an omission. The only ability `PaymentInstrumentPolicy` publishes
 * beyond reading is `detachInstrument`, and **the domain publishes no action for
 * it** — no `DetachInstrument`, nothing that writes `detached_at`. A presentation
 * package that wrote that column itself would be a second write path in the one
 * module where every write is supposed to go through an action, and a top-level
 * list of saved cards whose only purpose is a write nobody may perform is a
 * surface built to answer a question it cannot answer. What an operator actually
 * needs — which card paid for this, and is it still attached — is on the payment
 * that used it. See `docs/domain.md`.
 *
 * Listed rather than discovered by a directory scan: discovery reads the
 * filesystem on every boot to rediscover a set that is fixed at release, and a
 * scan rooted at `src/` would also sweep up anything a later version happens to
 * put there.
 */
final class PaymentOperationsPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-ecommerce-payment-operations';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            PaymentResource::class,
            ProviderCallbackResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
