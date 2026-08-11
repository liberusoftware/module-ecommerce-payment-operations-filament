<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\PaymentResource\RelationManagers\EntriesRelationManager;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource\Pages\ListProviderCallbacks;
use Liberu\Ecommerce\PaymentOperations\Filament\Support\PanelActor;
use Liberu\Ecommerce\PaymentOperations\Filament\Tests\TestCase;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentInstrument;
use Liberu\Ecommerce\PaymentOperations\Models\ProviderCallback;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('answers no team rather than every team when nobody is signed in', function () {
    expect(PanelActor::teamId())->toBeNull()
        ->and(PanelActor::id())->toBeNull()
        ->and(PaymentResource::getEloquentQuery()->count())->toBe(0)
        ->and(ProviderCallbackResource::getEloquentQuery()->count())->toBe(0);
});

it('never lists the orphan rows a null team id would match', function () {
    $this->actingAs(TestUser::factory()->create());

    aPayment(orderId: 9_000_601, teamId: null);
    aPayment(orderId: 9_000_602, teamId: null);

    // `where('team_id', null)` compiles to `is null`, so an actor with no team
    // would be handed precisely the rows every policy denies everybody. The guard
    // is an explicit `whereRaw('1 = 0')`.
    expect(PaymentResource::getEloquentQuery()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(2);
});

it('refuses another team\'s money and nobody\'s to the team that owns neither', function () {
    $this->actorForTeam(TEAM);

    $orphan = aPayment(orderId: 9_000_611, teamId: null);
    $theirs = aPayment(orderId: 9_000_612, teamId: OTHER_TEAM);

    expect(PaymentResource::getEloquentQuery()->count())->toBe(0)
        ->and(Gate::allows('view', $theirs))->toBeFalse()
        ->and(Gate::allows('view', $orphan))->toBeFalse()
        ->and(Gate::allows('capture', $theirs))->toBeFalse()
        ->and(Gate::allows('void', $theirs))->toBeFalse()
        ->and(Gate::allows('refund', $theirs))->toBeFalse();

    Livewire::test(ListPayments::class)
        ->assertCanNotSeeTableRecords([$orphan, $theirs]);
});

/**
 * The policies' own answers first, so every refusal below reads as deliberately
 * stricter than the domain rather than as dead code standing in for it.
 */
it('says yes to what the policies publish before it overrides anything', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    expect(Gate::allows('viewAny', Payment::class))->toBeTrue()
        ->and(Gate::allows('view', $payment))->toBeTrue()
        ->and(Gate::allows('capture', $payment))->toBeTrue()
        ->and(Gate::allows('void', $payment))->toBeTrue()
        ->and(Gate::allows('viewAny', ProviderCallback::class))->toBeTrue()
        // Nothing captured yet, so there is nothing to give back — the policy asks
        // the ledger as well as the tenancy.
        ->and(Gate::allows('refund', $payment))->toBeFalse()
        ->and(Gate::allows('refund', captured($payment)))->toBeTrue();
});

/**
 * `PaymentPolicy` publishes `viewAny`, `view`, `capture`, `void` and `refund`, and
 * refuses sixteen more by name. Filament's authorization helper returns *allow*
 * when a present policy has no method for the ability asked about, so anything
 * neither answers would default open on a table of other people's money.
 */
it('refuses by name every ability the policies do not publish', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();
    $callback = ProviderCallback::factory()->create(['team_id' => TEAM, 'payment_id' => $payment->id]);

    expect(PaymentResource::canCreate())->toBeFalse()
        ->and(PaymentResource::canEdit($payment))->toBeFalse()
        ->and(PaymentResource::canDelete($payment))->toBeFalse()
        ->and(PaymentResource::canDeleteAny())->toBeFalse()
        ->and(PaymentResource::canForceDelete($payment))->toBeFalse()
        ->and(PaymentResource::canForceDeleteAny())->toBeFalse()
        ->and(PaymentResource::canRestore($payment))->toBeFalse()
        ->and(PaymentResource::canRestoreAny())->toBeFalse()
        ->and(PaymentResource::canReplicate($payment))->toBeFalse()
        ->and(PaymentResource::canReorder())->toBeFalse()
        ->and(ProviderCallbackResource::canCreate())->toBeFalse()
        ->and(ProviderCallbackResource::canEdit($callback))->toBeFalse()
        ->and(ProviderCallbackResource::canDelete($callback))->toBeFalse()
        ->and(ProviderCallbackResource::canDeleteAny())->toBeFalse()
        ->and(ProviderCallbackResource::canForceDelete($callback))->toBeFalse()
        ->and(ProviderCallbackResource::canForceDeleteAny())->toBeFalse()
        ->and(ProviderCallbackResource::canRestore($callback))->toBeFalse()
        ->and(ProviderCallbackResource::canRestoreAny())->toBeFalse()
        ->and(ProviderCallbackResource::canReplicate($callback))->toBeFalse()
        ->and(ProviderCallbackResource::canReorder())->toBeFalse();
});

