<?php declare(strict_types=1);

/**
 * A line too wide is spread from the outside in under a standard: the outer construct first, an inner one only when
 * its line is still too wide, and the whole cascade converges within the passes of a file.
 */

use DressCode\Config;
use DressCode\Config\{RuleBuilder, RunnerFactory};
use DressCode\Engine\{PhpFileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param array<string, mixed> $decisions */
function longLinesProcessor(array $decisions = []): PhpFileProcessor
{
	$factory = new RunnerFactory;
	$resolved = $factory->resolve(new Config(use: ['nette'], decisions: $decisions), __DIR__)->resolveFor([]);
	$style = $resolved->createStyle();
	return new PhpFileProcessor(
		RuleBuilder::buildRules($resolved),
		$resolved->createAnalyses($style),
		$resolved->phpVersion,
		$style,
		policy: new ReportPolicy($factory->registry->expandSuppressedName(...), strict: true),
		gates: $resolved->getGates(),
	);
}


/** A call of the depth on one line, each level holding two long variables and the level below. */
function longLinesNested(int $depth): string
{
	$call = '$innermostValue';
	for ($i = $depth; $i > 0; $i--) {
		$call = "level$i(\$firstArgumentOfLevel$i, \$secondArgumentOfLevel$i, $call)";
	}

	return "<?php\n\n\$result = $call;\n";
}


test('nested calls are spread from the outside in, and converge whatever the depth', function () {
	$processor = longLinesProcessor();
	$passes = [];
	foreach ([3, 5, 10, 20] as $depth) {
		$result = $processor->process('nested.php', longLinesNested($depth));
		Assert::null($result->failure, "depth $depth");
		$passes[$depth] = $result->passes;
		foreach (explode("\n", (string) $result->output) as $line) {
			Assert::true(strlen(str_replace("\t", '    ', $line)) <= 140, "depth $depth: $line");
		}

		Assert::same($result->output, $processor->process('nested.php', (string) $result->output)->output, "depth $depth is not stable");
	}

	Assert::same([3 => 4, 5 => 4, 10 => 4, 20 => 4], $passes);
});


test('a family of operators leaves its first or last operand to spread where a line of its own would leave it too wide', function () {
	$code = <<<'XX'
		<?php

		$first = in_array($name, ['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'], true) || str_starts_with($name, 'report-');
		$last = $enabled || in_array($name, ['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'], true);
		$ternary = $name === ''
			? null
			: in_array($name, ['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'], true) || str_starts_with($name, 'report-');
		$fits = $this->repository->findByIdentifierAndOptions($identifierOfTheEntity, $optionsOfTheQuery) || $this->repository->findByIdentifierAndOptions($options);
		$cast = (bool) in_array($name, ['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'], true) || $enabled;
		call(
			$prefix . format('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'ccccccccccccccccc'),
			$other,
		);

		// what follows the family on the line is no matter of its operands, the condition leaving it a line of its own
		if ((in_array(TypeIdentifier::TRUE, $identifiersOfTheBuiltinTypes, true) || in_array(TypeIdentifier::FALSE, $identifiersOfTheBuiltinTypes, true)) && in_array(TypeIdentifier::BOOL, $identifiersOfTheBuiltinTypes, true)) {
			return;
		}

		if (in_array($name, ['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'], true) || $enabled) {
			return;
		}

		if ($enabled || ($name !== '' && in_array($name, ['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'], true))) {
			return;
		}

		XX;
	$expected = <<<'XX'
		<?php

		$first = in_array(
			$name,
			['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'],
			true,
		) || str_starts_with($name, 'report-');
		$last = $enabled || in_array(
			$name,
			['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'],
			true,
		);
		$ternary = $name === ''
			? null
			: in_array(
				$name,
				['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'],
				true,
			) || str_starts_with($name, 'report-');
		$fits = $this->repository->findByIdentifierAndOptions($identifierOfTheEntity, $optionsOfTheQuery)
			|| $this->repository->findByIdentifierAndOptions($options);
		$cast = (bool) in_array(
			$name,
			['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'],
			true,
		) || $enabled;
		call(
			$prefix
			. format('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'ccccccccccccccccc'),
			$other,
		);

		// what follows the family on the line is no matter of its operands, the condition leaving it a line of its own
		if (
			(in_array(TypeIdentifier::TRUE, $identifiersOfTheBuiltinTypes, true)
				|| in_array(TypeIdentifier::FALSE, $identifiersOfTheBuiltinTypes, true))
			&& in_array(TypeIdentifier::BOOL, $identifiersOfTheBuiltinTypes, true)
		) {
			return;
		}

		if (
			in_array(
				$name,
				['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'],
				true,
			)
			|| $enabled
		) {
			return;
		}

		if (
			$enabled
			|| ($name !== '' && in_array(
				$name,
				['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width', 'other'],
				true,
			))
		) {
			return;
		}

		XX;
	$processor = longLinesProcessor();
	$result = $processor->process('family.php', $code);
	Assert::null($result->failure);
	Assert::same($expected, $result->output);
	Assert::same($expected, $processor->process('family.php', $expected)->output);
});


test('a ternary leaves its condition or its last branch to spread as a family of operators does, never its first branch', function () {
	$code = <<<'XX'
		<?php

		final class Shop
		{
			public function left(string $name): void
			{
				$a = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) ?: $name;
				$b = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) ?? $name;
				$c = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) && $name;
				$d = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) and $name;
				$x = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) or throw new Exception;
				$e = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) . $name;
				$f = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) + $name;
				$g = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) ** $name;
				$h = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) | $name;
				$i = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) << $name;
				$j = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) === $name;
				$k = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) <=> $name;
				$l = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) instanceof Order;
				$m = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) |> trim(...);
			}


			public function right(string $name): void
			{
				$a = $name ?: $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
				$b = $name ?? $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
				$c = $name && $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
				$d = $name and $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
				$e = $name . $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
				$n .= $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
				$o ??= $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
			}


			// the condition and the last branch, never the first one, which would hide the `?`
			public function full(string $name): void
			{
				$a = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) ? $name : '';
				$b = $name ? $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) : '';
				$c = $name ? '' : $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
			}


			// the ternary spreads itself where the branches would not fit after the parenthesis, or the operands before the
			// last one take more than half of the line
			public function spread(string $name): void
			{
				$p = $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name) ? $this->formatTheNameOfTheCustomerWithAllOfItsTitlesAndDegrees($name, $titlesOfTheCustomer) : $this->formatTheDefaultNameOfTheShop($name);
				$q = $this->repository->findTheDefaultNameByOrder($orderOfTheCustomer, $optionsOfTheQuery) ?: $this->database->fetchField('SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?', $name);
			}
		}

		XX;
	$expected = <<<'XX'
		<?php

		final class Shop
		{
			public function left(string $name): void
			{
				$a = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) ?: $name;
				$b = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) ?? $name;
				$c = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) && $name;
				$d = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) and $name;
				$x = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) or throw new Exception;
				$e = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) . $name;
				$f = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) + $name;
				$g = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) ** $name;
				$h = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) | $name;
				$i = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) << $name;
				$j = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) === $name;
				$k = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) <=> $name;
				$l = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) instanceof Order;
				$m = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) |> trim(...);
			}


			public function right(string $name): void
			{
				$a = $name ?: $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
				$b = $name ?? $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
				$c = $name && $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
				$d = $name and $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
				$e = $name . $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
				$n .= $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
				$o ??= $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
			}


			// the condition and the last branch, never the first one, which would hide the `?`
			public function full(string $name): void
			{
				$a = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				) ? $name : '';
				$b = $name
					? $this->database->fetchField(
						'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
						$name,
					)
					: '';
				$c = $name ? '' : $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				);
			}


			// the ternary spreads itself where the branches would not fit after the parenthesis, or the operands before the
			// last one take more than half of the line
			public function spread(string $name): void
			{
				$p = $this->database->fetchField(
					'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
					$name,
				)
					? $this->formatTheNameOfTheCustomerWithAllOfItsTitlesAndDegrees($name, $titlesOfTheCustomer)
					: $this->formatTheDefaultNameOfTheShop($name);
				$q = $this->repository->findTheDefaultNameByOrder($orderOfTheCustomer, $optionsOfTheQuery)
					?: $this->database->fetchField(
						'SELECT name FROM customer INNER JOIN shop_order ON customer.id = customer_id WHERE order_id=?',
						$name,
					);
			}
		}

		XX;
	$processor = longLinesProcessor();
	$result = $processor->process('ternary.php', $code);
	Assert::null($result->failure);
	Assert::same($expected, $result->output);
	Assert::same($expected, $processor->process('ternary.php', $expected)->output);
});


