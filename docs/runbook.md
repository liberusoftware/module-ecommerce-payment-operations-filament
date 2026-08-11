# Runbook

The questions this panel exists to answer, in the order somebody asks them. The
domain's own runbook is
[here](https://github.com/liberusoftware/module-ecommerce-payment-operations/blob/main/docs/runbook.md);
this one is about what to press.

---

## 1. The panel is empty for everybody

Not a bug and not an error anywhere. `PanelActor::teamId()` reads `current_team_id` off
the actor and guards it with `is_numeric()`, so a **string** id — a ULID, a UUID —
answers null, and null means no. `(int) '01H…'` is `1`, and showing an operator team
1's payments is worse than showing them nothing, so the guard fails closed and does it
silently. Wave 5 recorded the same guard failing this way in three modules.

Check the type of `current_team_id` on your user model. If it is not an integer, this
needs a resolver change in the domain rather than a configuration one — raise it.

An actor with no team also sees no navigation badges and cannot open either list at
all: `viewAny` is false.

## 2. A payment looks wrong

**Open it. The ledger is on the same page as the totals, and the totals are a sum over
the ledger.** There is no third place a number could have come from.

Read the *Who recorded it* column first. `We asked for it` rows passed every guard the
domain has. `The provider told us` rows passed none of them, deliberately — losing a
fact about our money is worse than holding an inconsistent one — so they are the only
rows that can make a ledger impossible.

Then read both clocks. `When it happened` is the provider's and `When we heard` is
ours; a large gap is a callback that arrived late, which is normal and needs no action.
The fold is commutative, so an out-of-order delivery cannot change any total.

## 3. The red badge on Payments

`Ledger does not add up`, counted by `PaymentQuery::needingReconciliation()` for the
actor's team. Filter the list by it and open one. The **Reconciliation** section names
which invariant broke and what it usually means:

| The page says | Usually | Do |
| --- | --- | --- |
| More has been taken than was ever reserved | The provider captured more than we reserved, or an authorization callback never arrived | Check the provider's own record. If the authorization is real and we simply never heard, replay the callback from their dashboard |
| More has gone back than was ever taken | A refund issued outside this system, against a capture we never recorded | Replay the capture callback, or record the missing capture through the adapter |
| *n* entries in a currency this payment is not in | A migration, a restore or a second writer — the module refuses to write one | Investigate the writer. Those entries are excluded from the totals, so the numbers are correct but incomplete |

**There is no button here that corrects any of it, and that is deliberate.** A
correction is a new ledger row written through the same actions everything else is. If
the fix is "money should go back", that is the refund button; if it is "we never
recorded a capture the provider made", that is a replayed callback. Nothing about an
impossible ledger is repaired by editing it, because nothing anywhere can edit it.

Note that an over-captured payment offers **no capture button**: `capturable()` is
floored at zero, which is the safe direction.

## 4. The amber badge on Provider callbacks

Callbacks nobody has dealt with, counted by `PaymentQuery::unhandledCallbacks()` for the
actor's team. Filter by *Somebody should look at these*.

- **Event type not mapped** — the provider has started sending something the adapter does
  not map. A decision rather than a fault: add the mapping in the adapter's `verify()`
  if the event matters, then replay the event from the provider's dashboard.
- **No payment matched** — **these are not in the panel.** They carry no team, so no
  operator owns them and every policy denies an unowned row. Read them where there is no
  actor:

  ```php
  (new PaymentQuery())->unhandledCallbacks();
  ```

  Check §5 first: a payment that stalled has no provider reference stored, so its
  callbacks cannot match. Once its retry completes it, replay the callback.

Nothing here is replayed or deleted from the panel. A replay is a request to the
provider; the row is the permanent dedupe that makes their next retry harmless.

## 5. A payment that says "Raised, nothing recorded"

Seconds old, it is in progress. Minutes old, it is the failure nobody gets paged about:
the gateway is called before the write, so a crash between the provider approving and
this module committing leaves exactly this shape — no error anywhere, and the customer
was probably charged.

Filter the payments list by **Raised and never authorized** (fifteen minutes, the
domain's own threshold).

**The fix is not in this panel.** The caller retries with *the same idempotency key*;
that key went to the provider too, so the retry asks the same question and gets the same
answer rather than reserving a second time. If retrying produces a second charge, the
adapter is minting its own key per attempt instead of forwarding
`GatewayInstruction::$idempotencyKey` — fix the adapter.

## 6. A button is missing

| Missing | Why |
| --- | --- |
| Take the money | Nothing is still reserved. Either it has all been taken, or the reservation was released or expired |
| Release the reservation | Something has been taken, so there is no reservation left — the page's *Releasing the reservation* entry says so and says that giving it back is a refund, which the customer sees and which usually costs a fee. Or nothing was ever reserved |
| Give the money back | Nothing has been taken, or all of it has already gone back |
| All three | The payment belongs to another team, or to nobody |

The page always says which, in the **What can happen next** section. A missing button is
never left to be interpreted.

## 7. "Already recorded"

The key the button derived is already in the ledger with the same facts, so the domain
wrote nothing and announced nothing. That is the idempotency guarantee working.

It means the movement has already happened. If you genuinely meant a *second* movement
of the same size — capture £30, refund £30, capture £30 again, refund £30 again — the
panel cannot tell that apart from a second click, and the second one needs a caller that
owns its own key: the `-api` package, a host listener, or the domain action directly.

## 8. "Somebody else is moving this money"

`PaymentInFlight`, and it is **transient**. A call has claimed the key and has not
committed. Wait a moment and press again; it clears on its own.

Distinct from **"That instruction conflicts with one already recorded"**, which is
`PaymentConflict` and is **permanent**. Retrying will not help — the same key has
already recorded a different movement. The two are separate exception classes for
exactly this reason, and the panel never asks anybody to read a message to tell them
apart.

## 9. "The provider refused it"

A decline is a fact, not an outage. The domain recorded a `Refused` row of zero in the
ledger with the provider's short code beside it, the totals did not move, and the money
is still wherever it was. The ledger on the page will show the attempt.

Repeated declines on one card type are a question for the provider, and the ledger's
refusal codes are the data for it.

## 10. "Nothing moved" with a gateway message

No gateway class is configured under `payment-operations.gateways.{name}.class`, or the
class named does not implement the contract. The domain refuses to guess and this panel
repeats the refusal rather than showing a stack trace. Set the configuration; see
`docs/adoption.md` §4.

## 11. Things that are not incidents

- **A settlement column saying "Same currency".** The ordinary case: the provider
  settled in the currency the customer was charged in.
- **"This order was paid in more than one currency."** Not an error. There is no single
  total and the module will not invent a rate to make one.
- **A callback marked "Seen before".** The dedupe working. Providers retry until they
  get a 2xx, and "until" includes a week later.
- **`Taken` with no `Given back`, forever.** A captured payment is not terminal — a
  refund is still possible — and it is not waiting for anything.
- **A payment nobody can see because `team_id` is null.** The policy denies an orphan
  deliberately, so it cannot be quietly claimed. Reach it from a console command.
- **No badge at all.** Both badges are absent when their count is zero, and absent for
  an actor with no team.
