<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The person at the panel, which team they are working in, and who to record a
 * movement against.
 *
 * The team is read off the actor rather than off `Filament::getTenant()` because
 * this package does not require the panel to be tenant-aware — an application may
 * attach the plugin to a panel with no tenancy at all, and a null tenant there
 * would silently widen the scope to every merchant's money. It is also the same
 * attribute all four domain policies read, deliberately: a list scoped by one rule
 * and authorized by another shows rows every row action then refuses, which reads
 * as a broken panel rather than as a denied one.
 *
 * Not the user model: no package may name the application's. `getAttribute()` on
 * `Model` is as far as this goes, and a guard that is not one answers null.
 */
final class PanelActor
{
    /**
     * The team, or null.
     *
     * `is_numeric()` is the guard the domain's `ReadsWithinTeam` uses, and it is
     * the one wave 5 recorded failing silently on ULID and UUID deployments: a
     * string id answers null, and null here means **no**. That is the safe
     * direction — `(int) '01H…'` is `1`, and showing an operator team 1's payments
     * is worse than showing them nothing — but it is completely invisible, which
     * is why `docs/runbook.md` names it as the first thing to check when a panel
     * is empty for everybody.
     */
    public static function teamId(): ?int
    {
        $actor = Auth::user();

        $teamId = $actor instanceof Model ? $actor->getAttribute('current_team_id') : null;

        return is_numeric($teamId) ? (int) $teamId : null;
    }

    /**
     * Who pressed the button, for `ecommerce_payment_entries.recorded_by`.
     *
     * Null is an honest answer and the same guard applies: `Auth::id()` on a host
     * with string ids is not an integer, and attributing somebody's refund to user
     * `1` is worse than attributing it to nobody. The domain takes this id from
     * its caller rather than reading the auth context precisely so a surface can
     * pass what it means.
     *
     * Unlike the sibling package for parcels, this one has an `id()` at all —
     * because the domain has a column for it. A refund is a person taking money
     * out of the business, and the ledger keeps a note of which person.
     */
    public static function id(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Narrow a query to the actor's team.
     *
     * The null case is a `whereRaw('1 = 0')` rather than `where('team_id', null)`:
     * the query builder turns a null binding into `is null`, which would list
     * precisely the unowned rows every policy in the domain denies every action
     * on. A payment with `team_id` null belongs to nobody — the runbook calls it an
     * orphan and says to reach it from a console command where there is no actor —
     * so a scope that returned them would be a list of other people's money where
     * every button is greyed out.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $column = 'team_id'): Builder
    {
        $teamId = self::teamId();

        return $query->when(
            $teamId === null,
            fn (Builder $scoped) => $scoped->whereRaw('1 = 0'),
            fn (Builder $scoped) => $scoped->where($column, $teamId),
        );
    }
}
