<?php

namespace DurexKz\FilamentSecurity\Resources\ErrorLogs\Pages;

use DurexKz\FilamentSecurity\Resources\ErrorLogs\ErrorLogResource;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageErrorLogs extends ManageRecords
{
	protected static string $resource = ErrorLogResource::class;

	public function getTitle(): string|Htmlable
	{
		return __('Список');
	}

	// создание записей в панели запрещено: ошибки пишет только обработчик исключений
	protected function getHeaderActions(): array
	{
		return [];
	}
}
