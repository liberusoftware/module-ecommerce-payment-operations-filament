<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Support;

use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Data\Settlement;

/**
 * Money on a screen, rendered from the integer the ledger holds.
 *
 * **There is no arithmetic here at all**, and that is the whole design. `Money`
 * already knows how to turn minor units into a decimal string — padding, `substr`
 * and concatenation, never a division — so this class formats and does not
 * compute. A second implementation of that conversion would agree with the
 * domain's on nearly every amount, which is exactly what would make it dangerous:
 * `(int) (19.99 * 100)` is `1998` and `1999 / 100` is where the penny goes.
 *
 * The exponent travels inside the `Money`, so a zero-exponent currency is not
 * quietly divided by a hundred on the way to a column.
 *
 * Prefixed with the ISO code rather than a symbol. A panel serving several
 * merchants shows several currencies in one column, two symbols do not tell a
 * screen reader which is which, and this package ships no currency table to look
 * a symbol up in.
 */
final class Amount
{
    public static function format(Money $money): string
    {
        return $money->currency.' '.$money->decimal();
    }

    /** An optional amount, or null when there is nothing to show. */
    public static function of(?Money $money): ?string
    {
        return $money === null ? null : self::format($money);
    }

    /**
     * What the provider says it actually settled, and at what rate.
     *
     * Rendered beside the presentment amount and never instead of it, and never
     * summed with anything. The domain records a settlement and converts nothing:
     * turning these two numbers into one is an accounting decision about which
     * rate at which moment net of which fees, and it belongs to whoever owns the
     * books. The rate is a string all the way from the provider to this line,
     * because a rate has more decimal places than a float holds exactly.
     */
    public static function settlement(?Settlement $settlement): ?string
    {
        if ($settlement === null) {
            return null;
        }

        return self::format($settlement->amount).' at '.$settlement->rate;
    }
}
