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

    /**
     * @param array<string> $errors
     */
    public static function becauseComposerLockSchemaInvalid(string $errorMessage, array $errors): self
    {
        return new self(\sprintf(
            'The "composer.lock" schema is invalid: %s. (%s)',
            $errorMessage,
            implode(', ', $errors),
        ));
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
}
