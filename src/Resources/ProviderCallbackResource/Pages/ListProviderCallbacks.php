<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\PaymentOperations\Filament\Resources\ProviderCallbackResource;

/**
 * The reconciliation inbox, and a sentence about the part of it this panel cannot
 * show.
 *
 * There are no header actions and no row actions at all. A callback is not replayed
 * from here — a replay is a request to the provider, made from their dashboard or
 * their API by whoever holds the credentials — and it is not deleted from here
 * either, because the row is the dedupe that makes their next retry harmless.
 */
class ListProviderCallbacks extends ListRecords
{
    protected static string $resource = ProviderCallbackResource::class;

    /**
     * **The unmatched ones are missing, and saying so is the whole point.**
     *
     * A callback that matched no payment carries no team, because the team is copied
     * off the payment it matched. The policy denies every action on an unowned row
     * and this resource scopes to the actor's team, so those callbacks cannot appear
     * here — and a queue nobody knows is invisible is worse than one nobody is
     * reading. The console line below is the runbook's own, and it is the read with
     * no actor in it.
     */
    public function getSubheading(): string
    {
        return 'Everything a provider has delivered about this team\'s payments, with what this module did about each. Nothing here can be edited, deleted or replayed: a replay is a request made from the provider\'s own dashboard, and this row is the dedupe that makes their next retry harmless. Callbacks that matched no payment are deliberately absent — they belong to no team, so no operator owns them; read those with (new PaymentQuery)->unhandledCallbacks() from a console command, where there is no actor and no tenant to leak across.';
    }
}
