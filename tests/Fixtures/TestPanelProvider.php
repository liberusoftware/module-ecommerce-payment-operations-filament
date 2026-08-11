<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\PaymentOperations\Filament\PaymentOperationsPlugin;

/**
 * The panel this package's resources need in order to be resources at all.
 *
 * The package ships a plugin and the application composes the panel, so the suite
 * composes the smallest panel that attaches the plugin — under the `admin` id
 * `module.json`'s `presentation.filament` key names, which is the composition this
 * repository is actually claiming works.
 *
 * Deliberately not tenant-aware, and deliberately without
 * `readOnlyRelationManagersOnResourceViewPages()`. The plugin must work on a panel
 * with no tenancy, because nothing in the manifest says the host's panel has any —
 * and leaving the read-only default off is what makes the relation manager's own
 * `isReadOnly()` the thing under test rather than a panel setting standing in for
 * it.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugins([PaymentOperationsPlugin::make()]);
    }
}
