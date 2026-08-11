<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A relation manager that can be read and nothing else.
 *
 * ## `isReadOnly()` first, and it is not belt-and-braces
 *
 * Filament consults `isReadOnly()` **before** it consults any policy. That
 * ordering is what makes it the right guard rather than a redundant one, because
 * of what asking the policy would actually do here.
 *
 * The domain does register a policy for both tables under these managers, and both
 * refuse every write by name — so this is not the "no policy at all" case the
 * fleet has shipped five times. It is the second one, which is sharper: a relation
 * manager's default authorization asks the gate about the **related** model, and
 * `PaymentEntryPolicy` and `ProviderCallbackPolicy` are typed against their own
 * models. A gate call that arrived with the wrong class would raise a `TypeError`
 * **from inside the policy** — a five hundred, not a refusal, and a refusal is what
 * was wanted. The domain types `RefusesEveryWrite`'s parameter as `Model` for
 * exactly that reason; answering the question before it is asked removes the last
 * of it.
 *
 * ## And then every ability by name anyway
 *
 * Filament's `get_authorization_response()` returns *allow* when a **present**
 * policy has no method for the ability asked about, so a partial policy is the
 * same hazard as no policy and harder to see. Each ability below is answered by
 * name rather than inferred from a table that happens to carry no create action
 * today.
 *
 * `canAssociate` and `canDissociate` are the two that matter most and the two
 * least likely to be noticed: they are live on a `hasMany` relation manager and
 * they default **open**. Associating a ledger entry onto a different payment moves
 * somebody else's money in a module whose every reported number is a sum over
 * these rows — and because the ledger is append-only, the wrong answer could not
 * be taken back afterwards.
 *
 * ## Why read-only rather than merely restricted
 *
 * **A ledger row is what happened.** The domain enforces that three ways — the
 * model's `updating` and `deleting` events throw, `LedgerBuilder` refuses
 * `query()->update()`, `query()->delete()` and `upsert()` because model events do
 * not fire for those, and the policy answers `false` to every write ability. A
 * panel offering an edit button would be a fourth path that fails at the first of
 * those with a stack trace, which is a worse surface than no button.
 *
 * A correction is a **new row**, written through the same actions everything else
 * is. That is not a workaround for the restriction; it is what a ledger is.
 *
 * ## The one method deliberately not defined
 *
 * `fill()`. Narrowing a parent's public method to `private` on a relation manager
 * is a fatal at class load rather than a test failure, and `fill()` is the one that
 * has bitten. Everything here matches the parent's `protected` visibility and its
 * parameter types exactly.
 *
 * Authorization for reading is the owner's: `view` on the payment these rows hang
 * off. Tenancy is therefore the policy's single answer rather than a second one
 * written here.
 */
abstract class LedgerRelationManager extends RelationManager
{
    /**
     * Unconditional, and not a call to `parent::isReadOnly()`.
     *
     * The parent answers `true` only when the current panel has been configured
     * with `readOnlyRelationManagersOnResourceViewPages()`, which is the
     * application's setting and not this package's to assume. An append-only table
     * is read-only wherever this plugin is attached.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    protected function canViewAny(): bool
    {
        return Gate::allows('view', $this->getOwnerRecord());
    }

    protected function canView(Model $record): bool
    {
        return Gate::allows('view', $this->getOwnerRecord());
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    protected function canDeleteAny(): bool
    {
        return false;
    }

    protected function canForceDelete(Model $record): bool
    {
        return false;
    }

    protected function canForceDeleteAny(): bool
    {
        return false;
    }

    protected function canRestore(Model $record): bool
    {
        return false;
    }

    protected function canRestoreAny(): bool
    {
        return false;
    }

    protected function canReplicate(Model $record): bool
    {
        return false;
    }

    protected function canReorder(): bool
    {
        return false;
    }

    /*
     * A `hasMany` relation manager offers associate and dissociate rather than
     * attach and detach, and all six are refused: nothing in this package may move
     * a ledger entry or a provider callback between payments. A row filed against
     * the wrong payment is a wrong number in the one module whose job is being
     * right about money, and on an append-only table it is a wrong number that
     * cannot be withdrawn.
     */

    protected function canAssociate(): bool
    {
        return false;
    }

    protected function canDissociate(Model $record): bool
    {
        return false;
    }

    protected function canDissociateAny(): bool
    {
        return false;
    }

    protected function canAttach(): bool
    {
        return false;
    }

    protected function canDetach(Model $record): bool
    {
        return false;
    }

    protected function canDetachAny(): bool
    {
        return false;
    }
}
