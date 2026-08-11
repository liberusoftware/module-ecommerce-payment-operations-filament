<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Named by `module.json` and registered by `ModuleManagerServiceProvider`, never
 * by Composer discovery — the package ships no `extra.laravel.providers`, so an
 * install boots nothing until the deployment names the module.
 *
 * It has nothing to do. The panels belong to the application, and this package
 * contributes to them through {@see PaymentOperationsPlugin}, which the
 * application attaches to whichever panel should carry money. A provider that
 * reached into a panel here would register a ledger over somebody's payments into
 * panels that never asked for one.
 *
 * It registers no policy either, and for a ledger that is worth saying out loud.
 * The domain module binds a policy for each of its four models in its own
 * provider, and those policies are where `capture`, `void` and `refund` are gated
 * on tenancy *and* on the domain's own answer. A presentation package binding a
 * second opinion about who may take money would be a second answer waiting to
 * disagree with the API, with a queued job and with a console command. What this
 * package *does* do is refuse, by name, every ability those policies do not
 * publish — see {@see Resources\PaymentResource}.
 */
final class PaymentOperationsFilamentServiceProvider extends ServiceProvider {}
