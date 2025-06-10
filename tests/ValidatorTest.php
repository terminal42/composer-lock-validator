<?php

declare(strict_types=1);

namespace Terminal42\ComposerLockValidator\Tests;

use Composer\Util\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Terminal42\ComposerLockValidator\PartialValidationMode;
use Terminal42\ComposerLockValidator\ValidationException;
use Terminal42\ComposerLockValidator\Validator;

class ValidatorTest extends TestCase
{
    private string $originalCwd;

    private bool|string $originalComposerEnv;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->originalComposerEnv = Platform::getEnv('COMPOSER');
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);

        if ($this->originalComposerEnv) {
            Platform::putEnv('COMPOSER', $this->originalComposerEnv);
        }
    }

    /**
     * @param non-empty-list<string> $packageList
     */
    #[DataProvider('passesPartialValidationProvider')]
    public function testPassesPartialValidation(string $fixture, array $packageList, PartialValidationMode $partialValidationMode): void
    {
        $validator = $this->loadValidator($fixture);
        $validator->validatePartial(
            $this->loadComposerLock($fixture, 'existing_composer.lock'),
            $this->loadComposerLock($fixture),
            $packageList,
            $partialValidationMode,
        );
        $this->addToAssertionCount(1);
    }

    #[DataProvider('passesValidationProvider')]
    public function testPassesValidation(string $fixture): void
    {
        $validator = $this->loadValidator($fixture);
        $validator->validate($this->loadComposerLock($fixture));
        $this->addToAssertionCount(1);
    }

    #[DataProvider('failsValidationProvider')]
    public function testFailsValidation(string $fixture, string $expectedExceptionMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $validator = $this->loadValidator($fixture);
        $validator->validate($this->loadComposerLock($fixture));
    }

    /**
     * @param non-empty-list<string> $packageList
     */
    #[DataProvider('failsPartialValidationProvider')]
    public function testFailsPartialValidation(string $fixture, string $expectedExceptionMessage, array $packageList, PartialValidationMode $partialValidationMode): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $validator = $this->loadValidator($fixture);
        $validator->validatePartial(
            $this->loadComposerLock($fixture, 'existing_composer.lock'),
            $this->loadComposerLock($fixture),
            $packageList,
            $partialValidationMode,
        );
    }

    public static function passesValidationProvider(): \Generator
    {
        yield ['valid-simple'];
        yield ['valid-root-package-replaces'];
        yield ['valid-different-branch-alias-order'];
    }

    public static function passesPartialValidationProvider(): \Generator
    {
        yield ['valid-partial-update-listed-only', ['vendor/package-a', 'vendor/package-b'], PartialValidationMode::UpdateOnlyListed];
    }

    public static function failsPartialValidationProvider(): \Generator
    {
        yield [
            'invalid-partial-update-listed-only-modified-existing-package',
            'The metadata of package "vendor/package-c" in version "1.0.0.0" does not match the package metadata in your local composer.lock. Diff (provided package / valid package): --- Original
+++ New
@@ @@
     "version": "1.0.0",
     "dist": {
         "type": "zip",
-        "url": "https:\/\/i-am-not-in-the-update-list-so-I-want-to-be-used-for-an-injection.com\/vendor\/package-c\/1.0.0.zip"
+        "url": "https:\/\/this-has-changed.com\/vendor\/package-c\/1.0.0.zip"
     },
     "require": {
         "vendor\/package-b": "^1.0"',
            ['vendor/package-a', 'vendor/package-b'],
            PartialValidationMode::UpdateOnlyListed,
        ];
    }

    public static function failsValidationProvider(): \Generator
    {
        yield ['invalid-wrong-composer-lock-schema', 'The "composer.lock" must contain both, the "packages" and the "packages-dev" keys and they must be arrays.'];
        yield ['invalid-composer-internal-exception', 'An unknown other exception has been thrown: Invalid version string "this-is-some-fake-version".'];
        yield ['invalid-package-not-required', 'The package "vendor/package-d" in version "1.0.0.0" is not required by any package in the composer.json or its transitive dependencies.'];
        yield ['invalid-package-manipulated-meta-data', 'The metadata of package "vendor/package-c" in version "1.0.0.0" does not match any of the metadata in the repositories. Diff (provided package / valid package): --- Original
+++ New
@@ @@
     "version": "1.0.0",
     "dist": {
         "type": "zip",
-        "url": "https:\/\/evil.com\/vendor\/package-c\/1.0.0.zip"
+        "url": "https:\/\/domain.com\/vendor\/package-c\/1.0.0.zip"
     },
     "require": {
         "vendor\/package-b": "^1.0"'];
        yield ['invalid-removed-package', 'At least one package required "vendor/package-c" in "[>= 1.0.0.0-dev < 2.0.0.0-dev]" but it is missing in the composer.lock.'];
        yield ['invalid-removed-package-and-modified-composer-lock-requirements', 'The metadata of package "vendor/package-b" in version "1.0.0.0" does not match any of the metadata in the repositories. Diff (provided package / valid package): --- Original
+++ New
@@ @@
         "type": "zip",
         "url": "https:\/\/domain.com\/vendor\/package-b\/1.0.0.zip"
     },
+    "require": {
+        "vendor\/package-c": "^1.0"
+    },
     "type": "library"
 }'];
    }

    private function loadValidator(string $fixture): Validator
    {
        chdir($this->getFixtureDir($fixture));
        Platform::putEnv('COMPOSER', $this->getFilePathFromFixtureDir($fixture, 'composer.json'));

        return Validator::createFromComposerJson($this->getFilePathFromFixtureDir($fixture, 'composer.json'));
    }

    /**
     * @return array<mixed>
     */
    private function loadComposerLock(string $fixture, string $name = 'composer.lock'): array
    {
        return json_decode(file_get_contents($this->getFilePathFromFixtureDir($fixture, $name)), true);
    }

    private function getFilePathFromFixtureDir(string $fixture, string $file): string
    {
        return $this->getFixtureDir($fixture).'/'.$file;
    }

    private function getFixtureDir(string $fixture): string
    {
        return __DIR__.'/Fixtures/'.$fixture;
    }
}
