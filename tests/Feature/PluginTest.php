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
