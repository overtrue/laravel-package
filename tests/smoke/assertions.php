<?php

namespace Tests\Smoke;

use Carbon\CarbonInterface;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    [, $application, $package, $migration, $provider, $originalPackage, $originalProvider, $phase] = $argv;
    $installedPackage = $application.'/vendor/acme/laravel-widget';
    $publishedMigration = $application.'/database/migrations/'.$migration;

    // Check the Composer-generated manifest before boot can rebuild it for us.
    expect(is_file($application.'/bootstrap/cache/packages.php'), 'Composer did not produce a package manifest.');
    $discovered = require $application.'/bootstrap/cache/packages.php';
    $providers = array_map(static fn (string $class): string => ltrim($class, '\\'),
        $discovered['acme/laravel-widget']['providers'] ?? []);
    expect($providers === [$provider], 'The renamed provider was not automatically discovered.');

    if ($originalPackage !== 'acme/laravel-widget') {
        expect(! isset($discovered[$originalPackage]), 'The template package name remains in discovery.');
    }

    require $application.'/vendor/autoload.php';

    expect(InstalledVersions::isInstalled('acme/laravel-widget'), 'Composer did not install the generated package.');
    expect(! InstalledVersions::isInstalled('phpstan/phpstan'), 'PHPStan leaked into the package runtime dependencies.');

    if ($originalPackage !== 'acme/laravel-widget') {
        expect(! InstalledVersions::isInstalled($originalPackage), 'Composer installed the original package name.');
    }

    if ($originalProvider !== $provider) {
        expect(! class_exists($originalProvider), 'The original namespace is still autoloadable.');
    }
    expect(! is_link($installedPackage), 'The generated package must be installed as a mirror, not a symlink.');

    $app = require $application.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    expect(str_starts_with($app->version(), '13.'), 'The smoke application is not Laravel 13.');
    expect($app->getProvider($provider) instanceof $provider, 'Laravel did not load the discovered provider.');
    expect(! in_array($provider, require $application.'/bootstrap/providers.php', true), 'The provider was manually registered.');

    $mapping = [$installedPackage.'/migrations/' => $application.'/database/migrations'];
    expect(ServiceProvider::pathsToPublish($provider) === $mapping, 'The provider publication mapping is incorrect.');
    expect(ServiceProvider::pathsToPublish($provider, 'migrations') === $mapping, 'The migrations tag mapping is incorrect.');
    expect(in_array($installedPackage.'/migrations/', $app->make('migrator')->paths(), true), 'The package migration path was not loaded.');
    expect(DB::connection()->getDriverName() === 'sqlite', 'The smoke database is not SQLite.');
    expect(DB::connection()->getDatabaseName() === $application.'/database/database.sqlite', 'The smoke database is outside the temporary app.');

    if ($phase === 'discovery') {
        expect(! file_exists($publishedMigration), 'The fixture was already published before vendor:publish.');
        expect(! Schema::hasTable('smoke_widgets'), 'The fixture table exists before migrate.');
    } elseif ($phase === 'published') {
        expect(is_file($publishedMigration), 'vendor:publish did not copy the migration.');
        expect(file_get_contents($publishedMigration) === file_get_contents($package.'/migrations/'.$migration),
            'The published migration does not exactly match the fixture.');
    } elseif ($phase === 'migrated') {
        expect(Schema::hasColumns('smoke_widgets', ['id', 'name', 'active', 'created_at', 'updated_at']),
            'The migration did not create the expected schema.');
        expect(DB::table('migrations')->where('migration', pathinfo($migration, PATHINFO_FILENAME))->count() === 1,
            'The fixture migration must run exactly once.');

        $widget = new class extends Model
        {
            protected $table = 'smoke_widgets';

            protected $fillable = ['name'];

            protected function casts(): array
            {
                return ['active' => 'boolean'];
            }
        };

        $record = $widget->newQuery()->create(['name' => 'Original widget']);
        $record->refresh();
        expect($record->exists && $record->getKey() > 0, 'Eloquent did not persist the widget.');
        expect($record->active === false, 'The database default or boolean cast is incorrect.');
        expect($record->created_at instanceof CarbonInterface && $record->updated_at instanceof CarbonInterface,
            'Eloquent did not populate timestamps.');

        $record->name = 'Updated widget';
        $record->active = true;
        expect($record->save(), 'Eloquent could not update the widget.');
        $found = $widget->newQuery()->findOrFail($record->getKey());
        expect($found->name === 'Updated widget' && $found->active === true, 'Eloquent did not read the updated values.');
        expect(DB::table('smoke_widgets')->where('id', $record->getKey())->value('name') === 'Updated widget',
            'The Eloquent update was not persisted in SQLite.');
        expect($found->delete() === true && $widget->newQuery()->count() === 0, 'Eloquent did not delete the widget.');
    } elseif ($phase === 'rolled-back') {
        expect(! Schema::hasTable('smoke_widgets'), 'Rollback did not remove the fixture table.');
        expect(DB::table('migrations')->where('migration', pathinfo($migration, PATHINFO_FILENAME))->count() === 0,
            'Rollback did not remove the migration record.');
    } else {
        throw new RuntimeException('Unknown smoke-test phase: '.$phase);
    }

    fwrite(STDOUT, "Verified {$phase} on Laravel {$app->version()}.".PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Smoke assertion failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
