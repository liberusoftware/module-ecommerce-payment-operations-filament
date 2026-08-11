<?php

// What this package is allowed to be, asserted against its own source rather than
// against its behaviour. Every rule here is one a reviewer would otherwise have to
// hold in their head while reading a diff.

/**
 * Every PHP file this package ships.
 *
 * @return list<string>
 */
function sourceFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** Every PHP file under `src/`, concatenated. */
function sourceOfSrc(): string
{
    return implode("\n", array_map(
        fn (string $path): string => (string) file_get_contents($path),
        sourceFiles(),
    ));
}

/** The same, with comments removed, for the rules a docblock would trip. */
function codeOfSrc(): string
{
    $code = '';

    foreach (sourceFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            $code .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }
    }

    return $code;
}

it('ships some source to check', function () {
    // So that every grep below is a statement about files rather than a statement
    // about an empty list.
    expect(sourceFiles())->not->toBeEmpty()
        ->and(codeOfSrc())->toContain('class PaymentResource');
});

/**
 * A fatal at class load is not a test failure, and a private method narrowing a
 * parent's public one is exactly that. Loading every class this package ships is
 * the cheapest thing that finds it.
 */
it('loads every class it ships', function () {
    $root = dirname(__DIR__, 2).'/src/';

    foreach (sourceFiles() as $path) {
        $class = 'Liberu\\Ecommerce\\PaymentOperations\\Filament\\'
            .str_replace(['/', '.php'], ['\\', ''], substr($path, strlen($root)));

        expect(class_exists($class))->toBeTrue($class);
    }
});

/**
 * The one commerce namespace this package is entitled to name.
 *
 * Written as "collect what is mentioned and check the set" rather than as a search
 * for a particular sibling, because a test that spells out a forbidden name puts
 * that name in the repository in order to look for it.
 */
it('names its own commerce namespace and no other', function () {
    preg_match_all('/Liberu\\\\+Ecommerce\\\\+([A-Za-z]+)/', sourceOfSrc(), $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($matches[1])))->toBe(['PaymentOperations']);
});

it('reaches the application namespace nowhere', function () {
    expect(sourceOfSrc())->not->toContain('use App\\')
        ->not->toContain('new App\\')
        ->not->toContain('extends App\\')
        ->not->toContain('implements App\\');
});

/**
 * No provider name anywhere, because this package integrates with nobody either.
 *
 * The domain resolves a gateway by configured class name and names no provider in
 * its own source; a panel over it that named one would have to be released the day
 * a merchant signs with somebody else. Matched on a word boundary rather than as a
 * substring, so an innocent substring inside a longer word is not a failure
 * somebody deletes rather than fixes.
 */
it('names no payment provider in its source', function (string $provider) {
    expect(preg_match('/\b'.preg_quote($provider, '/').'\b/i', sourceOfSrc()))->toBe(0);
})->with([
    'Stripe', 'PayPal', 'Braintree', 'Adyen', 'Klarna', 'Square', 'Mollie',
    'Worldpay', 'Checkout.com', 'GoCardless', 'Authorize.net', 'Razorpay',
    'Wise', 'Revolut', 'Visa', 'Mastercard', 'Amex',
]);

/**
 * **The package constructs no form input of any kind**, and that is the assertion
 * that says so about the source rather than about one page.
 *
 * There is no status to set, so there is no `Select`. There is no amount to type,
 * so there is no `TextInput` — a money field is where a typo becomes a charge, and
 * a partial capture's idempotency key belongs to whoever decided the amount. There
 * is no key to type either, for the same reason.
 *
 * The sibling package for parcels constructs exactly one input, a cancellation
 * reason. This one constructs none at all.
 */
it('constructs no form input anywhere in the package', function () {
    preg_match_all('/^use Filament\\\\Forms\\\\Components\\\\([A-Za-z]+);$/m', sourceOfSrc(), $imports);

    expect($imports[1])->toBe([])
        ->and(codeOfSrc())->not->toContain('TextInput::')
        ->not->toContain('Select::')
        ->not->toContain('->schema([Select');
});

/**
 * No column, entry or filter is ever built over machinery or a credential.
 *
 * Asserted by the name a component would have to be constructed with, which is the
 * form the leak would actually take. `provider_token` moves money when presented to
 * the provider; `body_digest` is not evidence a person can read; the keys and
 * hashes are the idempotency machinery, and a key on a screen is a key somebody
 * types into a second surface.
 */
it('builds no component over a token, a secret, a digest or a key', function (string $name) {
    expect(codeOfSrc())->not->toContain("make('".$name."')");
})->with([
    'provider_token', 'signing_secret', 'body_digest', 'entry_key', 'entry_hash',
    'payment_key', 'payment_hash', 'secret', 'details',
]);

