<?php

declare(strict_types=1);

namespace Terminal42\ComposerLockValidator;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\BasePackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;

final class Validator
{
    private function __construct(private readonly Composer $composer)
    {
    }

    /**
     * @param array<mixed>            $existingComposerLock
     * @param array<mixed>            $newComposerLock
     * @param non-empty-array<string> $packageList
     *
     * @throws ValidationException
     */
    public function validatePartial(array $existingComposerLock, array $newComposerLock, array $packageList, PartialValidationMode $partialValidationMode): void
    {
        if ([] === $packageList) {
            throw new ValidationException('Cannot create a partial update config without packages');
        }

        // 1st step, validate existing composer.lock
        $existingComposerLockRepository = $this->buildComposerLockRepository($existingComposerLock);

        $this->doValidate(
            $newComposerLock,
            function (LockArrayRepository $composerLockRepository, Pool $pool) use ($existingComposerLockRepository, $packageList, $partialValidationMode): void {
                // In case of the partial update, we first need to split the packages into the ones that need to be compared
                // against the repositories and the ones that need to be compared against the local composer lock
                $packagesToValidateAgainstRemoteRepositories = $this->getPackagesToValidateAgainstRemoteRepositories(
                    $packageList,
                    $partialValidationMode,
                );

                foreach ($composerLockRepository->getPackages() as $package) {
                    if (\in_array($package->getName(), $packagesToValidateAgainstRemoteRepositories, true)) {
                        $this->validatePackageMetadataAgainstRepositories($package, $pool);
                    } else {
                        $this->validatePackageMetadataAgainstLocalComposerLock($package, $existingComposerLockRepository);
                    }
                }
            },
        );
    }

    /**
     * @param array<mixed> $composerLock
     *
     * @throws ValidationException
     */
    public function validate(array $composerLock): void
    {
        $this->doValidate(
            $composerLock,
            function (LockArrayRepository $composerLockRepository, Pool $pool): void {
                // In case of the full update, all the packages have to be compared against the repository data
                foreach ($composerLockRepository->getPackages() as $package) {
                    $this->validatePackageMetadataAgainstRepositories($package, $pool);
                }
            },
        );
    }

    public static function createFromComposerJson(string $pathToComposerJson, IOInterface|null $io = null): self
    {
        return new self(Factory::create($io ?? new NullIO(), $pathToComposerJson));
    }

    public static function createFromComposer(Composer $composer): self
    {
        return new self($composer);
    }

    /**
     * @param array<mixed>                                                        $composerLock
     * @param \Closure(LockArrayRepository $metadataValidation, Pool $pool): void $metadataValidation
     *
     * @throws ValidationException
     */
    private function doValidate(array $composerLock, \Closure $metadataValidation): void
    {
        /** @var RootPackageInterface&BasePackage $rootPackage */
        $rootPackage = clone $this->composer->getPackage();

        try {
            // 1st step, validate basic composer.lock requirements
            $composerLockRepository = $this->buildComposerLockRepository($composerLock);

            // Use the pool because this handles all the replaces and provides as well
            $pool = $this->createPool(clone $rootPackage, $composerLockRepository);

            // 2nd step: validate if there is a package present, that is not required by the root composer.json
            // 3rd step: validate if no package has been removed from the composer.lock
            $this->validateNoAddedAndRemovedPackages($composerLockRepository, clone $rootPackage, $pool);

            // 4th step: validate the metadata of all provided packages in the composer.lock.
            $metadataValidation($composerLockRepository, $pool);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::becauseOfOtherException($exception);
        }
    }

    /**
     * @param array<mixed> $composerLock
     */
    private function buildComposerLockRepository(array $composerLock): LockArrayRepository
    {
        if (
            !isset($composerLock['packages'], $composerLock['packages-dev'])
            || !\is_array($composerLock['packages']) || !\is_array($composerLock['packages-dev'])
        ) {
            throw ValidationException::becausePackagesKeyMissingOrIncorrectInComposerLock();
        }

        $composerLockRepo = new LockArrayRepository();
        $loader = new ArrayLoader();

        foreach (array_merge($composerLock['packages'], $composerLock['packages-dev']) as $packageData) {
            $package = $loader->load($packageData);
            $composerLockRepo->addPackage($package);
        }

        return $composerLockRepo;
    }

    /**
     * @param RootPackageInterface&BasePackage $rootPackage
     */
    private function validateNoAddedAndRemovedPackages(LockArrayRepository $composerLockRepo, RootPackageInterface $rootPackage, Pool $pool): void
    {
        // Create an installed repo with our local root package repo and the provided composer.lock repo to check
        // for valid dependents.
        $installedRepo = new InstalledRepository([
            new RootPackageRepository($rootPackage),
            $composerLockRepo,
        ]);

        $allRequirements = [];

        // 2nd step: validate if there is a package present, that is not required by the root composer.json
        foreach ($composerLockRepo->getPackages() as $package) {
            if ([] === $installedRepo->getDependents($package->getName(), null, false, false)) {
                throw ValidationException::becauseNoPackageRequiresPackage($package->getName(), $package->getVersion());
            }

            // Collect requirements for the 3rd step
            foreach ($package->getRequires() as $require) {
                if (PlatformRepository::isPlatformPackage($require->getTarget())) {
                    continue;
                }

                $target = $require->getTarget();
                $constraint = $require->getConstraint();

                // Widen the requirement if one package required this package already
                $allRequirements[$target] = isset($allRequirements[$target])
                    ? new MultiConstraint([$allRequirements[$target], $constraint], false)
                    : $constraint;
            }
        }

        // 3rd step: validate that no package has been removed from the composer.lock
        foreach ($allRequirements as $packageName => $constraint) {
            $constraint = Intervals::compactConstraint($constraint);
            if ([] === $pool->whatProvides($packageName, $constraint)) {
                throw ValidationException::becauseOfRemovedPackage($packageName, $constraint->getPrettyString());
            }
        }
    }

