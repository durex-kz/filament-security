<?php

namespace DurexKz\FilamentSecurity\Widgets;

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use DurexKz\FilamentSecurity\Resources\ErrorLogs\ErrorLogResource;
use DurexKz\FilamentSecurity\SecurityPlugin;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Нерешённые ошибки системы на главной — только для администратора (gate error-monitor.admin_gate)
 */
class UnresolvedErrorLogsWidget extends TableWidget
{
	protected static bool $isLazy = false;

	protected static ?string $heading = 'Нерешённые ошибки';

	protected function getTableHeading(): ?string
	{
		return __(static::$heading);
	}

	public static function canView(): bool
	{
		return ErrorMonitor::isAdmin(auth()->user());
	}

	// ширина и порядок — SecurityPlugin::widgetsLayout()
	public function getColumnSpan(): int|string|array
	{
		return SecurityPlugin::current()->getErrorLogsWidgetColumnSpan();
	}

	public static function getSort(): int
	{
		return SecurityPlugin::current()->getWidgetSort() ?? parent::getSort();
	}

	public function table(Table $table): Table
	{
		return $table
			->poll(SecurityPlugin::current()->getPollingInterval()) // новые записи появляются без перезагрузки страницы
			->striped()
			->emptyStateHeading(__('Нерешённых ошибок нет'))
			->emptyStateIcon(Heroicon::OutlinedCheckCircle)
			->defaultPaginationPageOption(5)
			->paginated([5, 10, 25])
			// одна строка на ошибку (повторы — в счётчике), последние сверху
			->query(fn(): Builder => ErrorMonitor::model()::query()->unresolved()->latestOfRepeats()->withRepeatsCount()->latest('id'))
			->recordAction(ViewAction::class)
			->columns([
				TextColumn::make('code')
					->badge()
					->color(fn(?int $state): string => ErrorLogResource::codeColor($state))
					->label(__('Код')),
				TextColumn::make('repeats_count')
					->getStateUsing(fn(ErrorLog $record): ?int => ($record->repeats_count ?? 0) > 1 ? $record->repeats_count : null)
					->formatStateUsing(fn(int $state): string => '×' . $state)
					->badge()
					->color('warning')
					->placeholder('')
					->label(__('Повторы')),
				TextColumn::make('created_at')
					->dateTime()
					->wrap()
					->label(__('Дата')),
				TextColumn::make('message')
					->limit(40)
					->tooltip(fn(ErrorLog $record): ?string => $record->message)
					->description(fn(ErrorLog $record): ?string => $record->url ? str($record->url)->limit(40)->toString() : null)
					->placeholder('—')
					->label(__('Сообщение')),
			])
			->headerActions([
				Action::make('all')
					->link()
					->url(ErrorLogResource::getUrl())
					->label(__('Весь журнал')),
			])
			->recordActions([
				ViewAction::make()
					->iconButton()
					->schema(fn(Schema $schema): Schema => ErrorLogResource::infolist($schema))
					->extraModalFooterActions([ErrorLogResource::copyReportAction()]),
				Action::make('resolve')
					->iconButton()
					->icon(Heroicon::OutlinedCheckCircle)
					->color('success')
					->authorize('resolve')
					->schema(ErrorLogResource::resolveFormSchema())
					->modalSubmitActionLabel(__('Исправлено'))
					->action(fn(ErrorLog $record, array $data) => ErrorLogResource::resolveRecords([$record], $data))
					->tooltip(__('Исправлено'))
					->label(__('Исправлено')),
			]);
	}
}
