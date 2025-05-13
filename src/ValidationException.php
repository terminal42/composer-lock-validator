<?php

declare(strict_types=1);

namespace Terminal42\ComposerLockValidator;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

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

    public static function becauseOfInvalidMetadataForPackage(string $packageName, string $packageVersion, array $providedPackage, array $validPackage): self
    {
        $differ = new Differ(new UnifiedDiffOutputBuilder());

        return new self(\sprintf(
            'The metadata of package "%s" in version "%s" does not match any of the metadata in the repositories. Diff (provided package / valid package): %s',
            $packageName,
            $packageVersion,
            $differ->diff(json_encode($providedPackage, JSON_PRETTY_PRINT), json_encode($validPackage, JSON_PRETTY_PRINT)),
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
            'An unknown other exception has been thrown: %s.',
            $exception->getMessage(),
        ), 0, $exception);
    }
}
