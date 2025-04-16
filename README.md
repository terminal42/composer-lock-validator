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
