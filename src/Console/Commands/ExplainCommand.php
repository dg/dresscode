<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\ConfigResolver;
use DressCode\Console\{CommandContext, ExplainPrinter, Markup};
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use Nette\Utils\Helpers;
use function is_string;


/**
 * The command `explain`: a decision in detail with the values the standards give it, the decisions under a section,
 * or the configuration as a whole when none is named, which is every decision a layer makes; the Markdown document is
 * drawn, or with `--format markdown` written as it is.
 * @internal
 */
final readonly class ExplainCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * @param  list<string>  $only
	 */
	#[Cli\Command('Explain a decision, its values and its value in this configuration; every decision the configuration makes when none is named')]
	public function explain(
		ParseResult $args,
		#[Cli\Argument('A decision, or a section or structure of them; the whole configuration when omitted')]
		?string $decision = null,
		#[Cli\Option(
			'Only these: a decision, a section, a rule or a preset, narrowed to what the configuration runs; the values stay as the configuration resolves them',
			valueName: 'name',
			repeatable: true,
		)]
		array $only = [],
		#[Cli\Option('Output format: `console` to read, `markdown` as a document', alias: '-f', enum: ['console', 'markdown'])]
		?string $format = null,
	): int
	{
		[$factory, $resolution, $configFile] = $this->context->resolveProject($args, $only);
		$resolved = $resolution->resolvedConfig;
		if (!is_string($decision)) {
			$markdown = new ExplainPrinter($factory->registry)->printConfig($resolved);

		} elseif (isset($resolved->decisions[$decision])) {
			$standards = new ConfigResolver($factory->registry)->resolveStandards($resolved->phpVersion);
			$markdown = new ExplainPrinter($factory->registry, $standards)->printDecision($resolved->decisions[$decision]);

		} elseif (array_any(array_keys($resolved->decisions), fn(string $known) => str_starts_with($known, "$decision."))) {
			$markdown = new ExplainPrinter($factory->registry)->printSection($decision, $resolved);

		} else {
			$hint = Helpers::getSuggestion(array_keys($resolved->decisions), $decision);
			throw new UsageException("Unknown decision `$decision`." . ($hint === null ? '' : " Did you mean `$hint`?"), $args->command);
		}

		if ($format === 'markdown') {
			$this->context->out->write($markdown);
			return 0;
		}

		$this->context->writeHeader($configFile, $resolution);
		$this->context->out->write("\n" . Markup::renderMarkdown($this->context->out, $markdown));
		return 0;
	}
}
