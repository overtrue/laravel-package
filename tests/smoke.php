<?php

/**
 * Install a renamed copy of this template into a real Laravel 13 application.
 * Requires Composer on PATH, network access, PHP 8.4+, and pdo_sqlite.
 */

namespace Tests\Smoke;

use FilesystemIterator;
use RuntimeException;
use Throwable;

function readFile(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

function writeFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Cannot write {$path}.");
    }
}

function copyPackage(string $source, string $destination, array $replacements): void
{
    if (is_link($source)) {
        throw new RuntimeException("Refusing to copy a symlink: {$source}.");
    }

    if (is_dir($source)) {
        if (! mkdir($destination, 0700)) {
            throw new RuntimeException("Cannot create {$destination}.");
        }

        foreach (new FilesystemIterator($source) as $entry) {
            copyPackage($entry->getPathname(), $destination.'/'.$entry->getFilename(), $replacements);
        }

        return;
    }

    writeFile($destination, strtr(readFile($source), $replacements));
}

function removeTree(string $directory): void
{
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            removeTree($entry->getPathname());
        } elseif (! unlink($entry->getPathname())) {
            throw new RuntimeException("Cannot remove {$entry->getPathname()}.");
        }
    }

    if (! rmdir($directory)) {
        throw new RuntimeException("Cannot remove {$directory}.");
    }
}

function run(array $command, string $directory, array $environment): void
{
    fwrite(STDOUT, PHP_EOL.'> '.implode(' ', $command).PHP_EOL);
    $process = proc_open($command, [STDIN, ['pipe', 'w'], ['redirect', 1]], $pipes, $directory, $environment);

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start '.implode(' ', $command));
    }

    stream_copy_to_stream($pipes[1], STDOUT);
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('Command failed with exit code '.$exitCode.': '.implode(' ', $command));
    }
}

try {
    if (PHP_VERSION_ID < 80400 || ! extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The smoke test requires PHP 8.4+ with pdo_sqlite.');
    }

    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).'/laravel-package-smoke-'.bin2hex(random_bytes(12));

    if (! mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create the temporary smoke-test directory.');
    }

    $owner = bin2hex(random_bytes(16));
    writeFile($root.'/.smoke-owner', $owner);

    // Only remove our private directory, and never follow links during cleanup.
    register_shutdown_function(static function () use ($root, $owner): void {
        try {
            if (is_link($root) || ! is_dir($root) || readFile($root.'/.smoke-owner') !== $owner) {
                throw new RuntimeException('Refusing cleanup of an unrecognized temporary directory.');
            }

            removeTree($root);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Smoke-test cleanup failed: '.$exception->getMessage().PHP_EOL);
            exit(1);
        }
    });

    $package = $root.'/package';
    $application = $root.'/application';
    mkdir($package, 0700);

    $original = json_decode(readFile(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $namespaces = array_keys(array_filter($original['autoload']['psr-4'],
        static fn ($path): bool => is_string($path) && rtrim($path, '/') === 'src'));
    $providerFiles = glob(dirname(__DIR__).'/src/*ServiceProvider.php');

    if (count($namespaces) !== 1 || count($providerFiles) !== 1) {
        throw new RuntimeException('The template must have one src namespace and one service provider.');
    }

    $originalNamespace = rtrim($namespaces[0], '\\');
    $providerClass = pathinfo($providerFiles[0], PATHINFO_FILENAME);
    $originalProvider = $originalNamespace.'\\'.$providerClass;
    $provider = 'Acme\\LaravelWidget\\'.$providerClass;
    $replacements = [
        $original['name'] => 'acme/laravel-widget',
        str_replace('\\', '\\\\', $originalNamespace) => 'Acme\\\\LaravelWidget',
        $originalNamespace => 'Acme\\LaravelWidget',
    ];

    foreach (['composer.json', 'LICENSE', 'src', 'migrations'] as $path) {
        copyPackage(dirname(__DIR__).'/'.$path, $package.'/'.$path, $replacements);
    }

    // This fixture exists only in the generated package, never in migrations/.
    $migration = '2026_01_01_000000_create_smoke_widgets_table.php';
    writeFile($package.'/migrations/'.$migration, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smoke_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smoke_widgets');
    }
};
PHP
    );

    $environment = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $application.'/database/database.sqlite',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'COMPOSER_NO_INTERACTION' => '1',
    ]);

    // Parent Composer/application settings must not redirect the fresh install.
    foreach (['COMPOSER', 'COMPOSER_VENDOR_DIR', 'COMPOSER_BIN_DIR', 'COMPOSER_NO_SCRIPTS',
        'APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE',
        'APP_EVENTS_CACHE'] as $variable) {
        unset($environment[$variable]);
    }

    $composer = getenv('COMPOSER_BINARY') ? [PHP_BINARY, getenv('COMPOSER_BINARY')] : ['composer'];
    run([...$composer, 'create-project', 'laravel/laravel', $application, '^13.0',
        '--prefer-dist', '--no-install', '--no-scripts', '--no-interaction'], $root, $environment);

    $manifest = json_decode(readFile($application.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['repositories'][] = [
        'type' => 'path',
        'url' => $package,
        'options' => [
            'symlink' => false,
            'versions' => ['acme/laravel-widget' => 'dev-main'],
        ],
    ];
    $manifest['require']['acme/laravel-widget'] = 'dev-main';
    writeFile($application.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    writeFile($application.'/.env', readFile($application.'/.env.example'));
    writeFile($application.'/database/database.sqlite', '');

    $providers = readFile($application.'/bootstrap/providers.php');

    // Keep Laravel's normal Composer scripts enabled to test automatic discovery.
    run([...$composer, 'install', '--prefer-dist', '--no-dev', '--no-interaction'], $application, $environment);
    run([...$composer, 'audit', '--no-dev', '--no-interaction'], $application, $environment);
    run([...$composer, 'check-platform-reqs', '--no-dev'], $application, $environment);

    if (readFile($application.'/bootstrap/providers.php') !== $providers) {
        throw new RuntimeException('The application provider list was unexpectedly modified.');
    }

    $verify = [PHP_BINARY, __DIR__.'/smoke/assertions.php', $application, $package, $migration,
        $provider, $original['name'], $originalProvider];
    run([...$verify, 'discovery'], $application, $environment);

    // Prove loadMigrationsFrom works even before the migration is published.
    run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], $application, $environment);
    run([...$verify, 'migrated'], $application, $environment);

    run([PHP_BINARY, 'artisan', 'vendor:publish', '--provider='.$provider,
        '--tag=migrations', '--no-interaction'], $application, $environment);
    run([...$verify, 'published'], $application, $environment);

    // A published copy must not cause duplicate execution of the same migration.
    run([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], $application, $environment);
    run([...$verify, 'migrated'], $application, $environment);
    run([PHP_BINARY, 'artisan', 'migrate:rollback', '--force', '--no-interaction'], $application, $environment);
    run([...$verify, 'rolled-back'], $application, $environment);

    fwrite(STDOUT, PHP_EOL.'Generated-package Laravel 13 smoke test passed.'.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, PHP_EOL.'Smoke test failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
