<?php

namespace DurexKz\FilamentSecurity\Resources\AllowedIps;

use BackedEnum;
use DurexKz\BotGuard\BotGuard;
use DurexKz\BotGuard\Models\AllowedIp;
use DurexKz\FilamentSecurity\Resources\AllowedIps\Pages\ManageAllowedIps;
use DurexKz\FilamentSecurity\Resources\BannedIps\BannedIpResource;
use DurexKz\FilamentSecurity\SecurityPlugin;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Белый список: подсети, которые фильтр сканеров не трогает до указанного срока. Пополняется действием
 * «В белый список» у заблокированного IP (со ссылкой на блокировку и её снимком) или вручную
 */
class AllowedIpResource extends Resource
{
	protected static ?string $model = AllowedIp::class;

	protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

	protected static ?string $label = 'Разрешённый IP'; // заголовок формы

	protected static ?string $pluralLabel = 'Белый список IP'; // заголовок списка

	// модель из config bot-guard.allowed_model
	public static function getModel(): string
	{
		return BotGuard::allowedModel();
	}

	public static function getNavigationGroup(): string|UnitEnum|null
	{
		return SecurityPlugin::current()->getNavigationGroup();
	}

	// после «Заблокированных IP»
	public static function getNavigationSort(): ?int
	{
		$sort = SecurityPlugin::current()->getNavigationSort();

		return $sort === null ? null : $sort + 2;
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

	/**
	 * Добавление вручную: IP, срок вариантом (1, 7, 30 дней, навсегда), причина
	 */
	public static function createFormComponents(): array
	{
		return [
			TextInput::make('ip')
				->required()
				->ip()
				->maxLength(45)
				->helperText(fn(): string => __('Разрешается вся подсеть адреса: /:v4 для IPv4, /:v6 для IPv6', ['v4' => config('bot-guard.ipv4_prefix', 24), 'v6' => config('bot-guard.ipv6_prefix', 64)]))
				->label(__('IP-адрес')),

			Select::make('duration')
				->options(fn(): array => BotGuard::allowedModel()::durationOptions())
				->default('7')
				->required()
				->native(false)
				->label(__('Разрешён на срок')),

			Textarea::make('reason')
				->rows(2)
				->label(__('Почему разрешён')),
		];
	}

	// правка: точная дата окончания (пусто — навсегда) и причина; IP и ссылка на блокировку не меняются
	public static function form(Schema $schema): Schema
	{
		return $schema
			->components([
				DateTimePicker::make('allowed_until')
					->seconds(false)
					->helperText(__('Пусто — навсегда'))
					->label(__('Разрешён до')),

				Textarea::make('reason')
					->rows(2)
					->label(__('Почему разрешён')),
			])->columns(1);
	}

	public static function infolist(Schema $schema): Schema
	{
		return $schema
			->columns(1)
			->components([
				Section::make(__('Разрешение'))
					->columns(2)
					->schema([
						TextEntry::make('ip')->copyable()->label(__('IP-адрес')),
						TextEntry::make('network')->copyable()->label(__('Подсеть')),
						TextEntry::make('allowed_until')->dateTime()->placeholder(__('Навсегда'))->label(__('Разрешён до')),
						TextEntry::make('creator.name')->placeholder(__('Система'))->label(__('Кто добавил')),
						TextEntry::make('reason')->placeholder('—')->columnSpanFull()->label(__('Почему разрешён')),
					]),

				// снимок блокировки сохраняется при переносе и остаётся, даже если блокировку потом удалили
				Section::make(__('Блокировка, из которой перенесён'))
					->visible(fn(AllowedIp $record): bool => $record->banned_ip_id !== null || filled($record->banned_snapshot))
					->columns(2)
					->schema([
						TextEntry::make('banned_ip_id')
							->formatStateUsing(fn($state, AllowedIp $record): string => '№' . $state . ($record->bannedIp ? '' : ' (' . __('удалена') . ')'))
							->url(fn(AllowedIp $record): ?string => $record->bannedIp ? BannedIpResource::getUrl('index', ['search' => $record->banned_snapshot['ip'] ?? $record->ip]) : null)
							->label(__('Запись')),
						TextEntry::make('banned_snapshot.banned_at')->dateTime()->placeholder('—')->label(__('Заблокирован')),
						TextEntry::make('banned_snapshot.banned_until')->dateTime()->placeholder(__('Бессрочно'))->label(__('Был заблокирован до')),
						TextEntry::make('banned_snapshot.ban_count')->placeholder('—')->label(__('Номер блокировки подсети')),
						TextEntry::make('banned_snapshot.hits')->placeholder('—')->label(__('Отклонено запросов')),
						TextEntry::make('banned_snapshot.is_auto')
							->formatStateUsing(fn($state): string => $state ? __('Фильтр сканеров') : __('Вручную'))
							->label(__('Кто заблокировал')),
						TextEntry::make('banned_snapshot.reason')->placeholder('—')->columnSpanFull()->label(__('Причина блокировки')),
					]),
			]);
	}

	public static function table(Table $table): Table
	{
		return $table
			->emptyStateHeading(__('Нет записей'))
			->defaultSort('id', 'desc')
			->recordAction(ViewAction::class)
			->columns([
				TextColumn::make('ip')
					->searchable()
					->copyable()
					->sortable()
					->description(fn(AllowedIp $record): ?string => $record->network)
					->label(__('IP-адрес')),

				IconColumn::make('active')
					->state(fn(AllowedIp $record): bool => $record->isActive())
					->boolean()
					->label(__('Действует')),

				TextColumn::make('allowed_until')
					->dateTime()
					->placeholder(__('Навсегда'))
					->sortable()
					->label(__('Разрешён до')),

				TextColumn::make('reason')
					->searchable()
					->wrap()
					->lineClamp(2)
					->placeholder('—')
					->label(__('Почему разрешён')),

				TextColumn::make('banned_ip_id')
					->formatStateUsing(fn($state): string => '№' . $state)
					->description(fn(AllowedIp $record): ?string => isset($record->banned_snapshot['reason']) ? \Illuminate\Support\Str::limit($record->banned_snapshot['reason'], 80) : null)
					->placeholder(__('Вручную'))
					->wrap()
					->label(__('Из блокировки')),

				TextColumn::make('creator.name')
					->placeholder(__('Система'))
					->toggleable()
					->label(__('Кто добавил')),

				TextColumn::make('created_at')
					->dateTime()
					->sortable()
					->toggleable()
					->label(__('Дата')),
			])
			->filters([
				TernaryFilter::make('active')
					->label(__('Действует'))
					->queries(
						true: fn(Builder $query): Builder => $query->active(),
						false: fn(Builder $query): Builder => $query->whereNotNull('allowed_until')->where('allowed_until', '<=', now()),
					),
			])
			->recordActions([
				ViewAction::make(),
				EditAction::make(),
				DeleteAction::make()->label(__('Убрать из белого списка')),
			])
			->toolbarActions([
				BulkActionGroup::make([
					DeleteBulkAction::make(),
				]),
			]);
	}

	public static function getEloquentQuery(): Builder
	{
		return parent::getEloquentQuery()->with(['creator', 'bannedIp']);
	}

	public static function getPages(): array
	{
		return [
			'index' => ManageAllowedIps::route('/'),
		];
	}
}
