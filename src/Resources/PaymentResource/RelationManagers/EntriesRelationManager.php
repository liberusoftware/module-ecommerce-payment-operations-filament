<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryOrigin;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\LedgerRelationManager;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Amount;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Wording;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentEntry;

/**
 * **The ledger itself** — every event recorded against this payment, and the only
 * place any number on the page above comes from.
 *
 * The payment has no status column and no cached total. Everything the view page
 * says — reserved, taken, given back, still capturable — is a sum over these rows,
 * computed at the moment it was asked for. Showing the rows underneath the totals
 * is what makes that checkable by a person: a merchant who disagrees with a number
 * can add this table up.
 *
 * ## Ordered by the provider's clock, not by ours
 *
 * `Payment::entries()` orders by `occurred_at` and then by `id`, so the ledger
 * reads in the order things really happened rather than the order we heard about
 * them. That matters for a callback that arrived late and for nothing else: the
 * arithmetic is a commutative fold, so the totals are bit-identical whichever order
 * these rows are in. A module that stored a status would have had to solve
 * out-of-order delivery; this one shows the rows in a sensible order because a list
 * that reads out of sequence looks like something went wrong.
 *
 * **Both clocks are here on purpose.** `occurred_at` is the provider's and
 * `recorded_at` is ours, and the gap between them is the interesting number when a
 * callback arrives late. Collapsing them into one column is how "we heard about it
 * on Tuesday" becomes "it happened on Tuesday".
 *
 * ## What is not in this table
 *
 * **The idempotency key and the fact hash.** Both are machinery: the key is the
 * string a caller supplied to make a redelivered job harmless, and the hash is what
 * tells a replay from a conflict. Neither is a fact about money, and a key on a
 * screen is a key somebody types into a second surface — which is the one way to
 * make the guarantee it exists for stop working.
 *
 * **A settlement is shown and never added.** The provider's own amount and rate sit
 * beside the presentment amount, in their own column, because the domain records
 * them and converts nothing. See {@see Amount::settlement()}.
 */
class EntriesRelationManager extends LedgerRelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'The ledger';

    public function table(Table $table): Table
    {
        return $table
            ->description('Every event recorded against this payment. Nothing here can be edited or deleted — a ledger row is what happened, and a correction is a new row written through the same actions everything else is.')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When it happened')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('What happened')
                    ->badge()
                    ->formatStateUsing(fn (EntryKind $state): string => Wording::kind($state))
                    ->color(fn (EntryKind $state): string => Wording::kindColour($state)),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (PaymentEntry $record): string => Amount::format($record->amount())),
                TextColumn::make('origin')
                    ->label('Who recorded it')
                    ->formatStateUsing(fn (EntryOrigin $state): string => Wording::origin($state)),
                TextColumn::make('settlement')
                    ->label('Settled as')
                    ->state(fn (PaymentEntry $record): ?string => Amount::settlement($record->settlement()))
                    // Null is the ordinary case: the provider settled in the
                    // currency the customer was charged in, so there is nothing
                    // to say. An empty cell would read as a missing value.
                    ->placeholder('Same currency'),
                TextColumn::make('failure_code')
                    ->label('Refusal')
                    // A short code, never the provider's prose. The domain refuses
                    // free text here on the grounds that free text next to a log
                    // line is where a customer's email address ends up.
                    ->placeholder('None'),
                TextColumn::make('provider_reference')
                    ->label('Provider reference')
                    ->placeholder('None')
                    ->toggleable(),
                TextColumn::make('recorded_by')
                    ->label('Recorded by')
                    // Null is honest and common: a provider callback is nobody and
                    // a scheduled capture is nobody.
                    ->placeholder('Nobody — a provider or a job')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('When we heard')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at');
    }
}
