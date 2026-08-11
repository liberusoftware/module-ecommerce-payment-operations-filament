# What this surface shows, and everything it refuses

Written for somebody deciding whether a missing button is a bug. It is mostly a list
of refusals, because for a ledger the correct answer to almost every ability is no.

The domain's own reasoning lives in
[`docs/domain.md`](https://github.com/liberusoftware/module-ecommerce-payment-operations/blob/main/docs/domain.md)
of the module this presents. This document is about the panel.

---

## 1. There is no status field, because there is no status

`ecommerce_payment_payments` has no `status` column, no `captured_minor` and no
`refunded_minor`. The domain's `SchemaTest` asserts each of them absent **by name**, so
the decision cannot be quietly undone by a convenience column, and every figure this
panel shows is `PaymentState::fold()` over `ecommerce_payment_entries` at the moment
the page was drawn.

So this package has **no form, no create page, no edit page and no delete**. Not "not
yet": there is nothing on a payment a person may set.

### The host is the argument

`CONFORMANCE.md` records `OrderResource` exposing `payment_status` as a free `Select`
beside an editable `total_amount`, bypassing `Order::TRANSITIONS` entirely. Two
failures follow from one cause:

- A staff member can mark an order paid with no money having moved.
- Nothing anywhere records that they did it, so the number and the events that were
  supposed to produce it can differ with nothing to say which is right.

A derived state cannot do that, and a panel over a derived state has nothing to offer
that would. The ledger sits under the totals on the same page precisely so a person
who disagrees with a number can add it up.

### What it costs

`state()` folds on every call. `PaymentResource::getEloquentQuery()` eager-loads
`entries` for exactly that reason — without it, every column on every row is a query.
The two queue filters and the reconciliation badge fold every payment in the team;
that is the ceiling the domain names on `PaymentQuery::needingReconciliation()` itself,
and both call sites carry a `ponytail:` note repeating the upgrade path: a
materialised flag written where the entry is appended, never a second implementation
of the fold in SQL.

---

## 2. The write surface is three movements, and none of them is a field

| Button | Calls | Offered when |
| --- | --- | --- |
| Take the money | `CapturePayment` | `PaymentPolicy::capture()` — tenancy **and** something still capturable |
| Release the reservation | `VoidPayment` | `PaymentPolicy::void()` — tenancy, nothing captured, something authorized |
| Give the money back | `RefundPayment` | `PaymentPolicy::refund()` — tenancy **and** something still refundable |

Three separate abilities rather than one `update`, because they are different-sized
mistakes: one takes money, one gives it back, one costs a fee and one does not.

Each is gated on the domain's own answer as well as on tenancy, so a staff member
holding the ability still cannot get round an invariant — and the button is absent
rather than present and throwing. A button that always throws is a worse surface than
no button.

### The re-read, which is not decoration

Between a page rendering and a button being pressed the ledger may have moved: a second
click, a colleague on another screen, a queued job, a provider callback. So every
action re-reads the payment and its entries before calling the domain, and asks the
domain about what is in the database rather than about what the page was drawn from.

The guard after the re-read matters too. `CapturePayment` with nothing capturable would
write a capture of **zero** — a row in a ledger for something that never happened — so
the panel checks and says "nothing to do" instead.

---

## 3. No amount is ever typed here

The domain supports partial captures and partial refunds. This panel offers neither.
Capture takes everything still reserved; refund gives back everything still refundable.

1. **A money field is where a typo becomes a charge.** There is no amount input
   anywhere in this package — no `TextInput`, no `Select`, no input of any kind — and
   `tests/Feature/BoundaryTest.php` greps the source to keep it that way.
2. **An idempotency key belongs to whoever decided the amount.** A partial capture is
   somebody's decision about goods, and the key is what names that decision. A button
   cannot own a key for a decision it did not make.

A deployment that needs partial movements makes them through a caller that owns its
key: the domain's actions directly, the `-api` package, or a host listener.

---

## 4. The key a button uses, and what pressing twice does

```
panel:{reference}:capture:{minor}{currency}
panel:{reference}:refund:{minor}{currency}
panel:{reference}:void
```

Derived from the payment and from the ledger as it stands at the press, never minted
fresh. `PaymentPolicy::create()` is permanently false for the same reason: a fresh key
per press is exactly what an idempotency key is not.

- **A double click** never reaches the domain twice: the first press moves the money,
  the second re-reads, finds nothing capturable and says so.
- **Two operators racing** derive the same key, and the domain's unique index lets
  exactly one of them write. The other replays and is told so.

### The consequence, stated rather than discovered

**A panel cannot tell a second identical instruction from a second click.** Capture
£30, refund £30, capture £30 again, refund £30 again — the second refund derives the
same key as the first, replays, and writes nothing. The notification says so and says
what to do about it: a second identical movement needs a caller that owns its own key.

Failing closed is the correct direction for money. A surface that guessed would
sometimes guess "charge them again".

---

## 5. Refusals are surfaced, never softened

`ExceedsAuthorization` and `ExceedsCapture` carry the amount that was actually
available. `PaymentNotVoidable` names the void-is-not-a-refund distinction.
`CurrencyMismatch` says the module does no conversion. `UnknownGateway` says which
configuration key is missing. Every one of them reaches the operator as written.

A panel that caught one and moved what it could would be **clamping** — turning a loud
failure into a short capture the merchant hears about from the customer.

`PaymentConflict` and `PaymentInFlight` are caught **separately, by class**, which is
the whole reason the domain ships two: one says never retry and the other says retry
shortly, and they are opposite instructions to whoever is standing at the screen. A
surface that read a message to tell them apart would be the seam wave 4 shipped and
wave 5 fixed.

---

## 6. Reconciliation is reported and never corrected

A payment lands in the queue when its ledger says something arithmetically impossible.
Only a **provider-origin** row can put it there, because everything written on a
caller's behalf is guarded — which is why the ledger's *who recorded it* column is
where to look.

The page names which invariant broke:

| What the fold says | The page says | Runbook |
| --- | --- | --- |
| `captured > authorized` | More has been taken than was ever reserved | Check the provider's record; replay the authorization callback if it is real |
| `refunded > captured` | More has gone back than was ever taken | A refund issued from the provider's dashboard against a capture we never recorded |
| `mismatchedCurrencyEntries > 0` | *n* entries in a currency this payment is not in | A migration, a restore or a second writer. Those entries are excluded from the totals |

**There is no button that would fix any of it**, and there is no clamp. The domain
reports rather than clamping because a wrong number nobody is told about is the worst
outcome available to a module whose job is being told; a panel that offered a
correction would be undoing that decision from the outside. A correction is a new
ledger row, written through the same actions everything else is.

`capturable()` is floored at zero rather than reported negative, so an over-captured
payment admits **no further captures**. That is the safe direction and it is the
domain's choice, not this package's.

---

## 7. It will not net across currencies

`PaymentQuery::capturedForOrder()` throws `CurrencyMismatch` for a mixed-currency order
rather than answering. This panel catches it **into a sentence**, not into a blank cell:

> This order was paid in more than one currency, so there is no single figure for what
> it took. This module does no conversion: it records what the customer was charged and
> what the provider said it settled, at the rate the provider gave, and turning those
> into one number is an accounting decision it deliberately does not make. Read the
> tenders one at a time.

Which rate, at which moment, net of which fees, is an accounting decision belonging to
whoever owns the books. A settlement is shown in the ledger **beside** the presentment
amount and never instead of it, with the rate as the string the provider gave — a rate
has more decimal places than a float holds exactly.

An order in one currency gets the real answer, summed across every tender against it.
There is deliberately no unique key on `order_id`, so a card and a gift card are two
payments and both appear.

---

## 8. What is never rendered

- **No instrument.** No column in this module can hold a card number, a security code
  or an account number. That is a stronger guarantee than hiding one, which is why
  `$hidden` and `makeVisible` appear nowhere in this package — `$hidden` is a
  serialisation default that a raw query never consults, and the host this module
  replaces already calls `makeVisible('secret')` deliberately.
- **No provider token.** It is credential-shaped: presented to the provider it moves
  money. It is not a column, an entry, a filter or a search term anywhere here.
- **No signing secret.** It is configuration and never a database column, and nothing
  in this package reads it.
- **No callback body**, because the domain stores none — and **no digest either**. The
  digest proves two deliveries were the same delivery; it is not evidence a person can
  read, and putting it on a screen invites somebody to treat it as though it were.
- **No idempotency key and no fact hash in the ledger table.** Both are machinery, and
  a key on a screen is a key somebody types into a second surface.
- **Nothing sensitive is searchable or filterable.** A search term and a filter's state
  are both persisted into the query string, which is written into every access log on
  the path. The only searchable columns are the public payment reference and the
  provider's own event id — the two things an operator quotes down a telephone or
  copies out of a provider's console.
- **A payment is bound by its reference, never by its id.** Both halves are needed:
  `$recordRouteKeyName` governs the inbound resolution, and `{record:reference}` in
  `getPages()` is what keeps the id out of a generated URL. `Payment` does not override
  `getRouteKeyName()`, so without the second half a URL would say `1`.

Each is asserted in `tests/Feature/SecurityTest.php`, and each refusal has a positive
assertion beside it so it cannot pass against an empty page or an empty log.

---

## 9. Authorization, and the three ways silence becomes permission

- A model with **no** policy is exposed: Laravel's unanswered gate case is permissive.
- A **present** policy missing a method is sharper, because Filament's
  `get_authorization_response()` returns *allow* for an ability it has no method for —
  and the file existing makes it look like a control.
- `canAssociate` and `canDissociate` are **live on a `hasMany`** and default open.

The domain answers all three: four policies, sixteen abilities refused by name in
`RefusesEveryWrite`, and a parameter typed `Model` so a gate call about the wrong model
is a denial rather than a `TypeError` from inside the policy.

This package restates every one of them at the resource and at the relation manager,
so the set reads as one list rather than as two halves nobody can hold in mind, and
asserts each by name.

**The relation manager answers `isReadOnly()` unconditionally**, and not by calling the
parent — the parent answers `true` only when the panel was configured with
`readOnlyRelationManagersOnResourceViewPages()`, which is the application's setting and
not this package's to assume. Filament consults `isReadOnly()` before any policy, which
is what makes it the right guard rather than a redundant one.

`fill()` is deliberately not defined. Narrowing a parent's public method to `private`
on a relation manager is a **fatal at class load** rather than a test failure, and
`fill()` is the one that has bitten; a test loads every class this package ships for
the same reason.

### The `detach` collision, and why it is not reintroduced here

The domain found a real instance of the sharpest case: `PaymentInstrumentPolicy`
originally published a domain ability called `detach`, which silently reopened
Filament's relation-manager `detach` because a subclass method wins over the trait's.
It is now `detachInstrument`.

This package publishes **no domain ability of its own** under any name, and asserts the
gate still refuses `detach`, `attach`, `associate` and `disassociate` on the domain's
models.

---

## 10. What this package deliberately does not surface

### Saved instruments

There is no `PaymentInstrumentResource`, and that is a refusal rather than an omission.
The only ability `PaymentInstrumentPolicy` publishes beyond reading is
`detachInstrument`, and **the domain publishes no action that performs it** — nothing
that writes `detached_at`. A presentation package writing that column itself would be a
second write path in a module where every write goes through an action, and a
`BoundaryTest` here greps for exactly that.

So a top-level list of saved cards would be a surface whose only purpose is a write
nobody may perform. What an operator actually needs — which card paid for this, and what
its expiry was — is on the payment that used it.

If a deployment needs to stop a token being used, that is the runbook's console
operation today, and a `DetachInstrument` action in the domain tomorrow.

### Callbacks that matched no payment

A callback is stamped with the team of the payment it matched, so an **unmatched**
callback carries `team_id` null. Every policy in the domain denies an unowned row —
deliberately, so an orphan cannot be quietly claimed — and this package scopes to the
actor's team.

Showing them anyway would mean writing a second tenancy answer that contradicts the
domain's, over rows that might be about anybody's money and carry no attribute saying
whose. So they are absent, and the page **says** they are absent and gives the console
read from the runbook. A queue nobody knows is invisible is worse than one nobody is
reading.

### Replaying a callback

Not offered. A replay is a request to the provider, made from their dashboard or their
API by whoever holds the credentials. Nor is deleting one: the row is the permanent
dedupe that makes their next retry harmless.

### Raising a payment

Not offered. `PaymentPolicy::create()` is permanently false, and a payment is raised by
a checkout completing or by a caller holding an idempotency key of its own.

### Anything else the domain does not own

Disputes, fees, netting, FX, whether a customer is owed a refund. The domain owns none
of them and neither does this.
