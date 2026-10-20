<?php

namespace DurexKz\FilamentSecurity\Resources\ErrorLogs;

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use DurexKz\FilamentSecurity\Actions\CopyAction;
use DurexKz\FilamentSecurity\Resources\ErrorLogs\Pages\ManageErrorLogs;
use DurexKz\FilamentSecurity\SecurityPlugin;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class ErrorLogResource extends Resource
{
	protected static ?string $model = ErrorLog::class;

	protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;


	protected static ?string $label = 'Ошибка'; // заголовок формы

	protected static ?string $pluralLabel = 'Журнал ошибок'; // заголовок списка

	// модель из config error-monitor.model (приложение может её расширить)
	public static function getModel(): string
	{
		return ErrorMonitor::model();
	}

	// группа и позиция в меню — настройки плагина: SecurityPlugin::make()->navigationGroup(...)->navigationSort(...)
	public static function getNavigationGroup(): string|UnitEnum|null
	{
		return SecurityPlugin::current()->getNavigationGroup();
	}

	public static function getNavigationSort(): ?int
	{
		return SecurityPlugin::current()->getNavigationSort();
	}

	public static function getLabel(): ?string
	{
		return __(self::$label);
	}

	public static function getPluralLabel(): ?string
	{
		return __(self::$pluralLabel);
	}

	public static function getNavigationLabel(): string
	{
		return __(self::$pluralLabel);
	}

	/*
		количество неисправленных ошибок рядом с названием в главном меню
	*/
	public static function getNavigationBadge(): ?string
	{
		return static::getModel()::unresolved()->count() ?: null;
	}

	public static function getNavigationBadgeColor(): ?string
	{
		return 'danger';
	}

	/*
		цвет кода: 5xx — ошибка сервера, 4xx — ошибка запроса
	*/
	public static function codeColor(?int $code): string
	{
		return match (true) {
			$code >= 500 => 'danger',
			$code >= 400 => 'warning',
			default => 'gray',
		};
	}

	public static function infolist(Schema $schema): Schema
	{
		return $schema
			->columns(1)
			->components([
				Section::make(__('Ошибка'))
					->collapsible()
					->columns(3)
					->schema([
						TextEntry::make('code')
							->suffixAction(CopyAction::make())
							->badge()
							->color(fn(?int $state): string => static::codeColor($state))
							->label(__('Код')),
						TextEntry::make('created_at')
							->suffixAction(CopyAction::make())
							->dateTime()
							->label(__('Дата')),
						TextEntry::make('exception')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Исключение')),
						TextEntry::make('root_exception')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Исходное исключение')),
						TextEntry::make('repeats_total')
							->state(fn(ErrorLog $record): int => $record->repeats()->count() + 1)
							->badge()
							->color(fn(int $state): string => $state > 1 ? 'warning' : 'gray')
							->label(__('Повторов всего')),
						TextEntry::make('repeats_unresolved')
							->state(fn(ErrorLog $record): int => $record->repeats()->unresolved()->count())
							->badge()
							->color(fn(int $state): string => $state > 0 ? 'danger' : 'gray')
							->label(__('Других неисправленных')),
						TextEntry::make('message')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->weight(FontWeight::Bold)
							// длинные SQL, пути и адреса без пробелов переносятся, а не вылезают за край окна
							->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere'])
							->columnSpanFull()
							->label(__('Сообщение')),
						TextEntry::make('file')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->formatStateUsing(fn(ErrorLog $record): string => $record->file . ':' . $record->line)
							->columnSpanFull()
							->label(__('Файл')),
						// первое место в app/, resources/, routes/, database/ — у ошибок в шаблонах и SQL «Файл» указывает во vendor
						TextEntry::make('app_file')
							->suffixAction(CopyAction::make())
							->formatStateUsing(fn(ErrorLog $record): ?string => $record->appLocation())
							->placeholder('—')
							->weight(FontWeight::Bold)
							->columnSpanFull()
							->label(__('Место в коде')),
					]),

				// описание решения — сразу под ошибкой, не свёрнуто; кнопка в заголовке — дописать/исправить текст
				Section::make(__('Решение'))
					->collapsible()
					->headerActions([
						Action::make('editResolution')
							->icon(Heroicon::OutlinedPencilSquare)
							->color('gray')
							->authorize('resolve')
							->schema([static::resolutionField()])
							->fillForm(fn(ErrorLog $record): array => ['resolution' => $record->resolution])
							->action(fn(ErrorLog $record, array $data) => $record->update(['resolution' => $data['resolution'] ?: null]))
							->label(__('Изменить')),
					])
					->schema([
						TextEntry::make('resolution')
							->hiddenLabel()
							->suffixAction(CopyAction::make())
							->placeholder(__('Решение ещё не описано'))
							->extraAttributes(['style' => 'white-space: pre-wrap']),
					]),

				Section::make(__('Запрос'))
					->collapsible()
					->collapsed()
					->columns(3)
					->schema([
						TextEntry::make('is_scanner')
							->formatStateUsing(fn(bool $state): string => $state ? __('Да') : __('Нет'))
							->badge()
							->color(fn(bool $state): string => $state ? 'warning' : 'gray')
							->label(__('Сканер')),
						TextEntry::make('method')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Метод')),
						TextEntry::make('user.name')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Пользователь')),
						// поля проекта о пользователе (например, организация): SecurityPlugin::errorLogUserEntries()
						...SecurityPlugin::current()->getErrorLogUserEntries(),
						TextEntry::make('url')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->columnSpanFull()
							->label(__('Адрес')),
						TextEntry::make('referer')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->columnSpanFull()
							->label(__('Откуда (Referer)')),
						TextEntry::make('route_name')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Маршрут')),
						TextEntry::make('livewire_component')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->columnSpan(2)
							->label(__('Livewire-компонент')),
						TextEntry::make('livewire_path')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Страница компонента')),
						TextEntry::make('livewire_calls')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->fontFamily('mono')
							->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px'])
							->columnSpan(2)
							->label(__('Вызовы Livewire')),
						TextEntry::make('ip')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('IP-адрес')),
						TextEntry::make('user_agent')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->columnSpan(2)
							->label(__('User-Agent')),
						TextEntry::make('post_data')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->fontFamily('mono')
							->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px'])
							->columnSpanFull()
							->label(__('POST-данные')),
					]),

				Section::make(__('Исправление'))
					->collapsible()
					->collapsed()
					->columns(2)
					->schema([
						TextEntry::make('resolved_at')
							->suffixAction(CopyAction::make())
							->dateTime()
							->placeholder('—')
							->label(__('Дата исправления')),
						TextEntry::make('resolver.name')
							->suffixAction(CopyAction::make())
							->placeholder('—')
							->label(__('Исправил')),
					]),

				// последние записи той же ошибки: когда, у кого и на каком адресе повторялась
				Section::make(__('Повторы'))
					->collapsible()
					->collapsed()
					->visible(fn(ErrorLog $record): bool => $record->repeats()->exists())
					->schema([
						TextEntry::make('repeats_list')
							->hiddenLabel()
							->state(fn(ErrorLog $record): string => $record->repeats()
								->with('user')
								->latest('id')
								->limit(30)
								->get()
								->map(fn(ErrorLog $repeat): string => implode(' · ', [
									'#' . $repeat->id,
									$repeat->created_at?->format('d.m.Y H:i:s'),
									$repeat->isResolved() ? __('исправлено') : __('не исправлено'),
									$repeat->user?->name ?? __('гость'),
									$repeat->url ?? '—',
								]))
								->implode("\n"))
							->fontFamily('mono')
							->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px']),
					]),

				Section::make(__('Стек вызовов'))
					->collapsible()
					->collapsed()
					// кнопка в заголовке, чтобы копировать стек не разворачивая секцию
					->headerActions([
						CopyAction::make('copyTrace')
							->copyText(fn(ErrorLog $record): ?string => $record->trace),
					])
					->schema([
						TextEntry::make('trace')
							->hiddenLabel()
							->placeholder('—')
							->fontFamily('mono')
							->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px']),
					]),
			]);
	}

	/*
		кнопка «Копировать всё» в подвале окна просмотра рядом с «Закрыть»: код, сообщение, метод, адрес
		и стек одним текстом (например, чтобы переслать разработчику). Используется и в виджете на главной
	*/
	public static function copyReportAction(): CopyAction
	{
		return CopyAction::make('copyReport')
			->copyText(fn(ErrorLog $record): string => $record->toReport())
			->button()
			->color('info')
			->label(__('Копировать всё'));
	}

	/*
		поле «Решение» в окне отметки «Исправлено» (необязательное): причина ошибки и что сделано
	*/
	public static function resolutionField(): Textarea
	{
		return Textarea::make('resolution')
			->rows(4)
			->autosize()
			->placeholder(__('Причина ошибки и что сделано для исправления'))
			->label(__('Решение'));
	}

	/*
		форма отметки «Исправлено»: решение и переключатель «вместе с повторами» (включён по умолчанию —
		обычно исправление снимает всю группу одинаковых ошибок)
	*/
	public static function resolveFormSchema(): array
	{
		return [
			static::resolutionField(),
			Toggle::make('with_repeats')
				->default(true)
				->helperText(__('Все неисправленные записи этой же ошибки (тот же отпечаток)'))
				->label(__('Отметить и все повторы')),
		];
	}

	/*
		отметить запись(и) исправленными и сообщить, сколько записей отмечено с учётом повторов
	*/
	public static function resolveRecords(iterable $records, array $data): void
	{
		$count = 0;

		foreach ($records as $record) {
			$count += $record->markAsResolved($data['resolution'] ?? null, (bool) ($data['with_repeats'] ?? false));
		}

		Notification::make()
			->title(__('Отмечено исправленными: :count', ['count' => $count]))
			->success()
			->send();
	}

	public static function table(Table $table): Table
	{
		return $table
			->striped()
			->defaultSort('id', 'desc')
			->recordAction(ViewAction::class)
			->columns([
				TextColumn::make('id')
					->sortable()
					->searchable()
					->toggleable(isToggledHiddenByDefault: false)
					->label('ID'),

				TextColumn::make('code')
					->badge()
					->color(fn(?int $state): string => static::codeColor($state))
					->sortable()
					->searchable()
					->toggleable()
					->label(__('Код')),

				IconColumn::make('resolved_at')
					->state(fn(ErrorLog $record): bool => $record->isResolved())
					->boolean()
					->sortable()
					->toggleable()
					->label(__('Исправлено')),

				IconColumn::make('is_scanner')
					->boolean()
					->trueIcon(Heroicon::OutlinedBugAnt)
					->falseIcon(Heroicon::OutlinedMinus)
					->trueColor('warning')
					->falseColor('gray')
					->sortable()
					->toggleable()
					->label(__('Сканер')),

				// при включённом фильтре «Группировать повторы» строка — последняя запись группы
				TextColumn::make('repeats_count')
					->getStateUsing(fn(ErrorLog $record): ?int => ($record->repeats_count ?? 0) > 1 ? $record->repeats_count : null)
					->formatStateUsing(fn(int $state): string => '×' . $state)
					->badge()
					->color('warning')
					->placeholder('')
					->sortable(query: fn(Builder $query, string $direction): Builder => $query->orderBy('repeats_count', $direction))
					->toggleable()
					->label(__('Повторы')),

				TextColumn::make('created_at')
					->dateTime()
					->sortable()
					->wrap()
					->toggleable()
					->label(__('Дата')),

				TextColumn::make('message')
					->searchable()
					->limit(80)
					->wrap()
					->placeholder('—')
					->toggleable()
					->label(__('Сообщение')),

				TextColumn::make('url')
					->searchable()
					->limit(60)
					->placeholder('—')
					->toggleable()
					->label(__('Адрес')),

				TextColumn::make('app_file')
					->formatStateUsing(fn(ErrorLog $record): ?string => $record->appLocation())
					->searchable()
					->limit(60)
					->placeholder('—')
					->toggleable()
					->label(__('Место в коде')),

				TextColumn::make('livewire_component')
					->formatStateUsing(fn(?string $state): ?string => $state ? class_basename($state) : null)
					->tooltip(fn(ErrorLog $record): ?string => $record->livewire_component)
					->searchable()
					->placeholder('—')
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Livewire')),

				TextColumn::make('method')
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Метод')),

				TextColumn::make('exception')
					->searchable()
					->limit(50)
					->wrap()
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Исключение')),

				TextColumn::make('user.name')
					->placeholder('—')
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Пользователь')),

				// колонки проекта о пользователе: SecurityPlugin::errorLogUserColumns()
				...SecurityPlugin::current()->getErrorLogUserColumns(),

				TextColumn::make('ip')
					->searchable()
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('IP-адрес')),

				TextColumn::make('resolver.name')
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Исправил')),

				TextColumn::make('resolution')
					->searchable()
					->limit(80)
					->wrap()
					->placeholder('—')
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Решение')),

				TextColumn::make('resolved_at_date')
					->state(fn(ErrorLog $record) => $record->resolved_at)
					->dateTime()
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Дата исправления')),
			])
			->filters([
				TernaryFilter::make('resolved_at')
					->nullable()
					->default(false) // по умолчанию показываем только неисправленные
					->label(__('Исправлено')),

				TernaryFilter::make('is_scanner')
					->label(__('Сканер')),

				// одна строка на ошибку: старые повторы прячутся под последней записью группы
				Filter::make('latest_of_repeats')
					->toggle()
					->default()
					->query(fn(Builder $query): Builder => $query->latestOfRepeats())
					->label(__('Группировать повторы')),

				SelectFilter::make('code')
					->multiple()
					->options(fn(): array => static::getModel()::query()
						->distinct()
						->orderBy('code')
						->pluck('code', 'code')
						->all())
					->label(__('Код')),
			])
			->recordActions([
				ViewAction::make()
					->extraModalFooterActions([static::copyReportAction()]),
				Action::make('resolve')
					->icon(Heroicon::OutlinedCheckCircle)
					->color('success')
					->authorize('resolve')
					->visible(fn(ErrorLog $record): bool => !$record->isResolved())
					->schema(static::resolveFormSchema())
					->fillForm(fn(ErrorLog $record): array => ['resolution' => $record->resolution, 'with_repeats' => true])
					->modalSubmitActionLabel(__('Исправлено'))
					->action(fn(ErrorLog $record, array $data) => static::resolveRecords([$record], $data))
					->label(__('Исправлено')),
				Action::make('unresolve')
					->icon(Heroicon::OutlinedArrowUturnLeft)
					->color('gray')
					->authorize('resolve')
					->visible(fn(ErrorLog $record): bool => $record->isResolved())
					->action(fn(ErrorLog $record) => $record->markAsUnresolved())
					->label(__('Не исправлено')),
				DeleteAction::make(),
			])
			->toolbarActions([
				BulkActionGroup::make([
					BulkAction::make('resolve')
						->icon(Heroicon::OutlinedCheckCircle)
						->color('success')
						->authorizeIndividualRecords('resolve')
						->deselectRecordsAfterCompletion()
						->schema(static::resolveFormSchema())
						->modalSubmitActionLabel(__('Отметить исправленными'))
						->action(fn(Collection $records, array $data) => static::resolveRecords($records, $data))
						->label(__('Отметить исправленными')),
					BulkAction::make('unresolve')
						->icon(Heroicon::OutlinedArrowUturnLeft)
						->authorizeIndividualRecords('resolve')
						->deselectRecordsAfterCompletion()
						->action(fn(Collection $records) => $records->each->markAsUnresolved())
						->label(__('Снять отметку')),
					DeleteBulkAction::make(),
				]),
			]);
	}

	public static function getEloquentQuery(): Builder
	{
		return parent::getEloquentQuery()
			->withRepeatsCount()
			->with(SecurityPlugin::current()->getErrorLogRelations());
	}

	public static function getPages(): array
	{
		return [
			'index' => ManageErrorLogs::route('/'),
		];
	}
}