it('refuses the domain\'s own writes to the team that owns the money', function () {
    $this->actorForTeam(TEAM);

    $payment = captured(aPayment());
    $entry = $payment->entries->last();
    $instrument = PaymentInstrument::query()->firstOrFail();

    // Including the four that are live on a `hasMany` and default open, and
    // including `detach` on the instrument — the domain renamed its own instrument
    // ability to `detachInstrument` after discovering that a subclass method wins
    // over the trait's and silently reopened Filament's. Nothing in this package
    // publishes an ability that could collide with one of these names again.
    expect(Gate::allows('create', Payment::class))->toBeFalse()
        ->and(Gate::allows('update', $payment))->toBeFalse()
        ->and(Gate::allows('delete', $payment))->toBeFalse()
        ->and(Gate::allows('update', $entry))->toBeFalse()
        ->and(Gate::allows('delete', $entry))->toBeFalse()
        ->and(Gate::allows('associate', $entry))->toBeFalse()
        ->and(Gate::allows('disassociate', $entry))->toBeFalse()
        ->and(Gate::allows('attach', $entry))->toBeFalse()
        ->and(Gate::allows('detach', $entry))->toBeFalse()
        ->and(Gate::allows('detach', $instrument))->toBeFalse()
        ->and(Gate::allows('update', $instrument))->toBeFalse()
        ->and(Gate::allows('delete', $instrument))->toBeFalse();
});

/**
 * The ledger's relation manager, where every ability would default open.
 */
it('forces every write off the ledger', function () {
    $this->actorForTeam(TEAM);

    $payment = captured(aPayment());

    $component = Livewire::test(EntriesRelationManager::class, [
        'ownerRecord' => $payment,
        'pageClass' => ViewPayment::class,
    ])->instance();

    $can = fn (string $method, mixed ...$arguments): bool => (bool) (new ReflectionMethod($component, $method))
        ->invoke($component, ...$arguments);

    expect($component->isReadOnly())->toBeTrue()
        ->and($can('canViewAny'))->toBeTrue()
        ->and($can('canCreate'))->toBeFalse()
        ->and($can('canEdit', $payment))->toBeFalse()
        ->and($can('canDelete', $payment))->toBeFalse()
        ->and($can('canDeleteAny'))->toBeFalse()
        ->and($can('canForceDelete', $payment))->toBeFalse()
        ->and($can('canForceDeleteAny'))->toBeFalse()
        ->and($can('canRestore', $payment))->toBeFalse()
        ->and($can('canRestoreAny'))->toBeFalse()
        ->and($can('canReplicate', $payment))->toBeFalse()
        ->and($can('canReorder'))->toBeFalse()
        // The two that are live on a `hasMany` and default open. A ledger entry
        // associated onto a different payment moves somebody else's money, and on
        // an append-only table it could not be taken back.
        ->and($can('canAssociate'))->toBeFalse()
        ->and($can('canDissociate', $payment))->toBeFalse()
        ->and($can('canDissociateAny'))->toBeFalse()
        ->and($can('canAttach'))->toBeFalse()
        ->and($can('canDetach', $payment))->toBeFalse()
        ->and($can('canDetachAny'))->toBeFalse();
});

it('keeps the ledger away from another team\'s payment and from nobody\'s', function () {
    $this->actorForTeam(TEAM);

    $theirs = aPayment(orderId: 9_000_621, teamId: OTHER_TEAM);
    $orphan = aPayment(orderId: 9_000_622, teamId: null);

    foreach ([$theirs, $orphan] as $payment) {
        expect(EntriesRelationManager::canViewForRecord($payment, ViewPayment::class))->toBeFalse();
    }
});

/**
 * The schema promise this package is presenting: there is no column that could
 * hold an instrument, and none that could hold a secret. A surface cannot leak
 * what a table cannot hold, and this is the assertion that says the promise is
 * still true of the tables underneath.
 */
