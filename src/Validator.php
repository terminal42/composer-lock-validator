<?php

declare(strict_types=1);

namespace Terminal42\ComposerExternalLockValidator;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

final class Validator
{
    private function __construct(private readonly Composer $composer)
    {
    }

    /**
     * @throws ValidationException
     */
    public function validate(\stdClass $composerLock): void
    {
        // 1st step, validate the composer.lock
        try {
            JsonFile::validateJsonSchema('composer.lock', $composerLock, JsonFile::LOCK_SCHEMA);
        } catch (JsonValidationException $e) {
            throw ValidationException::becauseComposerLockSchemaInvalid($e->getMessage(), $e->getErrors());
        }

        $rootPackage = clone $this->composer->getPackage();
        $composerLockRepo = new LockArrayRepository();
        $loader = new ArrayLoader();
        $composerLock = json_decode(json_encode($composerLock), true);
        $packagesToLoad = [];

        foreach ($composerLock['packages'] as $packageData) {
            $package = $loader->load($packageData);
            $composerLockRepo->addPackage($package);
            $packagesToLoad[$package->getName()] = new Constraint('=', $package->getVersion());
        }

        foreach ($composerLock['packages-dev'] as $packageData) {
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

        // 2nd step: validate if there is a package present, that is not required by the root composer.json
        foreach ($composerLockRepo->getPackages() as $package) {
            if ([] === $installedRepo->getDependents($package->getName(), null, false, false)) {
                throw ValidationException::becauseNoPackageRequiresPackage($package->getName(), $package->getVersion());
            }
        }

        $pool = $this->createMetadataPool($rootPackage, $composerLockRepo, $packagesToLoad);

        // 3rd step: validate if all the provided packages in the composer.lock actually exist in the repositories
        foreach ($composerLockRepo->getPackages() as $package) {
            $this->validatePackageMetadata($package, $pool);
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
     * @param array<string, ConstraintInterface> $packagesToLoad
     */
    private function createMetadataPool(RootPackageInterface $rootPackage, LockArrayRepository $composerLockRepo, array $packagesToLoad): Pool
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
