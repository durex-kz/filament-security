<?php

namespace DurexKz\FilamentSecurity;

use Closure;
use DurexKz\FilamentSecurity\Resources\BannedIps\BannedIpResource;
use DurexKz\FilamentSecurity\Resources\ErrorLogs\ErrorLogResource;
use DurexKz\FilamentSecurity\Widgets\BannedIpsWidget;
use DurexKz\FilamentSecurity\Widgets\UnresolvedErrorLogsWidget;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Throwable;
use UnitEnum;

/**
 * Журнал ошибок и справочник заблокированных IP в панели Filament:
 *
 *   ->plugins([
 *       SecurityPlugin::make()
 *           ->navigationGroup(NavigationGroup::System)
 *           ->navigationSort(110),
 *   ])
 */
class SecurityPlugin implements Plugin
{
	use EvaluatesClosures;

	protected bool $hasErrorLogs = true;

	protected bool $hasBannedIps = true;

	protected bool $hasWidgets = true;

	protected string|UnitEnum|Closure|null $navigationGroup = null;

	protected ?int $navigationSort = null;

	protected ?string $pollingInterval = '10s';

	protected int|string|array $errorLogsWidgetColumnSpan = 'full';

	protected int|string|array $bannedIpsWidgetColumnSpan = 'full';

	protected ?int $widgetSort = null;

	protected ?Closure $errorLogUserEntries = null;

	protected ?Closure $errorLogUserColumns = null;

	protected array $errorLogRelations = ['user', 'resolver'];

	public static function make(): static
	{
		return app(static::class);
	}

	public function getId(): string
	{
		return 'durex-security';
	}

	/**
	 * Плагин текущей панели; вне панели (или если плагин в ней не подключён) — настройки по умолчанию
	 */
	public static function current(): static
	{
		try {
			$panel = Filament::getCurrentOrDefaultPanel();

			if ($panel?->hasPlugin('durex-security')) {
				return $panel->getPlugin('durex-security');
			}
		} catch (Throwable) {
		}

		return app(static::class);
	}

	public function register(Panel $panel): void
	{
		$panel->resources(array_values(array_filter([
			$this->hasErrorLogs ? ErrorLogResource::class : null,
			$this->hasBannedIps ? BannedIpResource::class : null,
		])));

		if ($this->hasWidgets) {
			$panel->widgets(array_values(array_filter([
				$this->hasErrorLogs ? UnresolvedErrorLogsWidget::class : null,
				$this->hasBannedIps ? BannedIpsWidget::class : null,
			])));
		}
	}

	public function boot(Panel $panel): void
	{
	}

	public function errorLogs(bool $condition = true): static
	{
		$this->hasErrorLogs = $condition;

		return $this;
	}

	public function bannedIps(bool $condition = true): static
	{
		$this->hasBannedIps = $condition;

		return $this;
	}

	/**
	 * Виджеты «Нерешённые ошибки» и «Заблокированные IP» на главной панели.
	 * Если у панели свой Dashboard::getWidgets(), классы виджетов нужно добавить туда самому
	 */
	public function widgets(bool $condition = true): static
	{
		$this->hasWidgets = $condition;

		return $this;
	}

	public function navigationGroup(string|UnitEnum|Closure|null $group): static
	{
		$this->navigationGroup = $group;

		return $this;
	}

	public function getNavigationGroup(): string|UnitEnum|null
	{
		return $this->evaluate($this->navigationGroup);
	}

	/**
	 * Позиция «Журнала ошибок» в меню; «Заблокированные IP» — следующим пунктом
	 */
	public function navigationSort(?int $sort): static
	{
		$this->navigationSort = $sort;

		return $this;
	}

	public function getNavigationSort(): ?int
	{
		return $this->navigationSort;
	}

	/**
	 * Как часто виджеты обновляются без перезагрузки страницы; null — не обновлять
	 */
	public function pollingInterval(?string $interval): static
	{
		$this->pollingInterval = $interval;

		return $this;
	}

	public function getPollingInterval(): ?string
	{
		return $this->pollingInterval;
	}

	/**
	 * Ширина и порядок виджетов: ->widgetsLayout(errorLogs: 4, bannedIps: 2, sort: 1)
	 */
	public function widgetsLayout(int|string|array $errorLogs = 'full', int|string|array $bannedIps = 'full', ?int $sort = null): static
	{
		$this->errorLogsWidgetColumnSpan = $errorLogs;
		$this->bannedIpsWidgetColumnSpan = $bannedIps;
		$this->widgetSort = $sort;

		return $this;
	}

	public function getErrorLogsWidgetColumnSpan(): int|string|array
	{
		return $this->errorLogsWidgetColumnSpan;
	}

	public function getBannedIpsWidgetColumnSpan(): int|string|array
	{
		return $this->bannedIpsWidgetColumnSpan;
	}

	public function getWidgetSort(): ?int
	{
		return $this->widgetSort;
	}

	/**
	 * Дополнительные поля о пользователе в карточке ошибки (после «Пользователь»), например организация:
	 * ->errorLogUserEntries(fn () => [TextEntry::make('user.org.name_ru')->label(__('Организация'))])
	 */
	public function errorLogUserEntries(?Closure $entries): static
	{
		$this->errorLogUserEntries = $entries;

		return $this;
	}

	public function getErrorLogUserEntries(): array
	{
		return $this->errorLogUserEntries ? (array) $this->evaluate($this->errorLogUserEntries) : [];
	}

	/**
	 * Дополнительные колонки о пользователе в таблице журнала (после «Пользователь»)
	 */
	public function errorLogUserColumns(?Closure $columns): static
	{
		$this->errorLogUserColumns = $columns;

		return $this;
	}

	public function getErrorLogUserColumns(): array
	{
		return $this->errorLogUserColumns ? (array) $this->evaluate($this->errorLogUserColumns) : [];
	}

	/**
	 * Связи, которые загружаются вместе с записями журнала: ['user.org', 'resolver']
	 */
	public function errorLogRelations(array $relations): static
	{
		$this->errorLogRelations = $relations;

		return $this;
	}

	public function getErrorLogRelations(): array
	{
		return $this->errorLogRelations;
	}
}
