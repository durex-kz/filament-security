<?php

namespace DurexKz\FilamentSecurity;

use Illuminate\Support\ServiceProvider;

class FilamentSecurityServiceProvider extends ServiceProvider
{
	public function boot(): void
	{
		// ключи переводов — русские строки (как в проектах); lang/*.json приложения имеет приоритет
		$this->loadJsonTranslationsFrom(__DIR__ . '/../lang');

		if ($this->app->runningInConsole()) {
			$this->publishes([
				__DIR__ . '/../lang' => $this->app->langPath('vendor/filament-security'),
			], 'filament-security-translations');
		}
	}
}
