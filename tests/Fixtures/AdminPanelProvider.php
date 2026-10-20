<?php

namespace DurexKz\FilamentSecurity\Tests\Fixtures;

use DurexKz\FilamentSecurity\SecurityPlugin;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Tables\Columns\TextColumn;

class AdminPanelProvider extends PanelProvider
{
	public function panel(Panel $panel): Panel
	{
		return $panel
			->default()
			->id('admin')
			->path('admin')
			->plugins([
				SecurityPlugin::make()
					->navigationGroup('Система')
					->navigationSort(810)
					->widgetsLayout(errorLogs: 4, bannedIps: 2, sort: 1)
					->errorLogUserEntries(fn() => [TextEntry::make('user.email')->label('Email')])
					->errorLogUserColumns(fn() => [TextColumn::make('user.email')->label('Email')]),
			]);
	}
}