it('presents tables that have nowhere to put an instrument or a secret', function () {
    foreach (['details', 'card_number', 'pan', 'cvv', 'iban', 'account_number', 'secret', 'signing_secret', 'api_key', 'payload', 'raw', 'body'] as $column) {
        expect(Schema::hasColumn('ecommerce_payment_instruments', $column))->toBeFalse($column)
            ->and(Schema::hasColumn('ecommerce_payment_payments', $column))->toBeFalse($column)
            ->and(Schema::hasColumn('ecommerce_payment_callbacks', $column))->toBeFalse($column);
    }

    // And the positive half, so the loop above is not passing against a table that
    // does not exist: the four that do exist are what a receipt needs.
    expect(Schema::hasColumn('ecommerce_payment_instruments', 'provider_token'))->toBeTrue()
        ->and(Schema::hasColumn('ecommerce_payment_instruments', 'last_four'))->toBeTrue()
        ->and(Schema::hasColumn('ecommerce_payment_callbacks', 'body_digest'))->toBeTrue();
});

it('puts nothing sensitive into the query string, because search and filters both persist there', function () {
    $this->actorForTeam(TEAM);

    aPayment();

    $searchable = fn (array $columns): array => array_values(array_map(
        fn ($column): string => $column->getName(),
        array_filter($columns, fn ($column): bool => $column->isSearchable()),
    ));

    $payments = Livewire::test(ListPayments::class)->instance()->getTable();
    $callbacks = Livewire::test(ListProviderCallbacks::class)->instance()->getTable();

    // A search term and a filter's state are both persisted into the URL, and a
    // query string is written into every access log on the path. So the only
    // searchable columns are a public payment reference and an opaque provider
    // event id — the two things an operator quotes down a telephone or copies out
    // of a provider's dashboard.
    expect($searchable($payments->getColumns()))->toBe(['reference'])
        ->and($searchable($callbacks->getColumns()))->toBe(['provider_event_id'])
        ->and(array_keys($payments->getFilters()))->toBe(['needs_reconciliation', 'stalled'])
        ->and(array_keys($callbacks->getFilters()))->toBe(['needs_attention', 'status'])
        // Neither the provider's token nor the body digest is a column anywhere.
        ->and(array_keys($payments->getColumns()))->not->toContain('provider_token')
        ->and(array_keys($callbacks->getColumns()))->not->toContain('body_digest')
        ->and(array_keys($callbacks->getColumns()))->not->toContain('provider_token');
});

it('renders a brand and a last four, and never the token behind them', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    $instrument = PaymentInstrument::query()->firstOrFail();

    // The token really is in the database, so the refusals below are refusals
    // rather than assertions about an empty column.
    expect($instrument->provider_token)->toBe(TestCase::PROVIDER_TOKEN)
        ->and($payment->instrument_last_four)->toBe('4242');

    Livewire::test(ViewPayment::class, ['record' => $payment->reference])
        ->assertOk()
        // What a receipt needs, on a page the policy guards.
        ->assertSee('4242')
        ->assertSee('test-brand')
        ->assertSee('12/2030')
        // What moves money when presented to the provider, and what a callback is
        // signed with. Neither is a column this package renders, and the secret is
        // not a column at all.
        ->assertDontSee(TestCase::PROVIDER_TOKEN)
        ->assertDontSee(TestCase::SIGNING_SECRET);

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertDontSee(TestCase::PROVIDER_TOKEN)
        ->assertDontSee(TestCase::SIGNING_SECRET);
});

it('renders no callback body and no digest of one', function () {
    $this->actorForTeam(TEAM);

    $payment = aPayment();

    aCallback('evt-secret-1', 'payment.disputed', $payment->provider_reference);

    $callback = ProviderCallback::query()->where('provider_event_id', 'evt-secret-1')->firstOrFail();

    // The digest is stored — it proves two deliveries were the same delivery — and
    // it is not evidence a person can read. Rendering it invites somebody to treat
    // it as though it were.
    expect($callback->body_digest)->not->toBeEmpty();

    Livewire::test(ListProviderCallbacks::class)
        ->assertOk()
        ->assertSee('evt-secret-1')
        ->assertDontSee($callback->body_digest)
        ->assertDontSee(TestCase::SIGNING_SECRET);
});

it('never lets a token, a secret or a digest reach a log line', function () {
    $this->actorForTeam(TEAM);

    $records = captureLog();

    $payment = aPayment();

    aCallback('evt-logged-1', 'payment.disputed', $payment->provider_reference);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('capture')->table($payment));

    $callback = ProviderCallback::query()->where('provider_event_id', 'evt-logged-1')->firstOrFail();

    $written = json_encode($records(), JSON_THROW_ON_ERROR);

    expect($written)->not->toContain(TestCase::PROVIDER_TOKEN)
        ->and($written)->not->toContain(TestCase::SIGNING_SECRET)
        ->and($written)->not->toContain($callback->body_digest)
        ->and($written)->not->toContain('4242')
        // Proof the capture works at all, so the four refusals above are not
        // passing against an empty array.
        ->and($written)->toContain('payment.captured')
        ->and($written)->toContain($payment->reference);
});
