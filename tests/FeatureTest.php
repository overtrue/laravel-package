<?php

namespace Tests;

use Illuminate\Support\ServiceProvider;
use Overtrue\LaravelPackage\PackageServiceProvider;

class FeatureTest extends TestCase
{
    public function test_package_provider_is_registered(): void
    {
        $this->assertInstanceOf(
            PackageServiceProvider::class,
            $this->app->getProvider(PackageServiceProvider::class)
        );
    }

    public function test_package_migrations_are_publishable(): void
    {
        $this->assertSame([
            dirname(__DIR__).'/migrations/' => database_path('migrations'),
        ], ServiceProvider::pathsToPublish(PackageServiceProvider::class, 'migrations'));
    }

    public function test_package_migrations_are_registered_with_the_migrator(): void
    {
        $this->assertContains(
            dirname(__DIR__).'/migrations/',
            $this->app['migrator']->paths()
        );
    }

    public function test_user_model_can_persist_query_update_and_delete(): void
    {
        $user = User::create(['name' => 'Laravel package']);

        $this->assertTrue($user->exists);
        $this->assertNotNull($user->created_at);
        $this->assertSame('Laravel package', User::findOrFail($user->id)->name);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'private' => false]);

        $user->update(['name' => 'Updated package']);

        $this->assertSame('Updated package', $user->fresh()->name);
        $this->assertSame(1, User::where('name', 'Updated package')->count());

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
