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
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;

final class Validator
{
    private function __construct(private readonly Composer $composer)
    {
    }

    /**
     * @param array<mixed> $composerLock
     *
     * @throws ValidationException
     */
    public function validate(array $composerLock): void
    {
        try {
            $this->doValidate($composerLock);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::becauseOfOtherException($exception);
        }
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
     * @param array<mixed> $composerLock
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    private function doValidate(array $composerLock): void
    {
        // 1st step, validate basic composer.lock requirements
        if (
            !isset($composerLock['packages'], $composerLock['packages-dev'])
            || !\is_array($composerLock['packages']) || !\is_array($composerLock['packages-dev'])
        ) {
            throw ValidationException::becausePackagesKeyMissingOrIncorrectInComposerLock();
        }

        /** @var RootPackageInterface&BasePackage $rootPackage */
        $rootPackage = clone $this->composer->getPackage();
        $composerLockRepo = new LockArrayRepository();
        $loader = new ArrayLoader();
        $packagesToLoad = [];

        foreach (array_merge($composerLock['packages'], $composerLock['packages-dev']) as $packageData) {
            $package = $loader->load($packageData);
            $composerLockRepo->addPackage($package);
            $packagesToLoad[$package->getName()] = new Constraint('=', $package->getVersion());
        }

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

        // Use the pool because this handles all the replaces and provides as well
        $pool = $this->createPool($rootPackage, $composerLockRepo, $packagesToLoad);

        // 3rd step: validate if no package has been removed from the composer.lock
        foreach ($allRequirements as $packageName => $constraint) {
            $constraint = Intervals::compactConstraint($constraint);
            if ([] === $pool->whatProvides($packageName, $constraint)) {
                throw ValidationException::becauseOfRemovedPackage($packageName, $constraint->getPrettyString());
            }
        }

        // 4th step: validate if all the provided packages in the composer.lock actually exist in the repositories
        foreach ($composerLockRepo->getPackages() as $package) {
            $this->validatePackageMetadata($package, $pool);
        }
    }

    private function validatePackageMetadata(PackageInterface $package, Pool $pool): void
    {
        $dumper = new ArrayDumper();
        $checkArray = $dumper->dump($package);

        foreach ($pool->whatProvides($package->getName(), new Constraint('=', $package->getVersion())) as $validPackage) {
            $packageArray = $dumper->dump($validPackage);

            if ($checkArray === $packageArray) {
                return; // Valid!
            }
        }

        throw ValidationException::becauseOfInvalidMetadataForPackage($package->getName(), $package->getVersion());
    }

    /**
     * @param RootPackageInterface&BasePackage   $rootPackage
     * @param array<string, ConstraintInterface> $packagesToLoad
     */
    private function createPool(RootPackageInterface $rootPackage, LockArrayRepository $composerLockRepo, array $packagesToLoad): Pool
    {
        $repoSet = new RepositorySet(
            $rootPackage->getMinimumStability(),
            $rootPackage->getStabilityFlags(),
            $rootPackage->getAliases(),
            $rootPackage->getReferences(),
        );

        foreach ($this->composer->getRepositoryManager()->getRepositories() as $repo) {
            $repoSet->addRepository($repo);
        }

        $request = new Request($composerLockRepo);

        $request->fixPackage($rootPackage);
        if ($rootPackage instanceof RootAliasPackage) {
            $request->fixPackage($rootPackage->getAliasOf());
        }

        $allowedPackages = [];

        foreach ($packagesToLoad as $packageName => $constraint) {
            $request->requireName($packageName, $constraint);
            $allowedPackages[] = strtolower($packageName);
        }

        if (\count($allowedPackages) > 0) {
            $request->restrictPackages($allowedPackages);
        }

        return $repoSet->createPool($request, new NullIO());
    }
}
