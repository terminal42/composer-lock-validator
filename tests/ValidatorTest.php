<?php

declare(strict_types=1);

namespace Terminal42\ComposerLockValidator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Terminal42\ComposerLockValidator\ValidationException;
use Terminal42\ComposerLockValidator\Validator;

class ValidatorTest extends TestCase
{
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

    public static function passesValidationProvider(): \Generator
    {
        yield ['valid-simple'];
        yield ['valid-root-package-replaces'];
    }

    public static function failsValidationProvider(): \Generator
    {
        yield ['invalid-wrong-composer-lock-schema', 'The "composer.lock" must contain both, the "packages" and the "packages-dev" keys and they must be arrays.'];
        yield ['invalid-composer-internal-exception', 'An unknown other exception has been thrown: Invalid version string "this-is-some-fake-version".'];
        yield ['invalid-package-not-required', 'The package "vendor/package-d" in version "1.0.0.0" is not required by any package in the composer.json or its transitive dependencies.'];
        yield ['invalid-package-manipulated-meta-data', 'The metadata of package "vendor/package-c" in version "1.0.0.0" does not match any of the metadata in the repositories.'];
        yield ['invalid-removed-package', 'At least one package required "vendor/package-c" in "[>= 1.0.0.0-dev < 2.0.0.0-dev]" but it is missing in the composer.lock.'];
        yield ['invalid-removed-package-and-modified-composer-lock-requirements', 'The metadata of package "vendor/package-b" in version "1.0.0.0" does not match any of the metadata in the repositories.'];
    }

    private function loadValidator(string $fixture): Validator
    {
        return Validator::createFromComposerJson($this->getFilePathFromFixtureDir($fixture, 'composer.json'));
    }

    /**
     * @return array<mixed>
     */
    private function loadComposerLock(string $fixture): array
    {
        return json_decode(file_get_contents($this->getFilePathFromFixtureDir($fixture, 'composer.lock')), true);
    }

    private function getFilePathFromFixtureDir(string $fixture, string $file): string
    {
        return __DIR__.'/Fixtures/'.$fixture.'/'.$file;
    }
}
