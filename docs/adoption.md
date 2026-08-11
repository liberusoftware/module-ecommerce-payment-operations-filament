# Adoption

Installing this package, enabling it, attaching it to a panel, and what the host has to supply.

## 1. Install

The domain package this presents is **not on Packagist**. Composer honours `repositories` only from
the root manifest, so the entry goes in the *application's* `composer.json`, not in this package's —
this package declares it for its own CI and that declaration does nothing for a consumer.

```bash
composer config repositories.ecommerce-payment-operations vcs https://github.com/liberusoftware/module-ecommerce-payment-operations
composer require liberusoftware/ecommerce-payment-operations-filament
```

That pulls `liberusoftware/ecommerce-payment-operations` with it. When the domain package reaches
Packagist, the `composer config repositories.*` line is the only thing to remove.

## 2. Enable the modules

Installing boots nothing: neither package ships `extra.laravel.providers`, so Composer discovery finds
no provider. `ModuleManagerServiceProvider` registers the provider each `module.json` names, and only
when the deployment asks for it:

```dotenv
MODULES_ENABLED=ecommerce-payment-operations,ecommerce-payment-operations-filament
```

Both, in that order. The presentation package registers nothing of its own — no migrations, no
policies, no config — so enabling it without the domain module gives you two resources with no tables
to query and no gate to ask.

## 3. Migrate

The domain module's four migrations are loaded by `PaymentOperationsServiceProvider`:

```bash
php artisan migrate
```

| Table | Holds |
| --- | --- |
| `ecommerce_payment_instruments` | A provider token, a brand, a last four, an expiry. Nothing else can fit |
| `ecommerce_payment_payments` | A tender against an order. **No status column** |
| `ecommerce_payment_entries` | The ledger. Append-only |
| `ecommerce_payment_callbacks` | What a provider delivered, and what we did about it. **Never the body** |

**The host's `payment_methods` table is not adopted, and it is a live leak.** `CONFORMANCE.md`
records `PaymentMethodController` returning raw `details` to the client, on a model with no `$hidden`
and no cast. The domain's own [`docs/adoption.md`](https://github.com/liberusoftware/module-ecommerce-payment-operations/blob/main/docs/adoption.md)
§1 is the migration for it, in order, and it is not optional. Do that before this panel is worth
looking at.

No foreign key leaves the four tables. `order_id`, `customer_id`, `team_id` and `recorded_by` are
plain columns, so nothing here needs the module that owns orders to be installed.

## 4. Configure a gateway

This package names no provider and neither does the domain. The domain resolves an implementation of
`Contracts\PaymentGateway` by configured class name at call time:

```php
// config/payment-operations.php
'gateways' => [
    'card' => [
        'class' => \App\Payments\CardGateway::class,
        'signing_secret' => env('PAYMENT_CARD_SIGNING_SECRET'),
    ],
],
```

The panel needs this because **capture, void and refund all call the provider**. Without it, pressing
a button produces the domain's own `UnknownGateway` message in a notification rather than a stack
trace — which is a fair surface for a misconfiguration and a poor substitute for a gateway.

The **signing secret is configuration**, from the environment. It is deliberately not a database
column, and nothing in this package reads it.

## 5. Attach the plugin to a panel

The application owns its panels; this package never registers itself into one.

```php
use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\PaymentOperations\Filament\PaymentOperationsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([
                PaymentOperationsPlugin::make(),
            ]);
    }
}
```

`module.json` declares the plugin under `presentation.filament.admin`, which is the panel id this
package is tested against — but nothing enforces it. Attach it to whichever panel should carry money,
and to more than one if that is what the deployment needs.

The panel does **not** need to be tenant-aware. Both resources scope on the actor's `current_team_id`
rather than on `Filament::getTenant()`, and `isScopedToTenant()` is `false`. It does not need
`readOnlyRelationManagersOnResourceViewPages()` either: the ledger's relation manager answers
`isReadOnly()` itself, because read-only here is this package's rule and not the application's
setting.

## 6. Getting payments into the panel in the first place

This package shows payments. Something has to raise them, and it is not this package and not the
domain module either — **the domain subscribes to nothing**, because listening to another module's
event means importing a class from a sibling package. The host is the only place entitled to know
that both modules exist.