    /**
     * @throws ValidationException
     * @throws \Throwable
     */
    private function validatePackageMetadataAgainstRepositories(PackageInterface $package, Pool $pool): void
    {
        $providedPackageArray = $this->dumpPackage($package);

        foreach ($pool->whatProvides($package->getName(), new Constraint('=', $package->getVersion())) as $validPackage) {
            $validPackageArray = $this->dumpPackage($validPackage);

            if ($providedPackageArray === $validPackageArray) {
                return; // Valid!
            }
        }

        throw ValidationException::becauseOfInvalidMetadataForPackageInRepositories($package->getName(), $package->getVersion(), $providedPackageArray, $validPackageArray ?? []);
    }

    /**
     * @return array<mixed>
     */
    private function dumpPackage(PackageInterface $package): array
    {
        $dumper = new ArrayDumper();
        $dump = $dumper->dump($package);

        // Remove useless keys that might cause issues when validating because Composer tampers with those in the Locker.
        // They are not relevant for integrity checks anyway (still cannot fake download URLs or wrong requires etc.)
        unset($dump['version_normalized'], $dump['time'], $dump['installation-source']);

        // Remove reference and transport-options for path repositories
        if (isset($dump['dist']['type']) && 'path' === $dump['dist']['type']) {
            unset($dump['dist']['reference'], $dump['transport-options']);
        }

        // Sort branch aliases
        if (isset($dump['extra']['branch-alias'])) {
            ksort($dump['extra']['branch-alias']);
        }

        return $dump;
    }

    /**
     * @param RootPackageInterface&BasePackage $rootPackage
     */
    private function createPool(RootPackageInterface $rootPackage, LockArrayRepository $composerLockRepo): Pool
    {
        $repoSet = new RepositorySet(
            $rootPackage->getMinimumStability(),
            $rootPackage->getStabilityFlags(),
            $rootPackage->getAliases(),
            $rootPackage->getReferences(),
        );

        $repoSet->addRepository(new RootPackageRepository($rootPackage));

        foreach ($this->composer->getRepositoryManager()->getRepositories() as $repo) {
            $repoSet->addRepository($repo);
        }

        $request = new Request($composerLockRepo);

        $request->fixPackage($rootPackage);
        if ($rootPackage instanceof RootAliasPackage) {
            $request->fixPackage($rootPackage->getAliasOf());
        }

        $allowedPackages = [];

        foreach ($composerLockRepo->getPackages() as $package) {
            $packageName = $package->getName();

            $request->requireName($packageName, new Constraint('=', $package->getVersion()));
            $allowedPackages[] = strtolower($packageName);
        }

        if (\count($allowedPackages) > 0) {
            $request->restrictPackages($allowedPackages);
        }

        return $repoSet->createPool($request, new NullIO());
    }

    /**
     * @param non-empty-array<string> $packageList
     *
     * @return array<string>
     */
    private function getPackagesToValidateAgainstRemoteRepositories(array $packageList, PartialValidationMode $partialValidationMode): array
    {
        // Only the listed packages with no dependencies
        if (PartialValidationMode::UpdateOnlyListed === $partialValidationMode) {
            return $packageList;
        }

        // Load all dependencies of the package list
        $dependentPackages = []; // TODO: implement me

        // The listed packages plus all their transitive dependencies
        if (PartialValidationMode::UpdateListedWithTransitiveDeps === $partialValidationMode) {
            return array_merge($packageList, $dependentPackages);
        }

        // Otherwise, we are PartialValidationMode::UpdateListedWithTransitiveDepsNoRootRequire in which case we must
        // remove the root requirements and their transitive deps from the $dependentPackages
        $rootRequiredPackages = []; // TODO: implement me

        return array_merge($packageList, array_diff($dependentPackages, $rootRequiredPackages));
    }

    /**
     * @throws ValidationException
     */
    private function validatePackageMetadataAgainstLocalComposerLock(PackageInterface $package, LockArrayRepository $existingComposerLockRepository): void
    {
        $existingPackage = $existingComposerLockRepository->findPackage($package->getName(), new Constraint('=', $package->getVersion()));

        // That really shouldn't happen, but hey...
        if (null === $existingPackage) {
            throw ValidationException::becauseOfAPackageThatShouldExistInComposerLockButDoesApparentlyNot($package->getName(), $package->getPrettyVersion());
        }

        $providedPackageArray = $this->dumpPackage($package);
        $validPackageArray = $this->dumpPackage($existingPackage);

        if ($providedPackageArray !== $validPackageArray) {
            throw ValidationException::becauseOfInvalidMetadataForPackageInLocalComposerLock($package->getName(), $package->getVersion(), $providedPackageArray, $validPackageArray);
        }
    }
}