test('a family of `|>` or `??` spreads itself where its short first operand would not need to, a wide operand in its place', function () {
	$code = <<<'XX'
		<?php

		final class Topic
		{
			// the family fits once spread, the short first operand being no reason to spread its brackets
			public function pipe(array $posts): array
			{
				$tiers = array_map(fn($post): int => $post->posterId, $posts) |> array_unique(...) |> array_values(...) |> $this->userTitles->tiersFor(...);
				$tiers = array_map(fn($post): int => $post->posterId, $posts) ?? array_unique($posts) ?? array_values($posts) ?? $this->titles->tiersFor($posts);
				$variant = $set?->getPrimaryVariant() ?? $this->variants[$expiration->variant_id ?? $this->variantId] ?? (reset($this->variants) ?: $this->error());
			}


			// a first or a last operand wide on a line of its own spreads its brackets in place of the family
			public function wideOperand(array $posts): array
			{
				$tiers = array_map(fn($post): int => $post->posterId + $post->editorId + $post->reviewerId + $post->moderatorId + $post->ownerId, $posts) ?? [];
				$tiers = $cached ?? array_map(fn($post): int => $post->posterId + $post->editorId + $post->reviewerId + $post->moderatorId + $post->ownerId, $posts);
				$value = $next ?? throw new ParseException("Option $option->name requires a value.", $command, reason: ParseError::MissingValue, parameter: $option);
				$subcommand = array_values(array_filter($commands, fn(Command $candidate) => $candidate->name === $argumentOfTheCommand && $candidate->isEnabled()))[0] ?? null;
			}


			// operands after the first narrower than a tenth of the line are not worth a line, the line staying too wide
			public function narrow(array $info): void
			{
				$this->deleteWhenMissingModels = $this->getAttributeValue($notification, DeleteWhenMissingModels::class, 'deleteWhenMissingModels') ?? false;
				$info['flags']['homogenous'] = (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['sound_information_of_the_whole_stream_in_the_file'] & 0x0001);
			}
		}

		XX;
	$expected = <<<'XX'
		<?php

		final class Topic
		{
			// the family fits once spread, the short first operand being no reason to spread its brackets
			public function pipe(array $posts): array
			{
				$tiers = array_map(fn($post): int => $post->posterId, $posts)
					|> array_unique(...)
					|> array_values(...)
					|> $this->userTitles->tiersFor(...);
				$tiers = array_map(fn($post): int => $post->posterId, $posts)
					?? array_unique($posts)
					?? array_values($posts)
					?? $this->titles->tiersFor($posts);
				$variant = $set?->getPrimaryVariant()
					?? $this->variants[$expiration->variant_id ?? $this->variantId]
					?? (reset($this->variants) ?: $this->error());
			}


			// a first or a last operand wide on a line of its own spreads its brackets in place of the family
			public function wideOperand(array $posts): array
			{
				$tiers = array_map(
					fn($post): int => $post->posterId + $post->editorId + $post->reviewerId + $post->moderatorId + $post->ownerId,
					$posts,
				) ?? [];
				$tiers = $cached ?? array_map(
					fn($post): int => $post->posterId + $post->editorId + $post->reviewerId + $post->moderatorId + $post->ownerId,
					$posts,
				);
				$value = $next ?? throw new ParseException(
					"Option $option->name requires a value.",
					$command,
					reason: ParseError::MissingValue,
					parameter: $option,
				);
				$subcommand = array_values(array_filter(
					$commands,
					fn(Command $candidate) => $candidate->name === $argumentOfTheCommand && $candidate->isEnabled(),
				))[0] ?? null;
			}


			// operands after the first narrower than a tenth of the line are not worth a line, the line staying too wide
			public function narrow(array $info): void
			{
				$this->deleteWhenMissingModels = $this->getAttributeValue($notification, DeleteWhenMissingModels::class, 'deleteWhenMissingModels') ?? false;
				$info['flags']['homogenous'] = (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['sound_information_of_the_whole_stream_in_the_file'] & 0x0001);
			}
		}

		XX;
	// the standard does not spread `??` for the width, so the operator is taken out of its exceptions here
	$processor = longLinesProcessor(['file' => ['lineLengthExcept' => ['imports', 'strings']]]);
	$result = $processor->process('pipe.php', $code);
	Assert::null($result->failure);
	Assert::same($expected, $result->output);
	Assert::same($expected, $processor->process('pipe.php', $expected)->output);
});


