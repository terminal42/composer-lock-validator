<?php

declare(strict_types=1);

namespace Terminal42\ComposerLockValidator;

enum PartialValidationMode
{
    case UpdateOnlyListed;
    case UpdateListedWithTransitiveDepsNoRootRequire;
    case UpdateListedWithTransitiveDeps;
}
