<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config\ResolvedRule;
use DressCode\ConfigurableRule;
use DressCode\RuleInfo;
use Nette\CommandLine\Console;
use Nette\Schema\Elements\AnyOf;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Elements\Type;
use Nette\Schema\Processor;
use function count, is_bool, is_string, strlen, strval;


/**
 * Explains one rule: what it is for, the options it has under this configuration, and the examples someone
 * chose for it. The examples are fixtures of the rule, so the test suite runs them against the rule itself
 * and an example that stopped being true cannot survive.
 * @internal
 */
final class ExplainPrinter
{
	public function __construct(
		private readonly ResolvedRule $rule,
		/** the directory the fixtures of the rules live in */
		private readonly string $fixtures,
	) {
	}


	/**
	 * The examples of a rule: `<slug>/showcase*.code` with the `.expected` beside it when it fixes, and the
	 * options its header sets, which is the configuration the example is true under.
	 * @return list<array{string, ?string, string}>
	 */
	public function findExamples(): array
	{
		$slug = substr($this->rule->name, strpos($this->rule->name, '/') + 1);
		$dir = "$this->fixtures/$slug";
		$examples = [];
		foreach (glob("$dir/showcase*.code") ?: [] as $file) {
			$code = (string) file_get_contents($file);
			$expected = (string) preg_replace('~\.code$~', '.expected', $file);
			$examples[] = [
				$code,
				is_file($expected) ? (string) file_get_contents($expected) : null,
				preg_match('~^//\s*(\{.*\})~m', $code, $m) ? (string) $m[1] : '',
			];
		}

		return $examples;
	}


	public function print(Console $console): string
	{
		$info = RuleInfo::of($this->rule->class);
		$out = $console->color('white', $this->rule->name) . "\n";
		$out .= ($info->description === '' ? '' : "$info->description.\n");
		$facts = ['stage ' . $info->stage->name];
		if ($info->minPhpVersion !== null) {
			$facts[] = "needs PHP $info->minPhpVersion";
		}

		if ($info->modifiesComments) {
			$facts[] = 'modifies comments';
		}

		$out .= $console->color('gray', implode(', ', $facts)) . "\n";
		$out .= "\n" . ($this->rule->isActive()
			? $console->color('gray', 'It runs in this project') . ($this->rule->getSource() === null ? '' : ', set by ' . $this->rule->getSource())
			: $console->color('gray', 'It does not run in this project: ') . $this->rule->inactive) . ".\n";

		$out .= $this->printOptions($console);
		$examples = $this->findExamples();
		foreach ($examples as $i => [$before, $after, $options]) {
			$out .= "\n" . $console->color('white', 'Example' . (count($examples) > 1 ? ' ' . ($i + 1) : ''))
				. ($options === '' ? '' : $console->color('gray', "  with $options")) . "\n";
			$out .= self::indent(self::strip($before));
			if ($after !== null && $after !== $before) {
				$out .= $console->color('gray', "  becomes\n") . self::indent(self::strip($after));
			}
		}

		return $out;
	}


	private function printOptions(Console $console): string
	{
		if (!is_subclass_of($this->rule->class, ConfigurableRule::class)) {
			return '';
		}

		$schema = $this->rule->class::getOptionsSchema();
		if (!$schema instanceof Structure) {
			return '';
		}

		$defaults = (array) (new Processor)->process($schema, []);
		$origins = $this->rule->getOrigins();
		$out = "\n" . $console->color('white', "Options\n");
		// the widest name decides the column, so a long one does not run into its value
		$width = max(26, ...array_map(strlen(...), array_map(strval(...), array_keys($schema->getShape()))));
		foreach ($schema->getShape() as $option => $element) {
			$value = $this->rule->options[$option] ?? $defaults[$option] ?? null;
			$layers = $origins[(string) $option] ?? [];
			$out .= '  ' . str_pad((string) $option, $width + 1) . str_pad(self::format($value), 24)
				. $console->color('gray', $layers === [] ? '(default)' : $layers[count($layers) - 1][0]) . "\n";
			$description = $element instanceof Type || $element instanceof AnyOf || $element instanceof Structure
				? $element->describe()['description'] ?? null
				: null;
			if (is_string($description) && $description !== '') {
				$out .= $console->color('gray', "      $description\n");
			}
		}

		return $out;
	}


	/** The header of a fixture is its options, which the example shows in the caption, not in the code. */
	private static function strip(string $code): string
	{
		return (string) preg_replace('~^(<\?php\r?\n)(?://[^\r\n]*\r?\n)+~', '$1', $code);
	}


	private static function indent(string $code): string
	{
		return '  ' . rtrim(str_replace("\n", "\n  ", rtrim($code, "\n"))) . "\n";
	}


	private static function format(mixed $value): string
	{
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			$value === null => 'null',
			is_string($value) => $value,
			default => (string) json_encode($value),
		};
	}
}