test('a call in a condition waits for the condition, which is spread first', function () {
	$code = <<<'XX'
		<?php

		if ($this->isFeatureEnabled($feature, $context) && $this->repository->findByIdentifierAndOptions($identifierOfTheEntity, $options) !== null) {
			return;
		}

		XX;
	Assert::same(<<<'XX'
		<?php

		if (
			$this->isFeatureEnabled($feature, $context)
			&& $this->repository->findByIdentifierAndOptions($identifierOfTheEntity, $options) !== null
		) {
			return;
		}

		XX, longLinesProcessor()->process('condition.php', $code)->output);
});


test('a call in a negated condition or after an operator nested on the right waits for the condition too', function () {
	$code = <<<'XX'
		<?php

		if (!($this->isFeatureEnabled($feature, $context) && $this->repository->findByIdentifierAndOptions($entityOfTheRequest, $optionsOfTheQuery))) {
		}
		if ($this->isFeatureEnabled($feature) || $enabled && $this->repository->findByIdentifierAndOptions($entityOfTheRequest, $optionsOfTheQuery)) {
		}

		XX;
	Assert::same(<<<'XX'
		<?php

		if (
			!($this->isFeatureEnabled($feature, $context) && $this->repository->findByIdentifierAndOptions($entityOfTheRequest, $optionsOfTheQuery))
		) {
		}
		if (
			$this->isFeatureEnabled($feature)
			|| ($enabled && $this->repository->findByIdentifierAndOptions($entityOfTheRequest, $optionsOfTheQuery))
		) {
		}

		XX, longLinesProcessor()->process('negated.php', $code)->output);
});


