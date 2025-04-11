<?php

declare(strict_types=1);

namespace Terminal42\ComposerExternalLockValidator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Terminal42\ComposerExternalLockValidator\ValidationException;
use Terminal42\ComposerExternalLockValidator\Validator;

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
    }

    public static function failsValidationProvider(): \Generator
    {
        yield ['invalid-wrong-composer-lock-schema', 'The "composer.lock" schema is invalid: "composer.lock" does not match the expected JSON schema.'];
        yield ['invalid-package-not-required', 'The package "vendor/package-d" in version "1.0.0.0" is not required by any package in the composer.json or its transitive dependencies.'];
        yield ['invalid-package-manipulated-meta-data', 'The metadata of package "vendor/package-c" in version "1.0.0.0" does not match any of the metadata in the repositories.'];
    }

    private function loadValidator(string $fixture): Validator
    {
        return Validator::createFromComposerJson($this->getFilePathFromFixtureDir($fixture, 'composer.json'));
    }

    private function loadComposerLock(string $fixture): \stdClass
    {
        return json_decode(file_get_contents($this->getFilePathFromFixtureDir($fixture, 'composer.lock')));
    }

    private function getFilePathFromFixtureDir(string $fixture, string $file): string
    {
        return __DIR__.'/Fixtures/'.$fixture.'/'.$file;
    }
}