/**
 * Nor does it reach for the serialisation trick the domain rejected.
 *
 * `$hidden` is a serialisation default: `makeVisible()` steps over it, a raw query
 * never consults it, and the host this module replaces already calls
 * `makeVisible('secret')` deliberately in a webhook controller. The guarantee is
 * that no column can hold the thing, and a presentation package leaning on
 * `$hidden` would be quietly claiming a weaker one.
 */
it('leans on no serialisation default for its security', function () {
    expect(codeOfSrc())->not->toContain('$hidden')
        ->not->toContain('makeVisible')
        ->not->toContain('makeHidden');
});

/**
 * No float arithmetic reaches money.
 *
 * `(int) (19.99 * 100)` is `1998` and `1999 / 100` is where the penny goes. The
 * conversion lives in the domain's `Money::decimal()`, which is padding and
 * `substr`; this package formats what that returns and computes nothing.
 */
it('does no floating-point arithmetic on money', function () {
    $code = codeOfSrc();

    expect($code)->not->toContain('(float)')
        ->not->toContain('floatval')
        ->not->toContain('number_format')
        ->not->toContain('round(')
        ->not->toContain('/ 100')
        ->not->toContain('* 100');
});

/**
 * This package writes nothing itself.
 *
 * Every change goes through a domain action, which is where the row lock, the fold
 * under that lock, the guard, the provider call and the event all live. A `save()`
 * or an `update()` in a presentation package is a second write path with none of
 * them — and on an append-only table it is a write the model events, `LedgerBuilder`
 * and the policy are all separately built to refuse.
 */
it('performs no write of its own anywhere in its source', function () {
    preg_match_all(
        '/->(save|update|updateOrCreate|forceFill|fill|delete|forceDelete|restore|insert|upsert|push|increment|decrement)\(/',
        codeOfSrc(),
        $matches,
    );

    expect($matches[1])->toBe([]);
});

/**
 * And it calls exactly the three actions the domain publishes as a write surface.
 *
 * `AuthorizePayment` is deliberately absent — a payment is raised under a key its
 * caller supplies, and a button would mint a fresh one per press. So is
 * `RecordProviderCallback`, which is a public endpoint's job and not a person's.
 */
it('calls the three published movements and no other action', function () {
    preg_match_all('/^use Liberu\\\\Ecommerce\\\\PaymentOperations\\\\Actions\\\\([A-Za-z]+);$/m', sourceOfSrc(), $matches);

    $actions = array_values(array_unique($matches[1]));
    sort($actions);

    expect($actions)->toBe(['CapturePayment', 'RefundPayment', 'VoidPayment']);
});

/**
 * And it reads through the queries the domain publishes rather than writing its
 * own `where` clauses over somebody else's tables.
 */
it('asks the domain\'s own queries for both operator queues', function () {
    $code = codeOfSrc();

    expect($code)->toContain('needingReconciliation(')
        ->toContain('stalledSince(')
        ->toContain('unhandledCallbacks(')
        ->toContain('capturedForOrder(')
        // The callbacks filter calls the domain's scope by name. A second copy of
        // "needs attention" here would be a second answer waiting to disagree.
        ->toContain("scopes('needingAttention')");
});

it('registers no service provider through Composer', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // Installation never implies boot. The module manager registers the provider
    // `module.json` names, and only when the deployment asks for it by name.
    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([]);

    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    // The manifest's package list is the composer `require` list, filtered. The
    // boundary suite asserts the same thing; this is the one that fails in a diff
    // where somebody added a dependency and forgot the manifest.
    $required = array_keys(array_filter(
        $composer['require'],
        fn (string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_KEY,
    ));

    sort($required);

    $declared = array_keys($manifest['requires']['packages']);
    sort($declared);

    expect($declared)->toBe($required)
        ->and($composer['version'])->toBe($manifest['version'])
        ->and($composer['extra']['liberu']['name'])->toBe($manifest['name'])
        ->and($manifest['category'])->toBe('presentation')
        ->and($manifest['presentation']['filament']['admin'])->not->toBeEmpty();
});

it('names only classes that exist under its presentation key', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($manifest['presentation']['filament'] as $panel => $classes) {
        foreach ($classes as $class) {
            expect(class_exists($class))->toBeTrue($panel.': '.$class);
        }
    }

    expect(class_exists($manifest['provider']))->toBeTrue();
});

/**
 * The domain declares its own VCS repository here so that **this** package's CI can
 * resolve it, and that declaration does nothing for a consumer: Composer honours
 * `repositories` only from the root manifest. The host has to add the same entry,
 * which `docs/adoption.md` says.
 */
it('declares the domain package it presents, in both manifests and as a VCS repository', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $urls = array_map(fn (array $repository): string => $repository['url'], $composer['repositories']);

    expect($composer['require'])->toHaveKey('liberusoftware/ecommerce-payment-operations')
        ->and($composer['require-dev'])->toHaveKey('liberusoftware/ecommerce-payment-operations')
        ->and($urls)->toBe(['https://github.com/liberusoftware/module-ecommerce-payment-operations']);
});
