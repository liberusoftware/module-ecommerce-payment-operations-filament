<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\PaymentOperations\Enums\CallbackStatus;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource\Pages\ListProviderCallbacks;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\PanelActor;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\Wording;
use Liberu\Ecommerce\PaymentOperations\Models\ProviderCallback;
use Liberu\Ecommerce\PaymentOperations\Queries\PaymentQuery;
use UnitEnum;

/**
 * What a provider delivered, and what this module did about it.
 *
 * **This is the reconciliation inbox**, and it exists because the domain's runbook
 * names "an unmatched callback queue nobody is reading" as one of three failures
 * nobody will be paged about. The symptom is payments that never advance while the
 * provider's dashboard shows them completing, and there is no error anywhere.
 *
 * Two statuses mean somebody should look, and the domain says which:
 *
 * - **`unmatched`** — a callback naming a `provider_reference` we do not have.
 *   Check the stalled-payment queue on the payments list first: a payment that
 *   crashed between the provider approving and this module committing has no
 *   provider reference stored, so its callbacks cannot match. Once its retry
 *   completes it, replay the callback from the provider's dashboard.
 * - **`unrecognised`** — the provider has started sending an event type the
 *   adapter does not map. A decision rather than a fault: add the mapping in the
 *   adapter's `verify()` if the event matters, and replay.
 *
 * Both were answered `2xx` when they arrived, deliberately. Answering `5xx` would
 * turn the provider's retry queue into an outage at the moment something is already
 * wrong — over a callback about somebody else's test-mode payment.
 *
 * ## The unmatched ones are not in this list, and that is the honest answer
 *
 * A callback is stamped with the team of the payment it matched, so **an unmatched
 * callback belongs to no team** — `team_id` is null on every one of them.
 * `ProviderCallbackPolicy` denies every action on an unowned row and this resource
 * scopes to the actor's team, so they cannot appear here.
 *
 * That is a refusal rather than an oversight. Showing them would mean this package
 * writing a second tenancy answer that contradicts the domain's, and the row would
 * be a leak: a callback we could not match might be about anybody's money, and
 * there is no attribute on it that says whose. The unowned queue is read from a
 * console command where there is no actor, which is what the runbook says and what
 * {@see ListProviderCallbacks::getSubheading()} says on the page — because a queue
 * nobody knows about is the failure this resource was built for.
 *
 * ## There is no body here, and no digest either
 *
 * The domain stores no callback body — not raw, not parsed, not partial — because
 * some providers include an instrument descriptor in one, and a schema promising no
 * column can hold an instrument cannot also hold arbitrary provider JSON. What it
 * stores is a SHA-256 of the bytes, and that is not rendered either: a digest is
 * machinery for proving two deliveries were the same delivery, it is not evidence
 * a person can read, and putting it on a screen invites somebody to treat it as
 * one. The signature is not stored at all.
 */
class ProviderCallbackResource extends Resource
{
    protected static ?string $model = ProviderCallback::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Provider callbacks';

    protected static ?string $modelLabel = 'provider callback';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * A callback row is written by `RecordProviderCallback` and by nothing else.
     * `ProviderCallbackPolicy` refuses every write ability by name; these restate
     * the refusal so it does not depend on which file is read first.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** What a provider sent is what a provider sent. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * The dedupe that makes a provider's retry harmless is a row in this table.
     * Deleting one lets the same delivery be processed again — the ledger's own
     * derived entry key is the second belt, but the first is this row existing.
     */
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
     * The team's callbacks, newest first.
     *
     * Unowned rows are excluded by `PanelActor::scope`, which uses an explicit
     * `whereRaw('1 = 0')` for an actor with no team rather than
     * `where('team_id', null)` — the latter compiles to `is null` and would hand
     * somebody precisely the rows the policy denies everybody.
     *
     * @return Builder<ProviderCallback>
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scope(parent::getEloquentQuery());
    }

    /** There is no form. Nothing a provider sent is anybody's to edit. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When we heard')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('occurred_at')
                    ->label('When it happened')
                    ->dateTime()
                    // The provider's own clock. The gap between the two columns is
                    // the interesting number when a callback arrives late, which is
                    // why they are not collapsed into one.
                    ->placeholder('Not stated')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('What we did')
                    ->badge()
                    ->formatStateUsing(fn (CallbackStatus $state): string => Wording::callbackStatus($state))
                    ->color(fn (CallbackStatus $state): string => Wording::callbackColour($state))
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Event type')
                    // The provider's vocabulary, kept out of the domain and shown
                    // here as the string it is. An unmapped one is what
                    // `unrecognised` means.
                    ->wrap(),
                TextColumn::make('payment.reference')
                    ->label('Payment')
                    ->placeholder('None matched'),
                TextColumn::make('provider_event_id')
                    // The number an operator copies out of the provider's dashboard
                    // to match a delivery, and the only searchable column here. It
                    // is an opaque event identifier, not a credential and not a
                    // person: the dedupe key is `(gateway, provider_event_id)`, and
                    // holding it lets somebody look an event up in the provider's
                    // own console and nothing more.
                    ->label('Provider event')
                    ->searchable(),
                TextColumn::make('gateway')
                    ->label('Gateway')
                    ->toggleable(),
                TextColumn::make('provider_reference')
                    ->label('Provider reference')
                    ->placeholder('None')
                    ->toggleable(),
                TextColumn::make('provider_sequence')
                    ->label('Provider sequence')
                    // Kept because the ledger reads in the provider's order, not
                    // ours. Nothing in the arithmetic depends on it: the fold is
                    // commutative, which is why an out-of-order delivery is a
                    // non-event rather than a bug report.
                    ->placeholder('Not stated')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('needs_attention')
                    ->label('Somebody should look at these')
                    // The domain's own scope, called by name rather than rewritten.
                    // A second copy of "needs attention" here would be a second
                    // answer waiting to disagree.
                    ->query(fn (Builder $query): Builder => $query->scopes('needingAttention')),
                SelectFilter::make('status')
                    ->label('What we did')
                    ->options(Wording::callbackOptions()),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProviderCallbacks::route('/'),
        ];
    }

    /**
     * How many of this team's callbacks nobody has dealt with.
     *
     * The domain's published query, asked with the actor's team exactly as the
     * runbook asks it. A badge is the cheapest thing that turns a queue nobody is
     * reading into a queue somebody is.
     */
    public static function getNavigationBadge(): ?string
    {
        $teamId = PanelActor::teamId();

        if ($teamId === null) {
            return null;
        }

        $count = (new PaymentQuery())->unhandledCallbacks($teamId)->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
