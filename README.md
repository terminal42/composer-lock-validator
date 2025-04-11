## Composer external lock file validator

This library allows to compare an external `composer.lock` file against your local Composer instance.
So you can be sure the provided `composer.lock` does not contain any foreign packages (not required by your Composer 
instance - aka `composer.json`) or package URLs that have been tampered with.

Usage:

```php
use \Terminal42\ComposerExternalLockValidator\Validator;
use \Terminal42\ComposerExternalLockValidator\ValidationException;

$externalComposerLock = [
    'content-hash' => '...',
    'packages' => [...]
    'packages-dev' => [...]
];

// You can either pass an already existing Composer instance
$validator = Validator::createFromComposer($composer);
// Or provide a path to your composer.json
$validator = Validator::createFromComposerJson($pathToComposerJson);

try {
    $validator->validate($externalComposerLock);
} catch (ValidationException $exception) {
    echo 'Invalid: ' . $exception->getMessage();
}

echo 'Valid!';
```
