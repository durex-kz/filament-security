<?php

namespace DurexKz\FilamentSecurity\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Js;

/**
 * Кнопка «Копировать» рядом со значением поля инфолиста: TextEntry::make(...)->suffixAction(CopyAction::make()).
 * Копирует значение так, как оно показано (с учётом formatStateUsing / dateTime), без запроса к серверу.
 * Вне поля (например, в заголовке секции) текст задаётся явно: CopyAction::make()->copyText(fn($record) => ...)
 */
class CopyAction extends Action
{
	protected string|Closure|null $copyText = null;

	public static function getDefaultName(): ?string
	{
		return 'copy';
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this
			->label(__('Копировать'))
			->tooltip(__('Копировать'))
			->icon(Heroicon::OutlinedClipboardDocument)
			->color('gray')
			->iconButton()
			->visible(fn(): bool => filled($this->getCopyText()))
			->alpineClickHandler(fn(): string => '
				window.navigator.clipboard.writeText(' . Js::from($this->getCopyText()) . ')
				$tooltip(' . Js::from(__('Скопировано')) . ', { theme: $store.theme, timeout: 2000 })
			');
	}

	public function copyText(string|Closure|null $text): static
	{
		$this->copyText = $text;

		return $this;
	}

	public function getCopyText(): string
	{
		if ($this->copyText !== null) {
			return (string) $this->evaluate($this->copyText);
		}

		$component = $this->getSchemaComponent();

		if (!$component instanceof TextEntry) {
			return '';
		}

		$state = $component->getState();

		if (blank($state)) {
			return '';
		}

		$value = $component->formatState($state);

		// из HTML — обычный текст: без тегов и с раскодированными &gt; &amp; и т. п.
		return $value instanceof Htmlable ? html_entity_decode(strip_tags($value->toHtml()), ENT_QUOTES | ENT_HTML5) : (string) $value;
	}
}
