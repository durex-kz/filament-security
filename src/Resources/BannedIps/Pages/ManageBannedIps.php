<?php

namespace DurexKz\FilamentSecurity\Resources\BannedIps\Pages;

use DurexKz\FilamentSecurity\Resources\BannedIps\BannedIpResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageBannedIps extends ManageRecords
{
	protected static string $resource = BannedIpResource::class;

	public function getTitle(): string|Htmlable
	{
		return __('Список');
	}

	protected function getHeaderActions(): array
	{
		return [
			CreateAction::make()
				->mutateDataUsing(function (array $data): array {
					$data['created_by'] = auth()->id();
					$data['is_auto'] = false;

					return $data;
				}),
		];
	}
}
