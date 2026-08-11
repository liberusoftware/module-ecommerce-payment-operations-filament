<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\PaymentOperations\Actions\CapturePayment;
use Liberu\Ecommerce\PaymentOperations\Actions\RefundPayment;
use Liberu\Ecommerce\PaymentOperations\Actions\VoidPayment;
use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Data\MovementInput;
use Liberu\Ecommerce\PaymentOperations\Data\PaymentResult;
use Liberu\Ecommerce\PaymentOperations\Enums\PaymentStatus;
use Liberu\Ecommerce\PaymentOperations\Exceptions\CurrencyMismatch;
use Liberu\Ecommerce\PaymentOperations\Exceptions\ExceedsAuthorization;
use Liberu\Ecommerce\PaymentOperations\Exceptions\ExceedsCapture;
use Liberu\Ecommerce\PaymentOperations\Exceptions\PaymentConflict;
use Liberu\Ecommerce\PaymentOperations\Exceptions\PaymentInFlight;
use Liberu\Ecommerce\PaymentOperations\Exceptions\PaymentNotVoidable;
use Liberu\Ecommerce\PaymentOperations\Exceptions\UnknownGateway;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\RelationManagers\EntriesRelationManager;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Amount;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\PanelActor;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Wording;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Queries\PaymentQuery;
use UnitEnum;

