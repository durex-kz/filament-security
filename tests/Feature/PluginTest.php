<?php

use DurexKz\BotGuard\Models\BannedIp;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use DurexKz\FilamentSecurity\Resources\BannedIps\BannedIpResource;
use DurexKz\FilamentSecurity\Resources\BannedIps\Pages\ManageBannedIps;
use DurexKz\FilamentSecurity\Resources\ErrorLogs\ErrorLogResource;
use DurexKz\FilamentSecurity\Resources\ErrorLogs\Pages\ManageErrorLogs;
use DurexKz\FilamentSecurity\Tests\Fixtures\User;
use DurexKz\FilamentSecurity\Widgets\BannedIpsWidget;
use DurexKz\FilamentSecurity\Widgets\UnresolvedErrorLogsWidget;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('registers resources and widgets with project navigation settings', function () {
	$panel = Filament::getPanel('admin');

	expect($panel->getResources())->toContain(ErrorLogResource::class, BannedIpResource::class)
		->and($panel->getWidgets())->toContain(UnresolvedErrorLogsWidget::class, BannedIpsWidget::class)
		->and(ErrorLogResource::getNavigationGroup())->toBe('Система')
		->and(ErrorLogResource::getNavigationSort())->toBe(810)
		->and(BannedIpResource::getNavigationSort())->toBe(811)
		->and(UnresolvedErrorLogsWidget::getSort())->toBe(1)
		->and(BannedIpsWidget::getSort())->toBe(2);
});

it('shows the error log only to admins', function () {
	app()->setLocale('ru');

	$this->actingAs(User::create(['name' => 'User']))
		->get(ErrorLogResource::getUrl())
		->assertForbidden();

	$this->actingAs($this->admin())
		->get(ErrorLogResource::getUrl())
		->assertOk()
		->assertSee('Журнал ошибок');
});

it('lists unresolved errors and resolves them with repeats', function () {
	$this->actingAs($admin = $this->admin());

	$first = ErrorLog::create(['code' => 500, 'message' => 'Boom', 'fingerprint' => sha1('a'), 'user_id' => $admin->id]);
	$second = ErrorLog::create(['code' => 500, 'message' => 'Boom', 'fingerprint' => sha1('a')]);
	$other = ErrorLog::create(['code' => 404, 'message' => 'Missing', 'fingerprint' => sha1('b')]);

	Livewire::test(ManageErrorLogs::class)
		->assertCanSeeTableRecords([$second, $other])
		->assertCanNotSeeTableRecords([$first]) // старый повтор спрятан под последней записью группы
		->assertTableColumnExists('user.email')  // колонка проекта из errorLogUserColumns()
		->callAction(TestAction::make('resolve')->table($second), ['resolution' => 'Исправлено в 1.2', 'with_repeats' => true]);

	expect($first->fresh()->isResolved())->toBeTrue()
		->and($first->fresh()->resolution)->toBe('Исправлено в 1.2')
		->and($first->fresh()->resolved_by)->toBe($admin->id)
		->and($other->fresh()->isResolved())->toBeFalse();
});

it('bans an IP manually from the panel', function () {
	$this->actingAs($admin = $this->admin());

	Livewire::test(ManageBannedIps::class)
		->callAction('create', ['ip' => '198.51.100.7', 'reason' => 'Перебор паролей', 'banned_until' => null])
		->assertHasNoActionErrors();

	$ban = BannedIp::sole();

	expect($ban->is_auto)->toBeFalse()
		->and($ban->created_by)->toBe($admin->id)
		->and(BannedIp::matchBan('198.51.100.200'))->toBe('198.51.100.7');
});

it('renders dashboard widgets for admins only', function () {
	ErrorLog::create(['code' => 500, 'message' => 'Boom', 'fingerprint' => sha1('a')]);
	BannedIp::create(['ip' => '203.0.113.5', 'reason' => 'Фильтр сканеров', 'is_auto' => true]);

	$this->actingAs($this->admin());

	Livewire::test(UnresolvedErrorLogsWidget::class)->assertSee('Boom');
	Livewire::test(BannedIpsWidget::class)->assertSee('203.0.113.5');

	expect(UnresolvedErrorLogsWidget::canView())->toBeTrue();

	$this->actingAs(User::create(['name' => 'User']));

	expect(UnresolvedErrorLogsWidget::canView())->toBeFalse()
		->and(BannedIpsWidget::canView())->toBeFalse();
});

it('ships Kazakh and English translations', function () {
	app()->setLocale('kz');
	expect(__('Журнал ошибок'))->toBe('Қателер журналы');

	app()->setLocale('en');
	expect(__('Заблокированные IP'))->toBe('Banned IPs');
});

