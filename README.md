# durex-kz/filament-security

Плагин Filament 5. Добавляет в панель «Журнал ошибок» ([`durex-kz/error-monitor`](https://github.com/durex-kz/error-monitor)), «Заблокированные IP» ([`durex-kz/bot-guard`](https://github.com/durex-kz/bot-guard)) и два виджета для главной страницы.

**Журнал ошибок:**
- по умолчанию показывает только неисправленные ошибки, по одной строке на группу повторов;
- фильтры по коду и по признаку сканера;
- отметка «Исправлено» с описанием решения, по одной записи, массово или сразу для всех повторов;
- карточка ошибки со стеком, местом в коде, контекстом Livewire и POST-данными, у каждого поля кнопка копирования, плюс кнопка «Копировать всё».

**Справочник IP:** ручная и автоматическая блокировка, срок, счётчик отклонённых запросов.

**Переводы:** русский (ключи), казахский (`kz`, `kk`), английский. Переводы из `lang/*.json` приложения имеют приоритет.

## Установка

```bash
composer require durex-kz/filament-security
```

Пока пакеты не опубликованы на Packagist, в `composer.json` проекта нужны все три репозитория:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/durex-kz/error-monitor" },
    { "type": "vcs", "url": "https://github.com/durex-kz/bot-guard" },
    { "type": "vcs", "url": "https://github.com/durex-kz/filament-security" }
]
```

Подключение плагина в панели:

```php
use DurexKz\FilamentSecurity\SecurityPlugin;

->plugins([
    SecurityPlugin::make()
        ->navigationGroup(NavigationGroup::System) // строка или enum
        ->navigationSort(110),                      // «Заблокированные IP» встают следующим пунктом
])
```

Настройка error-monitor и bot-guard описана в их README. Доступ ко всем разделам плагина даёт gate `security-admin`.

## Настройки плагина

| Метод | Назначение |
|---|---|
| `errorLogs(false)` / `bannedIps(false)` | отключить раздел |
| `widgets(false)` | не регистрировать виджеты |
| `widgetsLayout(errorLogs: 4, bannedIps: 2, sort: 1)` | ширина и порядок виджетов на главной |
| `pollingInterval('30s')` | частота обновления виджетов (`null` — без обновления) |
| `errorLogUserEntries(fn () => [...])` | дополнительные поля о пользователе в карточке ошибки |
| `errorLogUserColumns(fn () => [...])` | дополнительные колонки о пользователе в таблице |
| `errorLogRelations(['user.org', 'resolver'])` | жадная загрузка связей для этих полей |

Если у панели свой `Dashboard::getWidgets()`, виджеты нужно добавить туда вручную: `UnresolvedErrorLogsWidget::class` и `BannedIpsWidget::class` из `DurexKz\FilamentSecurity\Widgets`.

## Переход с кода в проекте

### QazPress

```php
SecurityPlugin::make()
    ->navigationGroup(NavigationGroup::System)
    ->navigationSort(110)
    ->widgetsLayout(errorLogs: ['md' => 2, 'xl' => 2], bannedIps: ['md' => 2, 'xl' => 2]),
```

В `App\Filament\Pages\Dashboard::getWidgets()` заменить `UnresolvedErrorLogs` и `ActiveBannedIps` на `UnresolvedErrorLogsWidget` и `BannedIpsWidget` из пакета.

### «Апельсин»: колонка «Организация»

```php
SecurityPlugin::make()
    ->navigationSort(810)
    ->pollingInterval('30s')
    ->widgetsLayout(errorLogs: 4, bannedIps: 2, sort: 1)
    ->errorLogRelations(['user.org', 'resolver'])
    ->errorLogUserEntries(fn () => [
        TextEntry::make('user.org.name_ru')
            ->formatStateUsing(fn (ErrorLog $record) => OrgLabel::for($record, withIdn: true))
            ->placeholder('—')
            ->columnSpan(2)
            ->label(__('Организация')),
    ])
    ->errorLogUserColumns(fn () => [
        TextColumn::make('user.org.name_ru')
            ->formatStateUsing(fn (ErrorLog $record) => OrgLabel::for($record))
            ->description(fn (ErrorLog $record) => $record->user?->org?->idn)
            ->searchable()->limit(50)->wrap()->placeholder('—')->toggleable()
            ->label(__('Организация')),
    ]),
```

Здесь `OrgLabel::for()` — бывший `ErrorLogResource::orgLabel()`, перенесённый в любой класс проекта.

### Что удалить в обоих проектах

`app/Filament/Resources/ErrorLogs/`, `app/Filament/Resources/BannedIps/`, `app/Filament/Widgets/UnresolvedErrorLogs.php`, а также `ActiveBannedIps.php` (QazPress) или `BannedIps.php` («Апельсин»). Свой `App\Filament\Actions\CopyAction` можно оставить для других ресурсов: в пакете есть своя копия.

## Тесты

```bash
composer test
```

## Лицензия

MIT