```php
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Checkout\Events\CheckoutCompleted;
use Liberu\Ecommerce\PaymentOperations\Actions\AuthorizePayment;
use Liberu\Ecommerce\PaymentOperations\Data\{AuthorizationInput, Money};

Event::listen(CheckoutCompleted::class, function (CheckoutCompleted $event): void {
    (new AuthorizePayment())->handle(new AuthorizationInput(
        orderId: $event->checkout->orderId,
        // The checkout's own key, reused deliberately: a fresh key per attempt is an
        // idempotency key that guarantees nothing.
        paymentKey: $event->checkout->reference,
        gateway: config('payments.default'),
        amount: new Money($event->checkout->grandTotalMinor, $event->checkout->currency),
        customerId: $event->checkout->customerId,
        teamId: $event->checkout->teamId,
        checkoutReference: $event->checkout->reference,
    ));
});
```

That is why this panel has no create button: a button would mint a fresh key on every press, and on
this table that reserves a customer's money twice.

The other two listeners — *money was taken, so the order is paid* and *money went back* — and the
callback route are in the domain's `docs/adoption.md` §5. **The callback route is not optional if you
want the reconciliation inbox to be about anything**: it is what fills
`ecommerce_payment_callbacks`.

## 7. What the host has to supply

| Thing | Why | What happens without it |
| --- | --- | --- |
| A `current_team_id` on the authenticated user, as an **integer** | It is the whole of every policy's tenancy, and the attribute both resource queries scope on | Every list is empty and every badge is absent. Not an error — the deliberate answer for an actor working in no team. `is_numeric` is the guard, so a ULID or UUID id fails closed and silently |
| `MODULES_ENABLED` naming both modules | Installation never implies boot | The resources exist as classes and appear nowhere |
| A `team_id` on the payments it raises | A payment with `team_id` null belongs to nobody, and every policy denies every action on one | Those rows are invisible in this panel, by design. `AuthorizationInput` takes a `teamId`; pass it |
| A configured gateway class | Capture, void and refund all call the provider | Every button reports `UnknownGateway` in a notification |
| The listeners and the callback route in §6 | Nothing crosses a module boundary by itself | Nothing is ever raised, and the callbacks list stays empty |
| Colour aliases `success`, `warning`, `danger`, `info`, `gray` on the panel | Badge, button and notification colours | Filament's defaults apply |

Optional:

| Thing | Effect |
| --- | --- |
| `PAYMENT_OPERATIONS_TEAM_MODEL`, if the host's team model is not the Jetstream default | The domain resolves it from config at call time and never imports it. Only matters if something eager-loads the `team` relation; nothing in this package does |
| `PAYMENT_OPERATIONS_TELEMETRY=true` | The domain's own event logger starts recording authorizations, captures, voids, refunds and failures — at `error` for any payment whose ledger needs reconciliation, so an alert can key off the level. Off by default; a busy checkout writes thousands an hour. No provider token, signing secret, brand, last four, customer id or checkout reference is ever written |
| `PAYMENT_OPERATIONS_TELEMETRY_CHANNEL` | Sends those records to a named log channel instead of the default one |

## 8. What it does not bring

- **No way to set a state, and no way to type an amount.** There is no status column and no form
  input of any kind in this package.
- **No way to create, edit or delete anything.** The only writes are the three movements, and each
  appends a row.
- **No partial capture or partial refund.** Both need an idempotency key belonging to whoever decided
  the amount. See [domain.md §3](domain.md#3-no-amount-is-ever-typed-here).
- **No saved-instrument surface.** See [domain.md §10](domain.md#10-what-this-package-deliberately-does-not-surface).
- **No unmatched-callback queue.** Those rows belong to no team; read them from a console command.
  Same section.
- **No callback replay and no callback deletion.** A replay belongs to the provider's dashboard.
- **No repair of an impossible ledger.** It is reported and never clamped.
- **No netting, no FX, no fees, no disputes, no subscriptions.** The domain owns none of them.
- **No storefront.** Everything here is the operator's side.
- **No scheduled sweep.** Nothing in either package runs on a timer; see [runbook.md](runbook.md).

## Upgrading

This is the first release; there is nothing to upgrade from.
