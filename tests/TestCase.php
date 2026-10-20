<?php

namespace DurexKz\FilamentSecurity\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use DurexKz\BotGuard\BotGuardServiceProvider;
use DurexKz\BotGuard\Models\BannedIp;
use DurexKz\ErrorMonitor\ErrorMonitorServiceProvider;
use DurexKz\FilamentSecurity\FilamentSecurityServiceProvider;
use DurexKz\FilamentSecurity\Tests\Fixtures\AdminPanelProvider;
use DurexKz\FilamentSecurity\Tests\Fixtures\User;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
	use RefreshDatabase;

	protected function getPackageProviders($app): array
	{
		return [
			ActionsServiceProvider::class,
			BladeHeroiconsServiceProvider::class,
			BladeIconsServiceProvider::class,
			FilamentServiceProvider::class,
			FormsServiceProvider::class,
			InfolistsServiceProvider::class,
			LivewireServiceProvider::class,
			NotificationsServiceProvider::class,
			SchemasServiceProvider::class,
			SupportServiceProvider::class,
			TablesServiceProvider::class,
			WidgetsServiceProvider::class,
			ErrorMonitorServiceProvider::class,
			BotGuardServiceProvider::class,
			FilamentSecurityServiceProvider::class,
			AdminPanelProvider::class,
		];
	}

	protected function defineEnvironment($app): void
	{
		$app['config']->set('database.default', 'testing');
		$app['config']->set('cache.default', 'array');
		$app['config']->set('auth.providers.users.model', User::class);
		$app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
	}

	protected function defineDatabaseMigrations(): void
	{
		Schema::create('users', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->string('email')->nullable();
			$table->string('password')->nullable();
			$table->boolean('is_admin')->default(false);
			$table->rememberToken();
			$table->timestamps();
		});
	}

	protected function setUp(): void
	{
		parent::setUp();

		BannedIp::flushCache();
		Gate::define('security-admin', fn(User $user) => $user->isGlobalAdmin());
	}

	protected function admin(): User
	{
		return User::create(['name' => 'Admin', 'email' => 'admin@example.kz', 'is_admin' => true]);
	}
}
