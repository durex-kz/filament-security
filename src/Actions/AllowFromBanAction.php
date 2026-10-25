<?php

namespace DurexKz\FilamentSecurity\Actions;

use DurexKz\BotGuard\BotGuard;
use DurexKz\BotGuard\Models\BannedIp;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * «В белый список» у заблокированного IP: выбор срока (1, 7, 30 дней, навсегда — config bot-guard.allow_durations)
 * и причины. Запись белого списка хранит ссылку на блокировку и её снимок, блокировка завершается
 */
class AllowFromBanAction extends Action
{
	public static function getDefaultName(): ?string
	{
		return 'allow';
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this
			->label(__('В белый список'))
			->icon(Heroicon::OutlinedShieldCheck)
			->color('success')
			->visible(fn(): bool => Gate::allows('create', BotGuard::allowedModel()))
			->modalHeading(fn(BannedIp $record): string => __('В белый список: :network', ['network' => BotGuard::model()::networkOf($record->ip)]))
			->modalDescription(__('Подсеть не будет блокироваться и проверяться фильтром сканеров до окончания срока. Блокировка завершится, ссылка на неё сохранится в записи белого списка.'))
			->modalSubmitActionLabel(__('Разрешить'))
			->schema([
				Select::make('duration')
					->options(fn(): array => BotGuard::allowedModel()::durationOptions())
					->default(fn(): ?string => array_key_exists('7', BotGuard::allowedModel()::durationOptions()) ? '7' : array_key_first(BotGuard::allowedModel()::durationOptions()))
					->required()
					->native(false)
					->label(__('Разрешён на срок')),

				Textarea::make('reason')
					->rows(2)
					->placeholder(__('Например: ложное срабатывание, офис за общим IP'))
					->label(__('Почему разрешён')),
			])
			->action(function (BannedIp $record, array $data): void {
				$allowed = BotGuard::allowedModel()::fromBan($record, $data['duration'], $data['reason'] ?? null);

				Notification::make()
					->success()
					->title(__('Подсеть :network в белом списке', ['network' => $allowed->network]))
					->body($allowed->allowed_until ? __('До :date', ['date' => $allowed->allowed_until->format('d.m.Y H:i')]) : __('Навсегда'))
					->send();
			});
	}
}
