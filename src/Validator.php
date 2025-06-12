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

    public static function createFromComposerJson(string $pathToComposerJson, IOInterface|null $io = null): self
    {
        return new self(Factory::create($io ?? new NullIO(), $pathToComposerJson));
    }

    public static function createFromComposer(Composer $composer): self
    {
        return new self($composer);
    }

    /**
     * @param array<mixed>      $composerLock
     * @param array<mixed>|null $existingComposerLock
     *
     * @throws ValidationException
     */
    public function validate(array $composerLock, array|null $existingComposerLock = null): void
    {
        try {
            // 1st step, validate basic composer.lock requirements
            $composerLockRepository = $this->buildComposerLockRepository($composerLock);

            // Use the pool because this handles all the replaces and provides as well
            $pool = $this->createPool($composerLockRepository, $existingComposerLock ? $this->buildComposerLockRepository($existingComposerLock) : null);

            // 2nd step: validate if there is a package present, that is not required by the root composer.json
            // 3rd step: validate if no package has been removed from the composer.lock
            $this->validateNoAddedAndRemovedPackages($composerLockRepository, $pool);

            // 4th step: validate the metadata of all provided packages in the composer.lock.
            // In case of the full update, all the packages have to be compared against the repository data
            foreach ($composerLockRepository->getPackages() as $package) {
                $this->validatePackageMetadataAgainstRepositories($package, $pool, null !== $existingComposerLock);
            }
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

    private function validateNoAddedAndRemovedPackages(LockArrayRepository $composerLockRepo, Pool $pool): void
    {
        // Create an installed repo with our local root package repo and the provided composer.lock repo to check
        // for valid dependents.
        $installedRepo = new InstalledRepository([
            new RootPackageRepository($this->getRootPackage()),
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
    private function validatePackageMetadataAgainstRepositories(PackageInterface $package, Pool $pool, bool $addHintAboutLocalComposerLockOnFailure): void
    {
        $providedPackageArray = $this->dumpPackage($package);

        foreach ($pool->whatProvides($package->getName(), new Constraint('=', $package->getVersion())) as $validPackage) {
            $validPackageArray = $this->dumpPackage($validPackage);

            if ($providedPackageArray === $validPackageArray) {
                return; // Valid!
            }
        }

        throw ValidationException::becauseOfInvalidMetadataForPackage($package->getName(), $package->getVersion(), $providedPackageArray, $validPackageArray ?? [], $addHintAboutLocalComposerLockOnFailure);
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

    private function createPool(LockArrayRepository $composerLockRepo, LockArrayRepository|null $existingComposerLockRepo = null): Pool
    {
        $rootPackage = $this->getRootPackage();

        $repoSet = new RepositorySet(
            $rootPackage->getMinimumStability(),
            $rootPackage->getStabilityFlags(),
            $rootPackage->getAliases(),
            $rootPackage->getReferences(),
        );

        // Add the existing composer lock repo as repository for valid packages too, in case that was passed
        if ($existingComposerLockRepo) {
            $repoSet->addRepository($existingComposerLockRepo);
        }

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
     * @return RootPackageInterface&BasePackage
     */
    private function getRootPackage(): RootPackageInterface
    {
        // Always clone the root package because some Composer actions set information on the packages causing problems
        // later on
        return clone $this->composer->getPackage();
    }
}
