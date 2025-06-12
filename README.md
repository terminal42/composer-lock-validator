## Composer lock file validator

This library allows to compare a given `composer.lock` file against your local Composer instance.
 
You can use it to e.g. ensure a provided `composer.lock` does not contain any foreign packages (not required by your Composer 
instance - aka `composer.json`) or package URLs that have been tampered with. It also detects removed packages that 
should be present.

Usage:

```php
use \Terminal42\ComposerLockValidator\Validator;
use \Terminal42\ComposerLockValidator\ValidationException;

$composerLock = [
    'content-hash' => '...',
    'packages' => [...]
    'packages-dev' => [...]
];

// You can either pass an already existing Composer instance
$validator = Validator::createFromComposer($composer);
// Or provide a path to your composer.json
$validator = Validator::createFromComposerJson($pathToComposerJson);

try {
    $validator->validate($composerLock);
} catch (ValidationException $exception) {
    echo 'Invalid: ' . $exception->getMessage();
}

echo 'Valid!';
```


### Partial validation / validation against existing `composer.lock`

When you run `composer update` as a partial update (e.g. `composer update <vendor/package> --with-dependencies`), Composer
will not update the `composer.lock` information of all the other packages. Hence, validating will probably fail because one
of the other packages have experienced metadata updates in the meantime (new URL, probably `abandoned`, different `branch-aliases` etc.).
In such a case, you might want to add your already existing `composer.lock` file as additional source of truth. Every
package in the `composer.lock` you want to validate then has to either match the metadata of the repositories or the entry
of an already existing `composer.lock`. Simply pass the data of the existing `composer.lock` as second argument:

```php
use \Terminal42\ComposerLockValidator\Validator;
use \Terminal42\ComposerLockValidator\ValidationException;

$composerLock = [
    'content-hash' => '...',
    'packages' => [...]
    'packages-dev' => [...]
];

$alreadyExistingComposerLockITrust = [
    'content-hash' => '...',
    'packages' => [...]
    'packages-dev' => [...]
];

// You can either pass an already existing Composer instance
$validator = Validator::createFromComposer($composer);
// Or provide a path to your composer.json
$validator = Validator::createFromComposerJson($pathToComposerJson);

try {
    $validator->validate($composerLock, $alreadyExistingComposerLockITrust);
} catch (ValidationException $exception) {
    echo 'Invalid: ' . $exception->getMessage();
}

echo 'Valid!';
```