it('opens the error card with project user entries and copy buttons', function () {
	config()->set('app.locale', 'ru');
	app()->setLocale('ru');
	$this->actingAs($admin = $this->admin());

	$log = ErrorLog::create(['code' => 500, 'message' => 'Boom', 'fingerprint' => sha1('a'), 'user_id' => $admin->id, 'trace' => '#0 /srv/app/Models/Offer.php(42): x']);

	Livewire::test(ManageErrorLogs::class)
		->mountAction(TestAction::make('view')->table($log))
		->assertMountedActionModalSee([
			'Email',                 // поле проекта из errorLogUserEntries()
			'admin@example.kz',
			'Стек вызовов',
			'Копировать всё',
		]);
});

it('lets every error log column be hidden and wraps long messages in the card', function () {
	config()->set('app.locale', 'ru');
	$this->actingAs($this->admin());

	$log = ErrorLog::create(['code' => 500, 'message' => 'SQLSTATE[42S22]:' . str_repeat('x', 300), 'fingerprint' => sha1('a')]);

	$table = Livewire::test(ManageErrorLogs::class)->instance()->getTable();

	foreach ($table->getColumns() as $column) {
		expect($column->isToggleable())->toBeTrue("колонку {$column->getName()} нельзя скрыть");
	}

	Livewire::test(ManageErrorLogs::class)
		->mountAction(TestAction::make('view')->table($log))
		->assertMountedActionModalSeeHtml('overflow-wrap: anywhere');
});

it('shows every stored field of the error in the card', function () {
	config()->set('app.locale', 'ru');
	$this->actingAs($admin = $this->admin());

	$values = [
		'user_id' => $admin->id,
		'code' => 500,
		'exception' => 'Illuminate\View\ViewException',
		'root_exception' => 'Illuminate\Database\QueryException',
		'message' => 'Column not found: offers.price_total',
		'file' => '/srv/site/vendor/laravel/framework/src/Foo.php',
		'line' => 1234,
		'trace' => '#0 /srv/site/app/Models/Offer.php(42): Foo->bar()',
		'method' => 'POST',
		'url' => 'https://orange.kz/admin/offers/7/edit',
		'post_data' => '{"price_total": "100"}',
		'ip' => '203.0.113.77',
		'user_agent' => 'Mozilla/5.0 TestBrowser/9.9',
		'fingerprint' => sha1('fingerprint-x'),
		'app_file' => 'app/Models/Offer.php',
		'app_line' => 42,
		'route_name' => 'filament.admin.resources.offers.edit',
		'livewire_component' => 'app.filament.resources.offers.pages.edit-offer',
		'livewire_path' => '/admin/offers/7/edit',
		'livewire_calls' => 'save(); set data.price_total',
		'referer' => 'https://orange.kz/admin/offers',
		'resolution' => 'Добавлена колонка price_total',
		'resolved_at' => now(),
		'resolved_by' => $admin->id,
	];

	$log = ErrorLog::create($values);
	ErrorLog::create(['code' => 500, 'fingerprint' => $values['fingerprint'], 'url' => 'https://orange.kz/repeat-url']);

	$expected = [
		'#' . $log->id, 'Исправлено', 'Повторов всего', 'Обновлено',
		$values['exception'], $values['root_exception'], $values['message'], $values['file'] . ':1234',
		'app/Models/Offer.php:42', $values['route_name'], $values['fingerprint'],
		$values['method'], $values['url'], $values['referer'], $values['ip'], $values['user_agent'], 'Admin',
		$values['livewire_component'], $values['livewire_path'], $values['livewire_calls'],
		'price_total', 'Foo-&gt;bar()', $values['resolution'], 'Дата исправления', 'Исправил',
		'repeat-url',
	];

	Livewire::test(ManageErrorLogs::class)
		->removeTableFilters() // по умолчанию в таблице только неисправленные
		->mountAction(TestAction::make('view')->table($log))
		->assertMountedActionModalSeeHtml(array_map(fn($v) => str_contains($v, '&gt;') ? $v : e($v), $expected));
});

it('labels guests and deleted users in the card', function () {
	app()->setLocale('ru');

	expect(ErrorLogResource::userLabel(null, 0, 'гость'))->toBe('гость')
		->and(ErrorLogResource::userLabel(null, 15, 'гость'))->toBe('#15 (удалён)');
});

it('copies multiline fields as plain text', function () {
	$trace = "#0 /srv/app/A.php(1): A->b('x & y')\n#1 {main}";
	$html = new \Illuminate\Support\HtmlString(nl2br(e($trace), false)); // как ErrorLogResource::multiline()

	// так CopyAction превращает показанное значение в текст для буфера обмена
	expect(html_entity_decode(strip_tags($html->toHtml()), ENT_QUOTES | ENT_HTML5))->toBe($trace);
});