test('flags joined by `|` are spread like a boolean operator, while `&` stays on its line', function () {
	$code = <<<'XX'
		<?php

		const Flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_HEX_TAG;
		const Mask = E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_DEPRECATED & ~E_WARNING & ~E_USER_WARNING & ~E_STRICT;

		XX;
	Assert::same(<<<'XX'
		<?php

		const Flags = JSON_PRETTY_PRINT
			| JSON_UNESCAPED_SLASHES
			| JSON_UNESCAPED_UNICODE
			| JSON_INVALID_UTF8_SUBSTITUTE
			| JSON_THROW_ON_ERROR
			| JSON_HEX_TAG;
		const Mask = E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_DEPRECATED & ~E_WARNING & ~E_USER_WARNING & ~E_STRICT;

		XX, longLinesProcessor()->process('flags.php', $code)->output);
});


test('the arguments of a call in a chain or a ternary wait for the chain or the ternary, which is spread first', function () {
	$code = <<<'XX'
		<?php

		$result = $this->repository->createQueryBuilder($aliasOfTheEntity)->where($conditionOfTheQuery, $parametersOfTheQueryBuilder)->getResultAsArray();
		$value = $condition ? $this->computeFirstValue($firstArgument, $secondArgument) : $this->computeSecondValue($thirdArgument, $fourthArgument);

		XX;
	Assert::same(<<<'XX'
		<?php

		$result = $this->repository->createQueryBuilder($aliasOfTheEntity)
			->where($conditionOfTheQuery, $parametersOfTheQueryBuilder)
			->getResultAsArray();
		$value = $condition
			? $this->computeFirstValue($firstArgument, $secondArgument)
			: $this->computeSecondValue($thirdArgument, $fourthArgument);

		XX, longLinesProcessor()->process('chain.php', $code)->output);
});


test('a sole argument that is a call is hugged by the parentheses of the call around it', function () {
	$code = <<<'XX'
		<?php

		function check(): void
		{
			throw new InvalidArgumentException(sprintf('The value %s of the option %s is not one of the allowed ones, see %s.', $value, $option, $url));
		}

		XX;
	Assert::same(<<<'XX'
		<?php

		function check(): void
		{
			throw new InvalidArgumentException(sprintf(
				'The value %s of the option %s is not one of the allowed ones, see %s.',
				$value,
				$option,
				$url,
			));
		}

		XX, longLinesProcessor()->process('hugged.php', $code)->output);
});


test('a chain stays where splitting it leaves the line too wide, the arguments of its call spread instead', function () {
	$code = <<<'XX'
		<?php

		function run($c, $table, $id): mixed
		{
			return $c->query('SELECT something FROM a_long_table_name WHERE column_one = ? AND column_two = ? AND column_three = ?', $table, $id, 'value')->fetchField();
		}


		function narrow($c, $table): mixed
		{
			return $c->query('SELECT something FROM a_long_table_name WHERE column_one = ? AND column_two = ?', $table, 'another value')->fetchField();
		}

		XX;
	$processor = longLinesProcessor();
	$output = $processor->process('hugged-call.php', $code)->output;
	Assert::same(<<<'XX'
		<?php

		function run($c, $table, $id): mixed
		{
			return $c->query(
				'SELECT something FROM a_long_table_name WHERE column_one = ? AND column_two = ? AND column_three = ?',
				$table,
				$id,
				'value',
			)->fetchField();
		}


		function narrow($c, $table): mixed
		{
			return $c->query('SELECT something FROM a_long_table_name WHERE column_one = ? AND column_two = ?', $table, 'another value')
				->fetchField();
		}

		XX, $output);
	Assert::same($output, $processor->process('hugged-call.php', (string) $output)->output);
});


