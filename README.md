# Payment Operations Administration

Filament administration for [`liberusoftware/ecommerce-payment-operations`](https://github.com/liberusoftware/module-ecommerce-payment-operations).

An operator surface over money that has moved. It shows what a payment's ledger folds to, the ledger
itself, and the three movements the domain publishes — and it offers no way to set a payment to
anything, because there is no status column to set.

```bash
composer config repositories.ecommerce-payment-operations vcs https://github.com/liberusoftware/module-ecommerce-payment-operations
composer require liberusoftware/ecommerce-payment-operations-filament
```

```dotenv
MODULES_ENABLED=ecommerce-payment-operations,ecommerce-payment-operations-filament
```

```php
use Liberu\Ecommerce\PaymentOperations\Filament\PaymentOperationsPlugin;

$panel->plugins([
    PaymentOperationsPlugin::make(),
]);
```

Full instructions, including what the host has to supply, are in [docs/adoption.md](docs/adoption.md).

## What an operator can do

| | |
| --- | --- |
| Read a payment, and every figure the ledger folds to | Yes |
| Read the ledger itself — every event, both clocks, who recorded it | Yes |
| Read the brand, the last four and the expiry that paid for it | Yes |
| Read what a provider has delivered about this team's payments | Yes |
| **Take the money that is reserved** | Yes — all of it |
| **Release the reservation**, while nothing has been taken | Yes |
| **Give the money back** | Yes — all of what is still refundable |
| Set a payment's state, or edit any figure on it | **No.** There is no status column and no form in this package |
| Type a partial amount to capture or refund | **No** — see below |
| Raise a payment | **No** — the domain policy denies it outright |
| Edit or delete a payment, a ledger entry or a callback | **No** — the ledger is append-only in three layers |
| Correct an impossible ledger | **No.** It is reported and never clamped |
| See a card number, an account number, a provider token or a signing secret | **No.** No column in this module can hold the first two, and this package renders neither of the last two |
| Read the queue of callbacks that matched no payment | **No** — they belong to no team. The runbook reads them from a console |

Every one of those refusals is argued in [docs/domain.md](docs/domain.md).

## There is no status field, because there is no status

`ecommerce_payment_payments` has no `status` column, no `captured_minor` and no `refunded_minor`. The
domain's own `SchemaTest` asserts each of them absent **by name**, so a convenience column cannot
quietly reappear. Every figure this panel shows is `PaymentState::fold()` over the ledger, computed as
the page was drawn:

```
Reserved     GBP 50.00      sum of the authorized rows
Taken        GBP 20.00      sum of the captured rows
Given back   GBP  0.00      sum of the refunded rows
Still takeable GBP 30.00    reserved − taken, floored at zero
```

The host in this repository is the argument. `CONFORMANCE.md` records `OrderResource` exposing
`payment_status` as a free `Select` beside an editable `total_amount`, bypassing `Order::TRANSITIONS`
entirely — so a staff member can mark an order paid with no money having moved, and nothing anywhere
records that they did. **This panel exists to not be that.** It has no form, no create page, no edit
page and no delete, and the ledger under every number is on the same screen so a person can add it up.

## Three movements, and no amount to type

**Take the money** captures everything still reserved. **Give the money back** refunds everything
still refundable. **Release the reservation** voids, and stops being offered the moment anything has
been taken — a void and a refund are different operations with different costs, and this module
refuses to decide for a merchant which of the two happened.

There is **no amount input anywhere in this package**, and no partial capture or partial refund from
this surface. Two reasons:

1. A money field is where a typo becomes a charge.
2. **An idempotency key belongs to whoever decided the amount.** A partial capture is somebody's
   decision — a parcel shipped, a line went out of stock — and a button cannot own the key for a
   decision it did not make.

The key a button *does* use is derived and never minted: `panel:PAY-…:capture:5000GBP`. A second press
re-reads the ledger, finds nothing left to move and says so; two operators racing derive the same
string and the domain's unique index lets exactly one of them write. The consequence is stated rather
than discovered: a panel cannot tell a second identical instruction from a second click, so a second
identical movement needs a caller that owns its own key.

## The two queues nobody was reading

The domain's runbook names three failures nobody gets paged about. Two of them are queues, and this
package puts both where somebody will see them:

- **Ledger does not add up** — a filter and a red badge, from `PaymentQuery::needingReconciliation()`.
  Only a provider-origin row can put a payment there, because everything written on a caller's behalf
  is guarded. The page names which invariant broke, in the runbook's words, and **corrects nothing**:
  there is no clamp, no repair job and no button that would be one. A correction is a new row.
- **Raised and never authorized** — a filter, from `PaymentQuery::stalledSince()`. A crash between the
  provider approving and the module committing leaves exactly this shape, with no error anywhere and a
  customer who was probably charged.

The **provider callbacks** list is the third: everything a provider delivered about this team's
payments, with a warning badge counting the ones nobody has dealt with, straight from
`PaymentQuery::unhandledCallbacks()`.

## It will not net across currencies, and it says so

`PaymentQuery::capturedForOrder()` throws rather than answering for a mixed-currency order, and this
panel surfaces the refusal as a sentence rather than swallowing it into a blank cell:

> This order was paid in more than one currency, so there is no single figure for what it took. This
> module does no conversion: it records what the customer was charged and what the provider said it
> settled, at the rate the provider gave, and turning those into one number is an accounting decision
> it deliberately does not make.

Settlements are shown beside the presentment amount in the ledger, never instead of it and never
summed with it.

## What never reaches a surface

- **No instrument.** There is no column in this module that could hold a card number, a security code
  or an account number — a stronger guarantee than hiding one, and the reason `$hidden` appears
  nowhere in this package. What is rendered is a brand, a last four and an expiry.
- **No provider token.** It is credential-shaped: presented to the provider it moves money. It is not
  a column, an entry, a filter or a search term anywhere here, and saved instruments are not surfaced
  at all.
- **No signing secret.** It is configuration, never a database column, and nothing here reads it.
- **No callback body, and no digest of one.** The domain stores only a SHA-256, and a digest is not
  evidence a person can read.
- **Nothing sensitive is searchable or filterable**, because a search term and a filter's state are
  both persisted into the query string and written into every access log on the path. The only
  searchable columns are a public payment reference and an opaque provider event id.
- **A payment is bound by its reference and never by its id**, because an incrementing id in a URL is
  an enumeration of everybody else's payments.

`tests/Feature/SecurityTest.php` asserts each of these, with a positive assertion beside every
refusal so none of them can pass against an empty page or an empty log.

## No provider names

There is not one anywhere in `src/`. The domain integrates with nobody and resolves a gateway by
configured class name; a panel over it that named a provider would have to be released the day a
merchant signs with somebody else. A test greps for seventeen of them on a word boundary.

## Compatibility

PHP 8.5, Laravel 13, Filament 5. The plugin does not require the panel to be tenant-aware.

## Documentation

- [docs/domain.md](docs/domain.md) — what this surface shows, what it refuses, and why each refusal.
- [docs/adoption.md](docs/adoption.md) — installing, enabling, attaching, and what the host supplies.
- [docs/runbook.md](docs/runbook.md) — the questions this panel exists to answer, in order.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
