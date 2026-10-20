<?php

namespace DurexKz\FilamentSecurity\Widgets;

use DurexKz\BotGuard\BotGuard;
use DurexKz\BotGuard\Models\BannedIp;
use DurexKz\FilamentSecurity\Resources\BannedIps\BannedIpResource;
use DurexKz\FilamentSecurity\SecurityPlugin;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Действующие блокировки IP на главной — только для администратора (gate bot-guard.admin_gate)
 */
class BannedIpsWidget extends TableWidget
{
	protected static bool $isLazy = false;

	protected static ?string $heading = 'Заблокированные IP';

	protected function getTableHeading(): ?string
	{
		return __(static::$heading);
	}

	public static function canView(): bool
	{
		return BotGuard::isAdmin(auth()->user());
	}

	// ширина и порядок — SecurityPlugin::widgetsLayout(); сразу после «Нерешённых ошибок»
	public function getColumnSpan(): int|string|array
	{
		return SecurityPlugin::current()->getBannedIpsWidgetColumnSpan();
	}

	public static function getSort(): int
	{
		$sort = SecurityPlugin::current()->getWidgetSort();

		return $sort === null ? parent::getSort() : $sort + 1;
	}

	public function table(Table $table): Table
	{
		return $table
			->poll(SecurityPlugin::current()->getPollingInterval()) // новые блокировки появляются без перезагрузки страницы
			->striped()
			->emptyStateHeading(__('Заблокированных IP нет'))
			->emptyStateIcon(Heroicon::OutlinedShieldCheck)
			->defaultPaginationPageOption(5)
			->paginated([5, 10, 25])
			->query(fn(): Builder => BotGuard::model()::query()->active()->latest('id'))
			->columns([
				TextColumn::make('ip')
					->copyable()
					->description(fn(BannedIp $record): ?string => BotGuard::model()::networkOf($record->ip))
					->label(__('IP-адрес')),
				TextColumn::make('reason')
					->limit(40)
					->tooltip(fn(BannedIp $record): ?string => $record->reason)
					->description(fn(BannedIp $record): string => $record->banned_until
						? __('до') . ' ' . $record->banned_until->format('d.m.Y H:i')
						: __('Бессрочно'))
					->label(__('Причина блокировки')),
				TextColumn::make('hits')
					->numeric()
					->label(__('Отклонено')),
			])
			->headerActions([
				Action::make('all')
					->link()
					->url(BannedIpResource::getUrl())
					->label(__('Весь справочник')),
			])
			->recordActions([
				DeleteAction::make()
					->iconButton()
					->icon(Heroicon::OutlinedLockOpen)
					->modalHeading(__('Разблокировать IP'))
					->tooltip(__('Разблокировать'))
					->label(__('Разблокировать')),
			]);
	}
}
