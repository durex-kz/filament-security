<?php

namespace DurexKz\FilamentSecurity\Resources\BannedIps;

use DurexKz\BotGuard\BotGuard;
use DurexKz\BotGuard\Models\BannedIp;
use DurexKz\FilamentSecurity\Resources\BannedIps\Pages\ManageBannedIps;
use DurexKz\FilamentSecurity\SecurityPlugin;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Справочник заблокированных IP: хранится адрес, блокируется вся его подсеть (по умолчанию /24, IPv6 — /64).
 * Пополняется фильтром сканеров (BlockScanners) и вручную
 */
class BannedIpResource extends Resource
{
	protected static ?string $model = BannedIp::class;

	protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;


	protected static ?string $label = 'Заблокированный IP'; // заголовок формы

	protected static ?string $pluralLabel = 'Заблокированные IP'; // заголовок списка

	// модель из config bot-guard.model
	public static function getModel(): string
	{
		return BotGuard::model();
	}

	public static function getNavigationGroup(): string|UnitEnum|null
	{
		return SecurityPlugin::current()->getNavigationGroup();
	}

	// сразу после «Журнала ошибок»
	public static function getNavigationSort(): ?int
	{
		$sort = SecurityPlugin::current()->getNavigationSort();

		return $sort === null ? null : $sort + 1;
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

	public static function form(Schema $schema): Schema
	{
		return $schema
			->components([
				TextInput::make('ip')
					->required()
					->ip()
					->unique(ignoreRecord: true)
					->maxLength(45)
					->helperText(fn(): string => __('Блокируется вся подсеть адреса: /:v4 для IPv4, /:v6 для IPv6', ['v4' => config('bot-guard.ipv4_prefix', 24), 'v6' => config('bot-guard.ipv6_prefix', 64)]))
					->label(__('IP-адрес')),

				Textarea::make('reason')
					->required()
					->rows(3)
					->label(__('Причина блокировки')),

				DateTimePicker::make('banned_until')
					->seconds(false)
					->default(fn() => now()->addHours(BannedIp::autoBanHours()))
					->helperText(__('Пусто — бессрочно'))
					->label(__('Заблокирован до')),
			])->columns(1);
	}

	public static function table(Table $table): Table
	{
		return $table
			->emptyStateHeading(__('Нет записей'))
			->defaultSort('id', 'desc')
			->columns([
				TextColumn::make('ip')
					->searchable()
					->copyable()
					->sortable()
					->description(fn(BannedIp $record): ?string => static::getModel()::networkOf($record->ip))
					->label(__('IP-адрес')),

				IconColumn::make('active')
					->state(fn(BannedIp $record): bool => $record->isActive())
					->boolean()
					->label(__('Действует')),

				TextColumn::make('reason')
					->searchable()
					->wrap()
					->lineClamp(3)
					->label(__('Причина блокировки')),

				TextColumn::make('banned_until')
					->dateTime()
					->placeholder(__('Бессрочно'))
					->sortable()
					->label(__('Заблокирован до')),

				TextColumn::make('hits')
					->numeric()
					->sortable()
					->description(fn(BannedIp $record): ?string => $record->last_hit_at?->format('d.m.Y H:i'))
					->label(__('Отклонено запросов')),

				IconColumn::make('is_auto')
					->boolean()
					->trueIcon(Heroicon::OutlinedBugAnt)
					->falseIcon(Heroicon::OutlinedUser)
					->trueColor('warning')
					->falseColor('gray')
					->tooltip(fn(BannedIp $record): string => $record->is_auto ? __('Фильтр сканеров') : ($record->creator?->name ?? __('Вручную')))
					->toggleable()
					->label(__('Автоматически')),

				TextColumn::make('url')
					->wrap()
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('Запрос')),

				TextColumn::make('user_agent')
					->wrap()
					->toggleable(isToggledHiddenByDefault: true)
					->label(__('User-Agent')),

				TextColumn::make('created_at')
					->dateTime()
					->sortable()
					->label(__('Дата')),
			])
			->filters([
				TernaryFilter::make('active')
					->label(__('Действует'))
					->queries(
						true: fn(Builder $query): Builder => $query->active(),
						false: fn(Builder $query): Builder => $query->whereNotNull('banned_until')->where('banned_until', '<=', now()),
					),

				TernaryFilter::make('is_auto')
					->label(__('Автоматически')),
			])
			->recordActions([
				EditAction::make(),
				DeleteAction::make()->label(__('Разблокировать')),
			])
			->toolbarActions([
				BulkActionGroup::make([
					DeleteBulkAction::make(),
				]),
			]);
	}

	public static function getPages(): array
	{
		return [
			'index' => ManageBannedIps::route('/'),
		];
	}
}