test('a condition that is a comparison splits before it, the sides each on a line of its own, before what they hold', function () {
	$code = <<<'XX'
		<?php

		if ($presenter->formatTemplateFileOfTheAction($destination) !== (isset($name) ? $presenter->formatTemplateFileOfTheChosenView($name) : '')) {
			echo 1;
		}

		XX;
	Assert::same(<<<'XX'
		<?php

		if (
			$presenter->formatTemplateFileOfTheAction($destination)
			!== (isset($name) ? $presenter->formatTemplateFileOfTheChosenView($name) : '')
		) {
			echo 1;
		}

		XX, longLinesProcessor()->process('comparison.php', $code)->output);
});


test('a match arm splits before its double arrow where the result then fits, before the result is spread', function () {
	$code = <<<'XX'
		<?php

		$method = match (true) {
			$isAjax && str_starts_with($action, 'handle') && strlen($action) > 6 => 'render' . ucfirst(substr($action, 6)) . ucfirst($nameOfTheView),
			default => $name,
		};

		XX;
	Assert::same(<<<'XX'
		<?php

		$method = match (true) {
			$isAjax && str_starts_with($action, 'handle') && strlen($action) > 6
				=> 'render' . ucfirst(substr($action, 6)) . ucfirst($nameOfTheView),
			default => $name,
		};

		XX, longLinesProcessor()->process('arm.php', $code)->output);
});


test('a match arm whose conditions are narrower than two levels of indentation does not split before its double arrow', function () {
	$code = <<<'XX'
		<?php

		$value = match (true) {
			is_string($result) => $result,
			default => throw new LogicException("Tool '$name' returned " . get_debug_type($result) . ', expected a string or a list of strings now.'),
		};

		XX;
	Assert::same($code, longLinesProcessor()->process('default.php', $code)->output);
});


test('a list too narrow to help the line it ends is not spread, the line staying too wide', function () {
	$code = <<<'XX'
		<?php

		$row = $this->connectionToTheDatabase->statementOfTheQuery->resultOfTheStatement[$indexOfTheRowInTheResult]->fetchTheRow(true);

		XX;
	Assert::same($code, longLinesProcessor()->process('narrow.php', $code)->output);
});


test('of two constructs with no common one to spread, the last on the line is spread first, unless the arm splits before', function () {
	$code = <<<'XX'
		<?php

		$value = match (true) {
			$this->isFinalPrivateMethodOfTheClass($node) => [Token::Final, 'Useless `final` modifier, because no child overrides a private method, which no child could ever do, nor any other class of the project.'],
			$this->isTheThingOfTheValueSupportedHere($firstArgument, $secondArgument) => throw new Exception('Missing ' . describe($first, $second)),
		};

		XX;
	Assert::same(<<<'XX'
		<?php

		$value = match (true) {
			$this->isFinalPrivateMethodOfTheClass($node) => [
				Token::Final,
				'Useless `final` modifier, because no child overrides a private method, which no child could ever do, nor any other class of the project.',
			],
			$this->isTheThingOfTheValueSupportedHere($firstArgument, $secondArgument)
				=> throw new Exception('Missing ' . describe($first, $second)),
		};

		XX, longLinesProcessor()->process('siblings.php', $code)->output);
});


test('a construct is not spread where what follows it on the line is too wide by itself', function () {
	$code = <<<'XX'
		<?php

		$value = match (true) {
			$entry instanceof Plugin || isPlugin($entry) => throw new InvalidArgumentException('The plugin is used by the configuration of the project, never by a preset or an override of it.'),
		};

		XX;
	Assert::same($code, longLinesProcessor()->process('rest.php', $code)->output);
});


test('the inner call stays on the line the outer one leaves it on when it fits there', function () {
	$code = <<<'XX'
		<?php

		$context->report($node, sprintf('The line is %d characters long, more than %d, which is far too long for one line.', $width, $limit), fixable: false);

		XX;
	Assert::same(<<<'XX'
		<?php

		$context->report(
			$node,
			sprintf('The line is %d characters long, more than %d, which is far too long for one line.', $width, $limit),
			fixable: false,
		);

		XX, longLinesProcessor()->process('inner.php', $code)->output);
});
