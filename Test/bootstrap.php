<?php
/**
 * Unit-test bootstrap. Uses this package's vendor tree normally; maintainers may point at an existing
 * Magento installation when repo.magento.com dependencies are not installed in the standalone clone.
 */

declare(strict_types=1);

$externalAutoload = getenv('VENUNO_MAGENTO_AUTOLOAD');
$autoload = is_string($externalAutoload) && $externalAutoload !== ''
    ? $externalAutoload
    : dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException(
        'Composer autoload not found. Run composer install or set VENUNO_MAGENTO_AUTOLOAD.'
    );
}
require $autoload;

// Prepend the working copy so an external Magento autoloader cannot select its installed Venuno release.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Venuno\\OrderImport\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = dirname(__DIR__) . '/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);
