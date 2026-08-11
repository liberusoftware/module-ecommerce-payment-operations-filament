<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Support;

use Liberu\Ecommerce\PaymentOperations\Enums\CallbackStatus;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryOrigin;
use Liberu\Ecommerce\PaymentOperations\Enums\PaymentStatus;

/**
 * The domain's vocabulary, in words a person reads, and a colour beside it.
 *
 * **Words first and colour second, everywhere.** Amber and grey are the same badge
 * to a screen reader and to anybody who cannot separate the two, and every state
 * in this module is a claim about money — "partially captured" and "refunded" have
 * to be legible without a palette.
 *
 * Every `match` here lists every case with no `default` arm, deliberately, for the
 * same reason the domain's fold has none: a new `EntryKind` or a new
 * `PaymentStatus` arriving in a later release of the domain raises
 * `UnhandledMatchError` on the first render rather than quietly showing a state
 * with no name, and a test folds every case so the build is what finds out.
 */
final class Wording
{
    /**
     * A payment's derived state, in words.
     *
     * `Pending` says "raised, and nothing has happened" rather than "pending",
     * because a payment sitting there is either seconds old or the silent failure
     * the runbook's first section is about, and neither is what "pending" suggests.
     */
    public static function status(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'Raised, nothing recorded',
            PaymentStatus::Authorized => 'Reserved, not taken',
            PaymentStatus::PartiallyCaptured => 'Partly taken',
            PaymentStatus::Captured => 'Taken',
            PaymentStatus::PartiallyRefunded => 'Partly given back',
            PaymentStatus::Refunded => 'Given back',
            PaymentStatus::Voided => 'Reservation released',
            PaymentStatus::Expired => 'Reservation expired',
            PaymentStatus::Failed => 'Refused by the provider',
        };
    }

    public static function statusColour(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'gray',
            PaymentStatus::Authorized => 'info',
            PaymentStatus::PartiallyCaptured => 'warning',
            PaymentStatus::Captured => 'success',
            PaymentStatus::PartiallyRefunded => 'warning',
            PaymentStatus::Refunded => 'gray',
            PaymentStatus::Voided => 'gray',
            PaymentStatus::Expired => 'gray',
            PaymentStatus::Failed => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (PaymentStatus::cases() as $status) {
            $options[$status->value] = self::status($status);
        }

        return $options;
    }

    /**
     * What one ledger row did.
     *
     * Named for the event rather than for the resulting state — a row is a thing
     * that happened at a moment, and the state is what you get by adding them up.
     */
    public static function kind(EntryKind $kind): string
    {
        return match ($kind) {
            EntryKind::Authorized => 'Reserved',
            EntryKind::Captured => 'Taken',
            EntryKind::Voided => 'Released',
            EntryKind::Refunded => 'Given back',
            EntryKind::Failed => 'Refused',
            EntryKind::Expired => 'Expired',
            EntryKind::Settled => 'Settled',
        };
    }

    public static function kindColour(EntryKind $kind): string
    {
        return match ($kind) {
            EntryKind::Authorized => 'info',
            EntryKind::Captured => 'success',
            EntryKind::Voided => 'gray',
            EntryKind::Refunded => 'warning',
            EntryKind::Failed => 'danger',
            EntryKind::Expired => 'gray',
            EntryKind::Settled => 'success',
        };
    }

    /**
     * Who wrote the row, which is the column that decides whether a number can be
     * trusted.
     *
     * A caller-origin row passed every guard the domain has. A provider-origin row
     * passed none of them — deliberately, because losing a fact about our money is
     * worse than holding an inconsistent one — so it is the only kind of row that
     * can put a payment into the reconciliation queue. An operator looking at an
     * impossible ledger is looking for these.
     */
    public static function origin(EntryOrigin $origin): string
    {
        return match ($origin) {
            EntryOrigin::Caller => 'We asked for it',
            EntryOrigin::Provider => 'The provider told us',
        };
    }

    public static function callbackStatus(CallbackStatus $status): string
    {
        return match ($status) {
            CallbackStatus::Applied => 'Applied to a payment',
            CallbackStatus::Duplicate => 'Seen before',
            CallbackStatus::Unmatched => 'No payment matched',
            CallbackStatus::Unrecognised => 'Event type not mapped',
        };
    }

    public static function callbackColour(CallbackStatus $status): string
    {
        return match ($status) {
            CallbackStatus::Applied => 'success',
            CallbackStatus::Duplicate => 'gray',
            CallbackStatus::Unmatched => 'warning',
            CallbackStatus::Unrecognised => 'warning',
        };
    }

    /** @return array<string, string> */
    public static function callbackOptions(): array
    {
        $options = [];

        foreach (CallbackStatus::cases() as $status) {
            $options[$status->value] = self::callbackStatus($status);
        }

        return $options;
    }
}
