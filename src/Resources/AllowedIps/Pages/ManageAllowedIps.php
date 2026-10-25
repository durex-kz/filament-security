<?php

namespace DurexKz\FilamentSecurity\Resources\AllowedIps\Pages;

use DurexKz\BotGuard\BotGuard;
use DurexKz\FilamentSecurity\Resources\AllowedIps\AllowedIpResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageAllowedIps extends ManageRecords
{
	protected static string $resource = AllowedIpResource::class;

	public function getTitle(): string|Htmlable
	{
		return __('Список');
	}

	protected function getHeaderActions(): array
	{
		return [
			// вручную, без блокировки: срок выбирается вариантом (1, 7, 30 дней, навсегда)
			CreateAction::make()
				->schema(AllowedIpResource::createFormComponents())
				->mutateDataUsing(function (array $data): array {
					$data['allowed_until'] = BotGuard::allowedModel()::untilFor($data['duration'] ?? null);
					unset($data['duration']);

					return $data;
				}),
		];
	}
}
