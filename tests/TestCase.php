<?php

namespace Liberu\Ecommerce\PaymentOperations\Filament\Tests;

use Filament\Facades\Filament;
use Liberu\Ecommerce\PaymentOperations\Filament\Tests\Fixtures\TestPanelProvider;
use Liberu\Ecommerce\PaymentOperations\PaymentOperationsServiceProvider;
use Liberu\Ecommerce\PaymentOperations\Testing\FakeGateway;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;

/**
 * Filament is the one dependency `PackageTestCase`'s scoped discovery cannot cover
 * on its own: it registers `extra.laravel.providers` of this package's *direct*
 * requirements, which for `filament/filament` is a single provider. A panel needs
 * the rest of the stack — support, schemas, forms, tables, actions, notifications,
 * widgets, Livewire, the icon packages — and every one of those is transitive. So
 * this widens the same walk to everything installed, which is what Laravel's own
 * discovery does in an application, and appends the fixture panel.
 */
abstract class TestCase extends PackageTestCase
{
    use UsesTestUser;

    /**
     * A signing secret, configured the way a deployment configures one.
     *
     * It exists in this suite so that the tests asserting it never reaches a
     * screen or a log line are asserting about a secret that is genuinely there.
     * A leak test run with nothing to leak passes for the wrong reason.
     */
    public const SIGNING_SECRET = 'shhh-signing-secret-9001';

    /** A provider token, which is credential-shaped and must never be rendered. */
    public const PROVIDER_TOKEN = 'tok_never_render_9002';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing has resolved a panel from a request, and a resource page needs
        // one to be current before it can mount.
        Filament::setCurrentPanel('admin');
    }

    /**
     * An actor working in a team, which is the whole of every policy's tenancy.
     *
     * `current_team_id` is set in memory rather than migrated onto `users`: the
     * column belongs to the application's own schema, and a package that added it
     * to the shared testbench table would be asserting against a users table no
     * deployment has.
     */
    protected function actorForTeam(int $teamId): TestUser
    {
        $user = TestUser::factory()->create();
        $user->setAttribute('current_team_id', $teamId);

        $this->actingAs($user);

        return $user;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Nothing here loads a team through the relation, but the default names
        // the host's own class — one no package tree has — and pointing it
        // somewhere real keeps an accidental eager load from failing on a missing
        // class rather than on the thing under test.
        $app['config']->set('payment-operations.team_model', TestUser::class);

        // On, so the telemetry assertions are asserting about records that were
        // actually written. Off is the shipped default, because a busy checkout
        // writes thousands an hour.
        $app['config']->set('payment-operations.telemetry.enabled', true);

        // The gateway is resolved by configured class name at call time, which is
        // how the domain integrates with nobody. This is the fake the domain ships
        // for exactly this: no provider name appears in either package.
        $app['config']->set('payment-operations.gateways.card', [
            'class' => FakeGateway::class,
            'signing_secret' => self::SIGNING_SECRET,
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            ...$this->discoveredProviders(),
            // Named as well as dev-required. `PackageTestCase` boots a sibling's
            // manifest provider off `require-dev`, and this package declares the
            // domain there for exactly that reason — but these resources have no
            // tables to query without the migrations it loads and no gate to ask
            // without the policies it binds, so the dependency is stated here too
            // rather than left to a manifest key.
            PaymentOperationsServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }

    /**
     * Every `extra.laravel.providers` entry in the installed tree.
     *
     * Sibling Liberu modules are unaffected: their manifests declare that array
     * empty precisely so installation never implies boot, so this picks up the
     * framework packages and nothing else.
     *
     * @return array<int, class-string>
     */
    private function discoveredProviders(): array
    {
        $installed = json_decode(
            (string) file_get_contents($this->packageRoot().'/vendor/composer/installed.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $providers = [];

        foreach ($installed['packages'] ?? [] as $package) {
            foreach ((array) ($package['extra']['laravel']['providers'] ?? []) as $provider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
