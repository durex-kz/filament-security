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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
use Illuminate\Support\HtmlString;
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

	/*
		карточка ошибки: сводка (код, статус, сообщение, место в коде) и решение всегда на виду,
		подробности — по вкладкам. Пустые поля не выводятся
	*/
	public static function infolist(Schema $schema): Schema
	{
		return $schema
			->columns(1)
			->components([
				static::summarySection(),
				static::resolutionSection(),

				Tabs::make('details')
					->contained(false)
					->tabs([
						static::codeTab(),
						static::requestTab(),
						static::livewireTab(),
						static::postDataTab(),
						static::traceTab(),
						static::repeatsTab(),
					]),
			]);
	}

	/*
		сводка: значки состояния, сообщение и место в коде
	*/
	protected static function summarySection(): Section
	{
		return Section::make()
			->compact()
			->columns(['default' => 2, 'md' => 4])
			->schema([
				TextEntry::make('code')
					->badge()
					->icon(Heroicon::OutlinedExclamationTriangle)
					->color(fn(?int $state): string => static::codeColor($state))
					->tooltip(fn(ErrorLog $record): string => ErrorLog::codeDescription((int) $record->code))
					->label(__('Код')),

				TextEntry::make('status')
					->state(fn(ErrorLog $record): string => $record->isResolved() ? __('Исправлено') : __('Не исправлено'))
					->badge()
					->icon(fn(ErrorLog $record): Heroicon => $record->isResolved() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
					->color(fn(ErrorLog $record): string => $record->isResolved() ? 'success' : 'danger')
					->label(__('Статус')),

				TextEntry::make('is_scanner')
					->formatStateUsing(fn(bool $state): string => $state ? __('Сканер') : __('Нет'))
					->badge()
					->icon(fn(bool $state): Heroicon => $state ? Heroicon::OutlinedBugAnt : Heroicon::OutlinedUser)
					->color(fn(bool $state): string => $state ? 'warning' : 'gray')
					->tooltip(__('Запрос сканера уязвимостей: записан сразу исправленным'))
					->label(__('Сканер')),

				TextEntry::make('repeats_total')
					->state(fn(ErrorLog $record): int => $record->repeats()->count() + 1)
					->formatStateUsing(fn(int $state): string => '×' . $state)
					->badge()
					->icon(Heroicon::OutlinedArrowPath)
					->color(fn(int $state): string => $state > 1 ? 'warning' : 'gray')
					->tooltip(fn(ErrorLog $record): string => __('Других неисправленных') . ': ' . $record->repeats()->unresolved()->count())
					->label(__('Повторов всего')),

				TextEntry::make('created_at')
					->dateTime()
					->sinceTooltip()
					->icon(Heroicon::OutlinedCalendar)
					->suffixAction(CopyAction::make())
					->columnSpan(['default' => 2, 'md' => 2])
					->label(__('Дата')),

				TextEntry::make('id')
					->formatStateUsing(fn(int $state): string => '#' . $state)
					->icon(Heroicon::OutlinedHashtag)
					->suffixAction(CopyAction::make())
					->label('ID'),

				TextEntry::make('updated_at')
					->dateTime()
					->sinceTooltip()
					->icon(Heroicon::OutlinedClock)
					->label(__('Обновлено')),

				TextEntry::make('message')
					->formatStateUsing(static::multiline())
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->weight(FontWeight::Bold)
					// длинные SQL, пути и адреса без пробелов переносятся, а не вылезают за край окна
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->columnSpanFull()
					->label(__('Сообщение')),

				// первое место в app/, resources/, routes/, database/ — у ошибок в шаблонах и SQL «Файл» указывает во vendor
				TextEntry::make('app_file')
					->formatStateUsing(fn(ErrorLog $record): ?string => $record->appLocation())
					->icon(Heroicon::OutlinedCodeBracket)
					->iconColor('primary')
					->fontFamily('mono')
					->weight(FontWeight::Bold)
					->suffixAction(CopyAction::make())
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->visible(fn(ErrorLog $record): bool => filled($record->app_file))
					->columnSpanFull()
					->label(__('Место в коде')),
			]);
	}

	/*
		решение: причина и что сделано, кто и когда исправил; кнопка в заголовке — дописать/исправить текст
	*/
	protected static function resolutionSection(): Section
	{
		return Section::make(__('Решение'))
			->icon(Heroicon::OutlinedWrench)
			->iconColor(fn(ErrorLog $record): string => $record->isResolved() ? 'success' : 'gray')
			->compact()
			->collapsible()
			->columns(['default' => 1, 'md' => 2])
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
					->formatStateUsing(static::multiline())
					->hiddenLabel()
					->suffixAction(CopyAction::make())
					->placeholder(__('Решение ещё не описано'))
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->columnSpanFull(),
				TextEntry::make('resolved_at')
					->dateTime()
					->sinceTooltip()
					->icon(Heroicon::OutlinedCheckCircle)
					->iconColor('success')
					->visible(fn(ErrorLog $record): bool => $record->isResolved())
					->label(__('Дата исправления')),
				TextEntry::make('resolver_name')
					->state(fn(ErrorLog $record): string => static::userLabel($record->resolver, (int) $record->resolved_by, __('система')))
					->icon(Heroicon::OutlinedUser)
					->visible(fn(ErrorLog $record): bool => $record->isResolved())
					->label(__('Исправил')),
			]);
	}

	protected static function codeTab(): Tab
	{
		return Tab::make(__('Код'))
			->icon(Heroicon::OutlinedCodeBracket)
			->columns(['default' => 1, 'md' => 2])
			->schema([
				TextEntry::make('exception')
					->icon(Heroicon::OutlinedExclamationTriangle)
					->iconColor('danger')
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->label(__('Исключение')),
				TextEntry::make('root_exception')
					->icon(Heroicon::OutlinedArrowsRightLeft)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->visible(fn(ErrorLog $record): bool => filled($record->root_exception))
					->label(__('Исходное исключение')),
				TextEntry::make('file')
					->formatStateUsing(fn(ErrorLog $record): string => $record->file . ($record->line ? ':' . $record->line : ''))
					->icon(Heroicon::OutlinedDocumentText)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->columnSpanFull()
					->label(__('Файл')),
				TextEntry::make('route_name')
					->icon(Heroicon::OutlinedSignal)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->visible(fn(ErrorLog $record): bool => filled($record->route_name))
					->label(__('Маршрут')),
				TextEntry::make('fingerprint')
					->icon(Heroicon::OutlinedFingerPrint)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->helperText(__('Одинаковый отпечаток — одна и та же ошибка: по нему группируются повторы'))
					->label(__('Отпечаток')),
			]);
	}

	protected static function requestTab(): Tab
	{
		return Tab::make(__('Запрос'))
			->icon(Heroicon::OutlinedGlobeAlt)
			->columns(['default' => 1, 'md' => 3])
			->schema([
				TextEntry::make('method')
					->badge()
					->color('gray')
					->placeholder('—')
					->label(__('Метод')),
				TextEntry::make('url')
					->icon(Heroicon::OutlinedLink)
					->suffixAction(CopyAction::make())
					->placeholder(__('вне HTTP-запроса (очередь, artisan, планировщик)'))
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->columnSpan(['default' => 1, 'md' => 2])
					->label(__('Адрес')),
				TextEntry::make('referer')
					->icon(Heroicon::OutlinedArrowTopRightOnSquare)
					->suffixAction(CopyAction::make())
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->visible(fn(ErrorLog $record): bool => filled($record->referer))
					->columnSpanFull()
					->label(__('Откуда (Referer)')),
				TextEntry::make('user_label')
					->state(fn(ErrorLog $record): string => static::userLabel($record->user, (int) $record->user_id, __('гость')))
					->icon(Heroicon::OutlinedUser)
					->suffixAction(CopyAction::make())
					->label(__('Пользователь')),
				// поля проекта о пользователе (например, организация): SecurityPlugin::errorLogUserEntries()
				...SecurityPlugin::current()->getErrorLogUserEntries(),
				TextEntry::make('ip')
					->icon(Heroicon::OutlinedMapPin)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->label(__('IP-адрес')),
				TextEntry::make('user_agent')
					->icon(Heroicon::OutlinedComputerDesktop)
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->columnSpanFull()
					->label(__('User-Agent')),
			]);
	}

	protected static function livewireTab(): Tab
	{
		return Tab::make('Livewire')
			->icon(Heroicon::OutlinedBolt)
			->visible(fn(?ErrorLog $record): bool => filled($record?->livewire_component) || filled($record?->livewire_calls))
			->columns(['default' => 1, 'md' => 2])
			->schema([
				TextEntry::make('livewire_component')
					->icon(Heroicon::OutlinedSquare3Stack3d)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->label(__('Livewire-компонент')),
				TextEntry::make('livewire_path')
					->icon(Heroicon::OutlinedLink)
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere'])
					->label(__('Страница компонента')),
				TextEntry::make('livewire_calls')
					->formatStateUsing(static::multiline())
					->icon(Heroicon::OutlinedCursorArrowRays)
					->fontFamily('mono')
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->extraAttributes(['style' => 'overflow-wrap: anywhere; font-size: 12px'])
					->columnSpanFull()
					->label(__('Вызовы Livewire')),
			]);
	}

	protected static function postDataTab(): Tab
	{
		return Tab::make(__('POST-данные'))
			->icon(Heroicon::OutlinedDocumentText)
			->visible(fn(?ErrorLog $record): bool => filled($record?->post_data))
			->schema([
				TextEntry::make('post_data')
					->formatStateUsing(static::multiline())
					->hiddenLabel()
					->suffixAction(CopyAction::make())
					->fontFamily('mono')
					->extraAttributes(['style' => 'overflow-wrap: anywhere; font-size: 12px']),
			]);
	}

	protected static function traceTab(): Tab
	{
		return Tab::make(__('Стек вызовов'))
			->icon(Heroicon::OutlinedQueueList)
			->schema([
				TextEntry::make('trace')
					->formatStateUsing(static::multiline())
					->hiddenLabel()
					->suffixAction(CopyAction::make())
					->placeholder('—')
					->fontFamily('mono')
					->extraAttributes(['style' => 'overflow-wrap: anywhere; font-size: 12px']),
			]);
	}

	/*
		последние записи той же ошибки: когда, у кого и на каком адресе повторялась
	*/
	protected static function repeatsTab(): Tab
	{
		return Tab::make(__('Повторы'))
			->icon(Heroicon::OutlinedArrowPath)
			->badge(fn(?ErrorLog $record): ?int => $record ? ($record->repeats()->count() ?: null) : null)
			->visible(fn(?ErrorLog $record): bool => (bool) $record?->repeats()->exists())
			->schema([
				TextEntry::make('repeats_list')
					->formatStateUsing(static::multiline())
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
							static::userLabel($repeat->user, (int) $repeat->user_id, __('гость')),
							$repeat->url ?? '—',
						]))
						->implode("\n"))
					->fontFamily('mono')
					->extraAttributes(['style' => 'overflow-wrap: anywhere; font-size: 12px']),
			]);
	}

	/*
		многострочный текст (стек, POST-данные, решение): строки — через <br>, HTML экранируется.
		white-space: pre-wrap не подходит — он сохраняет отступы шаблона Filament и даёт пустые области
	*/
	protected static function multiline(): \Closure
	{
		return fn(?string $state): ?HtmlString => filled($state) ? new HtmlString(nl2br(e($state), false)) : null;
	}

	/*
		пользователь записи: имя; удалённый — по id; 0 — гость (или система для «Исправил»)
	*/
	public static function userLabel($user, int $id, string $nobody): string
	{
		return match (true) {
			$user !== null => (string) ($user->name ?? ('#' . $id)),
			$id > 0 => '#' . $id . ' (' . __('удалён') . ')',
			default => $nobody,
		};
	}

	/*
		заголовок окна просмотра: «Ошибка #3 · 500»
	*/
	public static function modalHeading(ErrorLog $record): string
	{
		return __('Ошибка') . ' #' . $record->getKey() . ' · ' . $record->code;
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
					->modalHeading(fn(ErrorLog $record): string => static::modalHeading($record))
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