/**
 * A tender against an order, and the three movements of money the domain
 * publishes.
 *
 * ## There is no status field, because there is no status
 *
 * `ecommerce_payment_payments` has no `status` column, no `captured_minor` and no
 * `refunded_minor` — the domain's `SchemaTest` asserts each of them absent **by
 * name** so a convenience column cannot quietly reappear. Every number on this
 * page is `PaymentState::fold()` over the ledger, computed when it is asked for.
 *
 * The host in this repository is the argument for that, and this resource exists
 * to not be it. `CONFORMANCE.md` records `OrderResource` exposing `payment_status`
 * as a free `Select` beside an editable `total_amount`, bypassing
 * `Order::TRANSITIONS` entirely. Two failures follow from one cause: a staff member
 * can mark an order paid with no money having moved, and nothing anywhere records
 * that they did it.
 *
 * So this resource has **no form, no create page, no edit page and no delete**. It
 * is not that editing is forbidden here — there is nothing to edit. What a person
 * may do is capture, void and refund, each an explicit action with its own
 * confirmation and its own ability, because they are different-sized mistakes: one
 * takes money, one gives it back, and one costs a fee that the other does not.
 *
 * ## Partial amounts are not typed on this surface
 *
 * The domain supports partial captures and partial refunds and this panel offers
 * neither, deliberately. **Capture takes everything still reserved; refund gives
 * back everything still refundable.** Two reasons, and the second is the load
 * bearing one:
 *
 * 1. A money field is where a typo becomes a charge. There is no amount input
 *    anywhere in this package, so nothing here can mistype one.
 * 2. **An idempotency key belongs to whoever decided the amount.** A partial
 *    capture is somebody's decision — a parcel shipped, a line went out of stock —
 *    and that decision is what a key names. A button cannot own a key for a
 *    decision it did not make.
 *
 * A deployment that needs partial movements makes them through a caller that owns
 * its key: the domain's actions, the API package, or a host listener. See
 * `docs/domain.md`.
 *
 * ## The key a button does use, and why pressing twice is safe
 *
 * `panel:{reference}:{operation}:{amount}` — derived from the payment and from the
 * ledger as it stands at the press, never minted fresh. A second press re-reads the
 * payment, finds nothing left to move and says so; a genuine race between two
 * operators derives the same key and the domain's unique index lets exactly one of
 * them write. The domain's `create` ability is permanently false for this reason,
 * and a fresh key per press is precisely what it is refusing.
 *
 * The consequence, said out loud rather than discovered: **a panel cannot tell a
 * second identical instruction from a second click.** Capturing £30, refunding £30,
 * capturing £30 again and refunding £30 again would derive the same refund key
 * twice, and the second press replays and writes nothing — and says that it did.
 * Failing closed is the correct direction for money, and the caller that owns its
 * own keys is the surface for the other case.
 *
 * ## Every ability is stated, because silence here is permission
 *
 * Laravel's unanswered gate case is permissive, and Filament's
 * `get_authorization_response()` returns *allow* when a **present** policy has no
 * method for the ability asked about — so a partial policy is the same hazard as no
 * policy and harder to see, because the file exists and looks like a control.
 *
 * `PaymentPolicy` publishes `viewAny`, `view`, `capture`, `void`, `refund`, and
 * refuses sixteen more by name through `RefusesEveryWrite`. `deleteAny`,
 * `forceDeleteAny`, `restoreAny`, `replicate` and `reorder` are among them, and
 * they are restated below alongside the ones the policy already denies so the set
 * reads as one list rather than as two halves nobody can hold in mind.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $modelLabel = 'payment';

    /**
     * A payment is bound by its public reference and never by its id.
     *
     * An incrementing id in a URL enumerates everybody else's payments, and a URL
     * is the part of a page that gets pasted into a support ticket, a chargeback
     * response and an email to a customer. The domain mints `reference` from the
     * CSPRNG for exactly that.
     *
     * This property governs only the *inbound* half: it is read in
     * `resolveRecordRouteBinding()`. Route *generation* asks the route for a
     * binding field and, finding none, falls back to the model's own route key —
     * and `Payment` does not override `getRouteKeyName()`, so that is the id. The
     * view page therefore declares `{record:reference}` in `getPages()`, which is
     * the half that keeps the id out of the address bar. Both are needed; either
     * alone is a half-measure that reads like a control.
     */
    protected static ?string $recordRouteKeyName = 'reference';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * A payment is *raised*, by a checkout completing or by a caller with an
     * idempotency key of its own. `PaymentPolicy::create()` is permanently false;
     * this restates it so the refusal does not depend on which file is read first.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** A payment is what happened. There is no field on it that is anybody's to set. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** Every number this module reports was built from this row and its ledger. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    /**
     * The team's payments, with the ledger every column and every button reads.
     *
     * The eager load is not decoration. `Payment::state()` folds the entries, and
     * without them loaded that is a query per row per column — the domain's own
     * `PaymentQuery` eager-loads on every read it publishes for the same reason.
     *
     * @return Builder<Payment>
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scope(parent::getEloquentQuery()->with('entries'));
    }

    /**
     * There is no form.
     *
     * Not an empty one pending a later release: there is nothing on a payment a
     * person may set. The whole package constructs no input of any kind — no
     * status, no amount, no reason, no key — and a test greps the source to keep it
     * that way.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Payment')
                    // The only searchable column in this package, and it is the
                    // reference support quotes down a telephone. A search term is
                    // persisted into the query string, which is written into every
                    // access log between here and the operator, so nothing else is
                    // searchable — see the security notes in `docs/domain.md`.
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('State')
                    ->badge()
                    // Folded, not stored. There is no `status` column to read.
                    ->state(fn (Payment $record): PaymentStatus => $record->state()->status())
                    ->formatStateUsing(fn (PaymentStatus $state): string => Wording::status($state))
                    ->color(fn (PaymentStatus $state): string => Wording::statusColour($state)),
                // A number with no relation and no foreign key. The module that
                // owns orders is not a dependency of this package and there is
                // nothing here to link to.
                TextColumn::make('order_id')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Raised for')
                    ->state(fn (Payment $record): string => Amount::format($record->amount())),
                TextColumn::make('captured')
                    ->label('Taken')
                    ->state(fn (Payment $record): string => Amount::format($record->state()->captured())),
                TextColumn::make('refunded')
                    ->label('Given back')
                    ->state(fn (Payment $record): string => Amount::format($record->state()->refunded()))
                    ->toggleable(),
                TextColumn::make('reconciliation')
                    ->label('Reconciliation')
                    ->badge()
                    ->color('danger')
                    // Null on a healthy payment, so the column is empty until
                    // something is wrong — and the placeholder says the ordinary
                    // case out loud rather than leaving a blank cell to interpret.
                    ->state(fn (Payment $record): ?string => $record->state()->needsReconciliation() ? 'Impossible ledger' : null)
                    ->placeholder('Adds up'),
                TextColumn::make('gateway')
                    ->label('Gateway')
                    ->toggleable(),
                TextColumn::make('instrument')
                    ->label('Paid with')
                    // A brand and a last four, frozen onto the payment when it was
                    // authorized. There is no column in this module that could hold
                    // a card number, a security code or an account number, and the
                    // provider's token is never rendered anywhere.
                    ->state(fn (Payment $record): ?string => self::instrument($record))
                    ->placeholder('Not recorded')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('needs_reconciliation')
                    ->label('Ledger does not add up')
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', self::reconciliationIds())),
                Filter::make('stalled')
                    ->label('Raised and never authorized')
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', self::stalledIds())),
            ])
            ->recordActions([
                ViewAction::make(),
                ...self::movementActions(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * The three movements, in the order a payment normally meets them.
     *
     * @return list<Action>
     */
    public static function movementActions(): array
    {
        return [
            self::captureAction(),
            self::voidAction(),
            self::refundAction(),
        ];
    }

    /**
     * Take money that is reserved.
     *
     * `PaymentPolicy::capture()` answers ownership **and** asks the ledger — there
     * is nothing to capture on a payment with nothing capturable — so the button is
     * absent rather than present and throwing. The re-read inside
     * {@see capture()} closes the window a hidden button cannot: a colleague on
     * another screen, a queued job, a provider callback.
     */
    public static function captureAction(): Action
    {
        return Action::make('capture')
            ->label('Take the money')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Take the money that is reserved')
            ->modalDescription('This takes everything still reserved on this payment — the whole of it, because a partial capture is a decision somebody made about goods and it belongs to whoever made it, along with the idempotency key that names it. The customer sees a charge. Pressing this twice records one capture.')
            ->modalSubmitActionLabel('Take it')
            ->visible(fn (Payment $record): bool => Gate::allows('capture', $record))
            ->action(fn (Payment $record) => self::capture($record));
    }

    /**
     * Release a reservation without taking anything.
     *
     * Gated on the domain's answer as well as on tenancy: `PaymentPolicy::void()`
     * is false the moment anything is captured, and `VoidPayment` refuses it too.
     * The panel simply stops offering a button that would throw — and the view page
     * says why in the domain's own words, because a missing button is a puzzle.
     */
    public static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Release the reservation')
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Release the reservation')
            ->modalDescription('The money stops being held and nothing moves. The customer never sees this on a statement, which is the difference between it and a refund — and it usually costs nothing, which a refund usually does not. Once anything has been taken there is no reservation left to release and this stops being offered.')
            ->modalSubmitActionLabel('Release it')
            ->visible(fn (Payment $record): bool => Gate::allows('void', $record))
            ->action(fn (Payment $record) => self::void($record));
    }

    /**
     * Give back money that was taken.
     *
     * **This module moves the money; it does not decide that it is owed.** Whether
     * a customer is owed anything, how much and under what return window belongs to
     * Returns and to Refunds, and neither is imported here. An operator pressing
     * this has already made that decision somewhere else.
     */
    public static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Give the money back')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Give the money back')
            ->modalDescription('This gives back everything still refundable on this payment. The customer sees it on their statement and the merchant usually pays a fee for it. Whether they are owed it is a decision that belongs to returns or refunds and is not made here — this only moves the money.')
            ->modalSubmitActionLabel('Give it back')
            ->visible(fn (Payment $record): bool => Gate::allows('refund', $record))
            ->action(fn (Payment $record) => self::refund($record));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The payment')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')
                        ->label('Payment'),
                    TextEntry::make('status')
                        ->label('State')
                        ->badge()
                        ->getStateUsing(fn (Payment $record): PaymentStatus => $record->state()->status())
                        ->formatStateUsing(fn (PaymentStatus $state): string => Wording::status($state))
                        ->color(fn (PaymentStatus $state): string => Wording::statusColour($state)),
                    TextEntry::make('order_id')
                        ->label('Order'),
                    TextEntry::make('gateway')
                        ->label('Gateway'),
                    TextEntry::make('provider_reference')
                        ->label('Provider reference')
                        ->placeholder('None yet'),
                    TextEntry::make('created_at')
                        ->label('Raised')
                        ->dateTime(),
                ]),
            Section::make('What the ledger says')
                ->description('None of these is stored. Each is a sum over the ledger below, computed when this page was drawn — which is why they cannot disagree with the events that produced them. Add the table up and you get these numbers.')
                ->columns(3)
                ->schema([
                    TextEntry::make('authorized')
                        ->label('Reserved')
                        ->getStateUsing(fn (Payment $record): string => Amount::format($record->state()->authorized())),
                    TextEntry::make('captured')
                        ->label('Taken')
                        ->getStateUsing(fn (Payment $record): string => Amount::format($record->state()->captured())),
                    TextEntry::make('refunded')
                        ->label('Given back')
                        ->getStateUsing(fn (Payment $record): string => Amount::format($record->state()->refunded())),
                    TextEntry::make('capturable')
                        ->label('Still takeable')
                        ->getStateUsing(fn (Payment $record): string => Amount::format($record->state()->capturable())),
                    TextEntry::make('refundable')
                        ->label('Still refundable')
                        ->getStateUsing(fn (Payment $record): string => Amount::format($record->state()->refundable())),
                    TextEntry::make('failed_attempts')
                        ->label('Refused attempts')
                        ->getStateUsing(fn (Payment $record): int => $record->state()->failedCount),
                ]),
            Section::make('What can happen next')
                ->description('Three movements, each offered only when the ledger and the policy both allow it. There is no status to set and nothing to edit.')
                ->columns(2)
                ->schema([
                    TextEntry::make('next_movements')
                        ->label('Available now')
                        ->getStateUsing(fn (Payment $record): array => self::nextMovements($record))
                        ->listWithLineBreaks(),
                    TextEntry::make('void_note')
                        ->label('Releasing the reservation')
                        ->getStateUsing(fn (Payment $record): string => self::voidNote($record)),
                ]),
            Section::make('Reconciliation')
                ->description('Whether this ledger says something arithmetically impossible. Nothing here is corrected automatically — there is no clamp and no repair job, because a wrong number nobody is told about is the worst outcome available to a module whose job is being told.')
                ->schema([
                    TextEntry::make('reconciliation')
                        ->label('Does it add up')
                        ->getStateUsing(fn (Payment $record): array => self::reconciliationNotes($record))
                        ->listWithLineBreaks(),
                ]),
            Section::make('This order, across every tender against it')
                ->description('An order may be paid by more than one payment — a card and a gift card are two tenders and two rows. There is deliberately no unique key on the order id, so this is a real question with a real answer, except when it is not.')
                ->schema([
                    TextEntry::make('order_total')
                        ->label('Taken across the order')
                        ->getStateUsing(fn (Payment $record): string => self::orderTotalNote($record)),
                ]),
            Section::make('What paid for it')
                ->description('A brand, a last four and an expiry, frozen onto this payment when it was authorized so that detaching the saved card later cannot rewrite what a receipt says. This module has no column that could hold a card number, a security code or an account number, and the provider\'s token — which moves money when presented — is not rendered on any surface in this package.')
                ->columns(3)
                ->schema([
                    TextEntry::make('instrument_brand')
                        ->label('Brand')
                        ->placeholder('Not recorded'),
                    TextEntry::make('instrument_last_four')
                        ->label('Last four')
                        ->placeholder('Not recorded'),
                    TextEntry::make('expiry')
                        ->label('Expires')
                        ->getStateUsing(fn (Payment $record): ?string => self::expiry($record))
                        ->placeholder('Not recorded'),
                ]),
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
        ];
    }

    /**
     * Index and view.
     *
     * No create page, because a payment is raised under a key its caller supplies.
     * No edit page, because there is nothing on a payment to edit — and an edit
     * page is where a status field comes from.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record:reference}'),
        ];
    }

    /**
     * How many payments in this team's ledger say something impossible.
     *
     * The runbook's reconciliation queue, put where somebody will see it. A queue
     * nobody is reading is the shape of two of the three silent failures this
     * module has, and a number next to the navigation item is the cheapest fix
     * available.
     *
     * ponytail: this folds every one of the team's payments on every panel render,
     * which is the ceiling the domain names on `PaymentQuery::needingReconciliation()`
     * itself. Fine for an operational queue over one team; the upgrade, if a
     * deployment outgrows it, is a materialised flag written by the same code that
     * appends the entry — not a second implementation of the fold in SQL, because
     * two implementations of one piece of arithmetic is two answers waiting to
     * disagree.
     */
    public static function getNavigationBadge(): ?string
    {
        $teamId = PanelActor::teamId();

        // No team is no answer, not every team's answer. Asking the domain query
        // with a null team would scan across tenants to produce a number.
        if ($teamId === null) {
            return null;
        }

        $count = (new PaymentQuery())->needingReconciliation($teamId)->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** The brand and last four this payment was made with, if any were recorded. */
    public static function instrument(Payment $payment): ?string
    {
        $brand = $payment->instrument_brand;
        $lastFour = $payment->instrument_last_four;

        if ($brand === null && $lastFour === null) {
            return null;
        }

        return trim(($brand ?? 'Card').' ••••'.($lastFour ?? '????'));
    }

    /** The saved instrument's expiry, when the payment still points at one. */
    public static function expiry(Payment $payment): ?string
    {
        $instrument = $payment->instrument;

        if ($instrument === null || $instrument->expiry_month === null || $instrument->expiry_year === null) {
            return null;
        }

        return str_pad((string) $instrument->expiry_month, 2, '0', STR_PAD_LEFT).'/'.$instrument->expiry_year;
    }

    /**
     * Which movements are on offer from where this payment is, in words.
     *
     * Read off the policy rather than written out, so the sentence cannot disagree
     * with the buttons: the policy is the thing that decides both.
     *
     * @return list<string>
     */
    public static function nextMovements(Payment $payment): array
    {
        $available = [];

        if (Gate::allows('capture', $payment)) {
            $available[] = 'Take the money — '.Amount::format($payment->state()->capturable()).' is still reserved.';
        }

        if (Gate::allows('void', $payment)) {
            $available[] = 'Release the reservation — nothing has been taken yet.';
        }

        if (Gate::allows('refund', $payment)) {
            $available[] = 'Give the money back — '.Amount::format($payment->state()->refundable()).' can still go back.';
        }

        return $available === []
            ? ['None. This payment has nothing left to move, and there is no field on it anybody may set instead.']
            : $available;
    }

    /**
     * Why releasing the reservation is or is not on offer.
     *
     * A void and a refund are different operations, and this module refuses to
     * decide for a merchant which of the two happened. The customer sees a refund
     * on their statement and never sees a void, and the merchant usually pays a fee
     * for the first and nothing for the second — a panel that quietly refunded when
     * somebody asked to void would be telling them at the end of the month.
     */
    public static function voidNote(Payment $payment): string
    {
        $state = $payment->state();

        if ($state->capturedMinor > 0) {
            return 'Not available: money has been taken against this payment, so there is no reservation left to release. Giving it back is a refund — the customer sees it on their statement and it usually costs a fee, which is why this module makes you name which of the two you meant.';
        }

        if ($state->authorizedMinor <= 0) {
            return 'Not available: there is no live reservation to release.';
        }

        return 'Available. '.Amount::format($state->capturable()).' is reserved and nothing has been taken, so releasing it moves no money and the customer sees nothing.';
    }

    /**
     * What is wrong with this ledger, named, or a sentence saying it is fine.
     *
     * The three cases the domain reports and refuses to clamp. Each is a work queue
     * with an entry in `docs/runbook.md`, and every one of them is only reachable
     * through a provider-origin row — everything written on a caller's behalf is
     * guarded — which is why the ledger's "who recorded it" column is where to look.
     *
     * @return list<string>
     */
    public static function reconciliationNotes(Payment $payment): array
    {
        $state = $payment->state();

        if (! $state->needsReconciliation()) {
            return ['It adds up. Taken never exceeds reserved, given back never exceeds taken, and every entry is in this payment\'s own currency.'];
        }

        $notes = [];

        if ($state->capturedMinor > $state->authorizedMinor) {
            $notes[] = 'More has been taken than was ever reserved. Usually the provider captured more than we reserved, or an authorization callback never arrived. Check the provider\'s own record; if the authorization is real and we simply never heard, replay the callback.';
        }

        if ($state->refundedMinor > $state->capturedMinor) {
            $notes[] = 'More has gone back than was ever taken. Usually a refund issued outside this system — from the provider\'s dashboard — against a capture we never recorded. Replay the capture callback, or record the missing capture through the adapter.';
        }

        if ($state->mismatchedCurrencyEntries > 0) {
            $notes[] = $state->mismatchedCurrencyEntries.' entr'.($state->mismatchedCurrencyEntries === 1 ? 'y is' : 'ies are').' in a currency this payment is not in. This module refuses to write one, so it came from a migration, a restore or a second writer. Those entries are excluded from the totals above, so the numbers are correct but incomplete — investigate the writer.';
        }

        $notes[] = 'Nothing is corrected automatically and there is no button here that would. A correction is a new ledger row, written through the same actions everything else is.';

        return $notes;
    }

    /**
     * What this order has taken across every tender against it.
     *
     * **`PaymentQuery::capturedForOrder()` throws for a mixed-currency order rather
     * than answering, and that refusal is surfaced as a sentence rather than
     * swallowed into a blank cell.** Two tenders in different currencies have no
     * single total, and inventing one would mean inventing a rate — which rate, at
     * which moment, net of which fees, is an accounting decision belonging to
     * whoever owns the books. The module records what was charged and what the
     * provider said it settled, and converts nothing.
     */
    public static function orderTotalNote(Payment $payment): string
    {
        try {
            $total = (new PaymentQuery())->capturedForOrder($payment->order_id);
        } catch (CurrencyMismatch) {
            return 'This order was paid in more than one currency, so there is no single figure for what it took. This module does no conversion: it records what the customer was charged and what the provider said it settled, at the rate the provider gave, and turning those into one number is an accounting decision it deliberately does not make. Read the tenders one at a time.';
        }

        // Null means no payments at all, which cannot happen from a page about
        // one of them — but it is a different answer from zero, and guessing which
        // is the sort of thing that makes a report wrong quietly.
        return $total === null
            ? 'No payments are recorded against this order.'
            : Amount::format($total).' taken across every tender against this order.';
    }

    /**
     * Take everything still reserved.
     *
     * The record is re-read first. Between this page rendering and the button being
     * pressed the ledger may have moved — a second click, a colleague, a queued job,
     * a provider callback — and the domain must be asked about what is in the
     * database rather than about what this page was drawn from. The guard after the
     * re-read is not decoration either: `CapturePayment` with nothing capturable
     * would happily write a capture of zero, and a zero row in a ledger is a fact
     * that never happened.
     */
    private static function capture(Payment $record): void
    {
        $payment = self::reread($record);
        $amount = $payment->state()->capturable();

        if (! $amount->isPositive()) {
            self::nothingToDo('There is nothing left to take on this payment. Somebody or something else has moved it since this page was drawn.');

            return;
        }

        self::record(
            fn (): PaymentResult => (new CapturePayment())->handle(self::instruction($payment, 'capture', $amount)),
            'Taken',
            Amount::format($amount).' has been taken and the ledger says so. Nothing was edited: a capture is a new row.',
        );
    }

    private static function void(Payment $record): void
    {
        $payment = self::reread($record);

        self::record(
            // No amount: a void releases whatever is left reserved, and the domain
            // derives that rather than taking it from a caller — which is why there
            // is no such thing as a partial void here or anywhere.
            fn (): PaymentResult => (new VoidPayment())->handle(self::instruction($payment, 'void', null)),
            'Reservation released',
            'The money is no longer held. Nothing moved and the customer sees nothing on their statement.',
        );
    }

    private static function refund(Payment $record): void
    {
        $payment = self::reread($record);
        $amount = $payment->state()->refundable();

        if (! $amount->isPositive()) {
            self::nothingToDo('There is nothing left to give back on this payment. Somebody or something else has moved it since this page was drawn.');

            return;
        }

        self::record(
            fn (): PaymentResult => (new RefundPayment())->handle(self::instruction($payment, 'refund', $amount)),
            'Given back',
            Amount::format($amount).' is on its way back to the customer. Nothing was edited: a refund is a new row, and the capture it reverses still stands.',
        );
    }

    /**
     * The instruction a button sends, including the key that makes pressing it
     * twice harmless.
     *
     * The key is derived from the payment and from the ledger as it stands, never
     * minted: `panel:PAY-…:capture:5000GBP`. A second press finds nothing left to
     * move and never gets here; two operators racing derive the same string and the
     * domain's unique index lets exactly one of them write. The amount is in the
     * key rather than only in the facts because a later movement of a different
     * size is a different instruction and must not replay this one.
     */
    private static function instruction(Payment $payment, string $operation, ?Money $amount): MovementInput
    {
        return new MovementInput(
            paymentReference: $payment->reference,
            entryKey: 'panel:'.$payment->reference.':'.$operation.($amount === null ? '' : ':'.$amount->minor.$amount->currency),
            amount: $amount,
            // Who pressed it, when the host's ids are integers. Null is honest:
            // attributing a refund to user 1 because an id was a ULID is worse than
            // attributing it to nobody.
            recordedBy: PanelActor::id(),
        );
    }

    /**
     * Run a movement and say what happened, in the domain's own words when it
     * refuses.
     *
     * **Every refusal is surfaced and none is softened.** `ExceedsAuthorization`
     * and `ExceedsCapture` carry the amount that was actually available;
     * `PaymentNotVoidable` names the void-is-not-a-refund distinction;
     * `CurrencyMismatch` says the module does no conversion. A panel that caught one
     * of those and moved what it could would be clamping — turning a loud failure
     * into a short capture the merchant hears about from the customer.
     *
     * `PaymentConflict` and `PaymentInFlight` are told apart by class rather than
     * by reading a message, which is the whole reason the domain ships two: one says
     * never retry and the other says retry shortly, and they are opposite
     * instructions to whoever is standing at the screen.
     *
     * @param  callable(): PaymentResult  $movement
     */
    private static function record(callable $movement, string $title, string $body): void
    {
        try {
            $result = $movement();
        } catch (PaymentInFlight) {
            Notification::make()
                ->title('Somebody else is moving this money')
                ->body('This payment is claimed by a call that has not finished. Wait a moment and try again — it clears on its own.')
                ->warning()
                ->send();

            return;
        } catch (PaymentConflict $exception) {
            Notification::make()
                ->title('That instruction conflicts with one already recorded')
                ->body($exception->getMessage().' Retrying will not help.')
                ->danger()
                ->send();

            return;
        } catch (ExceedsAuthorization|ExceedsCapture|PaymentNotVoidable|CurrencyMismatch|UnknownGateway $exception) {
            Notification::make()
                ->title('Nothing moved')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->recorded) {
            // The key replayed. The domain wrote nothing and announced nothing,
            // which is the guarantee working — and saying so is the difference
            // between a safe surface and one that looks broken.
            Notification::make()
                ->title('Already recorded')
                ->body('This exact movement is already in the ledger, so nothing was written and nothing was announced. If you meant a second movement of the same size, it needs a caller that owns its own idempotency key — a panel cannot tell that apart from a second click.')
                ->warning()
                ->send();

            return;
        }

        if (! $result->approved) {
            // A decline is a fact, not an outage. The domain records it as a
            // `failed` row of zero and the ledger below will show it.
            Notification::make()
                ->title('The provider refused it')
                ->body('The refusal is recorded in the ledger as an attempt, with the provider\'s short code beside it. No money moved.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }

    private static function nothingToDo(string $body): void
    {
        Notification::make()
            ->title('Nothing to do')
            ->body($body)
            ->warning()
            ->send();
    }

    /** The payment as the database has it now, with the ledger the guards fold. */
    private static function reread(Payment $payment): Payment
    {
        $payment->refresh()->load('entries');

        return $payment;
    }

    /**
     * The ids of this team's payments whose ledger says something impossible.
     *
     * The domain's own query, asked rather than reimplemented. It folds in PHP and
     * returns read models; a filter needs a `where`, so the ids come back out of it.
     *
     * ponytail: one fold per payment in the team, the ceiling the domain names on
     * the query itself. The upgrade is a materialised flag written where the entry
     * is appended, never a second copy of the arithmetic as a `HAVING` clause.
     *
     * @return list<int>
     */
    private static function reconciliationIds(): array
    {
        $teamId = PanelActor::teamId();

        if ($teamId === null) {
            return [];
        }

        return (new PaymentQuery())->needingReconciliation($teamId)
            ->map(fn ($payment): int => $payment->id)
            ->all();
    }

    /**
     * The ids of this team's payments raised and never authorized.
     *
     * The runbook's first silent failure: a crash between the provider approving
     * and this module committing leaves exactly this shape, the customer was
     * probably charged, and nothing anywhere errors. Fifteen minutes is the
     * runbook's own threshold — anything younger than that is a payment in
     * progress.
     *
     * @return list<int>
     */
    private static function stalledIds(): array
    {
        $teamId = PanelActor::teamId();

        if ($teamId === null) {
            return [];
        }

        return (new PaymentQuery())->stalledSince(now()->subMinutes(15), $teamId)
            ->map(fn ($payment): int => $payment->id)
            ->all();
    }
}
