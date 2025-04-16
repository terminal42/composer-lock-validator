<?php

declare(strict_types=1);

namespace Terminal42\ComposerExternalLockValidator;

class ValidationException extends \LogicException
{
    public static function becauseNoPackageRequiresPackage(string $packageName, string $packageVersion): self
    {
        return new self(\sprintf(
            'The package "%s" in version "%s" is not required by any package in the composer.json or its transitive dependencies.',
            $packageName,
            $packageVersion,
        ));
    }

    public static function becausePackagesKeyMissingOrIncorrectInComposerLock(): self
    {
        return new self('The "composer.lock" must contain both, the "packages" and the "packages-dev" keys and they must be arrays.');
    }

    public static function becauseOfInvalidMetadataForPackage(string $packageName, string $packageVersion): self
    {
        return new self(\sprintf(
            'The metadata of package "%s" in version "%s" does not match any of the metadata in the repositories.',
            $packageName,
            $packageVersion,
        ));
    }

    public static function becauseOfRemovedPackage(string $packageName, string $prettyConstraint): self
    {
        return new self(\sprintf(
            'At least one package required "%s" in "%s" but it is missing in the composer.lock.',
            $packageName,
            $prettyConstraint,
        ));
    }

    public static function becauseOfOtherException(\Throwable $exception): self
    {
        return new self(\sprintf(
            'An unknown other exception has been thrown: %s',
            $exception->getMessage(),
        ), 0, $exception);
    }
}
