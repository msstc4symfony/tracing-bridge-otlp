<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Composer\InstalledPackageResolver;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Rector\Symfony\Configs\Rector\Closure\FromServicePublicToDefaultsPublicRector;
use Rector\Symfony\Configs\Rector\Closure\ServiceSettersToSettersAutodiscoveryRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\CoversAnnotationWithValueToAttributeRector;

/*
 * Composer-based rules are bound to the installed version of each package. Symfony packages pulled in
 * transitively (http-foundation, console, ...) have no constraint in composer.json, so Rector would take
 * the newest release from vendor/ and rewrite code to APIs the lowest supported Symfony lacks
 * (e.g. new RequestStack([$request]) needs 7.2). Every lockstep Symfony package is resolved to the floor
 * of the standard's "^7.4|^8.0" constraint.
 */
$lowestSymfony = '7.4.0';

$writeLowestPackagesManifest = static function () use ($lowestSymfony): string {
    $readJson = static function (string $file): array {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('"%s" is missing, run composer install first.', $file));
        }

        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    };

    $installed = $readJson(__DIR__ . '/vendor/composer/installed.json');
    $manifest = $readJson(__DIR__ . '/composer.json');

    $constraints = [];
    foreach ($installed['packages'] as ['name' => $name, 'version_normalized' => $version]) {
        // Contracts, polyfills and symfony/monolog-bundle release below the floor, so the version check skips
        // them; a dev branch has no comparable version and is pinned rather than dropped from the resolver.
        $isLockstepSymfony = str_starts_with($name, 'symfony/')
            && (str_starts_with($version, 'dev-') || version_compare($version, $lowestSymfony, '>='));
        $constraints[$name] = $isLockstepSymfony ? $lowestSymfony : $version;
    }

    $constraints = [...$constraints, ...($manifest['require'] ?? []), ...($manifest['require-dev'] ?? [])];

    // Per-process file: parallel Rector runs in one bundle must not read each other's half-written manifest.
    $file = tempnam(sys_get_temp_dir(), 'rector-lowest-packages-');
    $json = json_encode(['require' => $constraints], JSON_THROW_ON_ERROR);
    if ($file === false || file_put_contents($file, $json) === false) {
        throw new RuntimeException('Cannot write the lowest supported packages manifest for Rector.');
    }

    register_shutdown_function(static fn (): bool => !is_file($file) || unlink($file));

    return $file;
};

return static function (RectorConfig $rectorConfig) use ($writeLowestPackagesManifest): void {
    $rectorConfig->singleton(
        InstalledPackageResolver::class,
        static fn (): InstalledPackageResolver => new InstalledPackageResolver(__DIR__, $writeLowestPackagesManifest()),
    );

    $builder = RectorConfig::configure()
        ->withPaths([
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ])
        ->withoutParallel()
        // The package runs on PHP 8.2: no rule may raise the code above it.
        ->withPhpVersion(PhpVersion::PHP_82)
        ->withPhpSets(php82: true)
        ->withComposerBased(doctrine: true, phpunit: true, symfony: true)
        ->withAttributesSets(symfony: true, doctrine: true, mongoDb: true, phpunit: true)
        ->withPreparedSets(
            deadCode: true,
            codeQuality: true,
            codingStyle: true,
            typeDeclarations: true,
            privatization: true,
            naming: false,
            instanceOf: true,
            earlyReturn: true,
            carbon: false,
            rectorPreset: true,
            phpunitCodeQuality: false,
            doctrineCodeQuality: true,
            symfonyCodeQuality: true,
            symfonyConfigs: true,
        )
        ->withImportNames(removeUnusedImports: true)
        ->withSkip(
            [
                // Bundle service config registers optional integrations explicitly behind
                // interface_exists() guards: no public defaults, no class autodiscovery.
                FromServicePublicToDefaultsPublicRector::class,
                ServiceSettersToSettersAutodiscoveryRector::class,
                ClassPropertyAssignToConstructorPromotionRector::class,
                PostIncDecToPreIncDecRector::class,
                NewlineAfterStatementRector::class,
                CatchExceptionNameMatchingTypeRector::class,
                CoversAnnotationWithValueToAttributeRector::class,
            ],
        );

    $builder($rectorConfig);
};
