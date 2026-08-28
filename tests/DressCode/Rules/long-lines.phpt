<?php declare(strict_types=1);

/**
 * A line too wide is spread from the outside in under a standard: the outer construct first, an inner one only when
 * its line is still too wide, and the whole cascade converges within the passes of a file.
 */

use DressCode\Config;
use DressCode\Config\{RuleBuilder, RunnerFactory};
use DressCode\Engine\{FileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function longLinesProcessor(): FileProcessor
{
	$factory = new RunnerFactory;
	$resolved = $factory->resolve(new Config(use: ['nette']), __DIR__)->resolveFor([]);
	$style = $resolved->createStyle();
	return new FileProcessor(
		RuleBuilder::buildRules($resolved),
		$resolved->createAnalyses($style),
		$resolved->phpVersion,
		$style,
		detectLineEnding: false,
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


test('a call in a condition waits for the condition, which is spread first', function () {
	$code = "<?php\n\nif (\$this->isFeatureEnabled(\$feature, \$context) && \$this->repository->findByIdentifierAndOptions(\$identifierOfTheEntity, \$options) !== null) {\n\treturn;\n}\n";
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


test('of two constructs with no common one to spread, the last on the line is spread first, unless it is held whole', function () {
	$code = <<<'XX'
		<?php

		$value = match (true) {
			$this->isFinalPrivateMethodOfTheClass($node) => [Token::Final, 'Useless `final` modifier, because no child overrides a private method.'],
			$this->isTheThingOfTheValueSupportedHere($firstArgument, $secondArgument) => throw new Exception('Missing ' . describe($first, $second)),
		};

		XX;
	Assert::same(<<<'XX'
		<?php

		$value = match (true) {
			$this->isFinalPrivateMethodOfTheClass($node) => [
				Token::Final,
				'Useless `final` modifier, because no child overrides a private method.',
			],
			$this->isTheThingOfTheValueSupportedHere(
				$firstArgument,
				$secondArgument,
			) => throw new Exception('Missing ' . describe($first, $second)),
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
	$code = "<?php\n\n\$context->report(\$node, sprintf('The line is %d characters long, more than %d, which is far too long for one line.', \$width, \$limit), fixable: false);\n";
	Assert::same(<<<'XX'
		<?php

		$context->report(
			$node,
			sprintf('The line is %d characters long, more than %d, which is far too long for one line.', $width, $limit),
			fixable: false,
		);

		XX, longLinesProcessor()->process('inner.php', $code)->output);
});
