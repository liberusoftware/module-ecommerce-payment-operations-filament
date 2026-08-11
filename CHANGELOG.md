# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-11

First release.

### Added

- `PaymentOperationsPlugin`, attached per panel by the application. Nothing registers globally.
- `PaymentResource` — a list and a view page for payments, scoped to the actor's team, showing the
  state, the amounts and the reconciliation flag that the ledger folds to rather than any stored
  column.
- The three movements the domain publishes as actions: **Take the money**, **Release the
  reservation** and **Give the money back**, each with its own confirmation, its own ability and its
  own explanation of what the customer will see.
- A **What the ledger says** section — reserved, taken, given back, still takeable, still refundable,
  refused attempts — with the ledger that produced it on the same page.
- A **Reconciliation** section naming which invariant an impossible ledger broke, in the runbook's
  words, with no button anywhere that would correct it.
- A **This order, across every tender** section that states the mixed-currency refusal as a sentence.
- `EntriesRelationManager` — the ledger, read-only, with the provider's clock and ours in separate
  columns and the settlement beside the presentment amount.
- `ProviderCallbackResource` — the reconciliation inbox, with a warning badge counting what nobody
  has dealt with.
- Filters for the two operator queues the runbook names, both asking the domain's published queries:
  payments whose ledger does not add up, and payments raised and never authorized.
- `docs/domain.md`, `docs/adoption.md`, `docs/runbook.md`.

### Decided

- **No status field, no form, no create page, no edit page, no delete.** There is no `status` column
  to set — the domain asserts its absence by name — so there is nothing to edit, and the whole
  package constructs no form input of any kind. A test greps the source for one.
- **No amount is ever typed.** Capture takes everything reserved and refund gives back everything
  refundable. A money field is where a typo becomes a charge, and a partial movement's idempotency
  key belongs to whoever decided the amount rather than to a button.
- **Idempotency keys are derived from the payment and the ledger, never minted per press.** A second
  press finds nothing left to move and says so; a race derives the same key and the unique index
  settles it. The consequence — that a panel cannot tell a second identical instruction from a second
  click — is stated on the page rather than left to be discovered.
- **A permanent conflict and a transient claim are told apart by class**, which is why the domain
  ships two exception types. One says never retry and the other says retry shortly, and the operator
  is told which.
- **Every refusal is surfaced verbatim and none is clamped.** Where the domain refuses because the
  arithmetic does not permit it, the message reaches the operator as written.
- **An impossible ledger is reported and never corrected.** No clamp, no repair job, no button. The
  page names the broken invariant and says a correction is a new row.
- **The mixed-currency refusal is a sentence, not a blank cell.** `capturedForOrder()` throws rather
  than inventing a rate, and the page says why in the domain's own terms.
- **Callbacks that matched no payment are deliberately absent from the panel.** They carry no team,
  every policy denies an unowned row, and showing them would mean this package writing a second
  tenancy answer over rows that might be about anybody's money. The page says so and gives the
  console read from the runbook.
- **Saved instruments are not surfaced at all.** The only ability their policy publishes beyond
  reading is `detachInstrument`, and the domain publishes no action that performs it — a presentation
  package writing `detached_at` itself would be a second write path in a module where every write is
  an action. What an operator needs is on the payment that used the card.
- **Every ability neither policy publishes is refused by name**, on both resources and on the
  relation manager, because an unanswered gate is permissive and Filament returns *allow* when a
  present policy has no method for the ability asked about. `canAssociate` and `canDissociate` are
  live on a `hasMany` and default open.
- **The relation manager answers `isReadOnly()` unconditionally**, which Filament consults before any
  policy — sidestepping a gate call that would hand a policy the wrong model and raise a `TypeError`
  from inside it, which is a five hundred rather than a denial.
- **No instrument, no provider token, no signing secret and no callback digest on any surface**, none
  of them searchable or filterable, and none of them in a log line. Asserted with a render test and a
  log-capture test, each with a positive assertion beside it.
- **No provider name anywhere in `src/`.** A test greps for seventeen on a word boundary.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-payment-operations-filament/releases/tag/0.1.0
