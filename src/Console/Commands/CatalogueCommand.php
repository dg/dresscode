<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\{Catalogue, ConfigResolver};
use DressCode\Console\{CommandContext, Markup};
use Nette\CommandLine\{Ansi, Attributes as Cli, ParseResult};
use Nette\Utils\Json;


/**
 * The command `catalogue`: every decision of the catalogue with its description, those the configuration makes marked;
 * or the catalogue as data, the values of the standards included.
 * @internal
 */
final readonly class CatalogueCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * @param  list<string>  $only
	 */
	#[Cli\Command('List every decision the rules of the run declare, those this configuration makes marked')]
	public function catalogue(
		ParseResult $args,
		#[Cli\Option(
			'Only these: a decision, a section, a rule or a preset, narrowed to what the configuration runs; the values stay as the configuration resolves them',
			valueName: 'name',
			repeatable: true,
		)]
		array $only = [],
		#[Cli\Option('Output format: `console` to read, `json` as data', alias: '-f', enum: ['console', 'json'])]
		?string $format = null,
	): int
	{
		[$factory, $resolution] = $this->context->resolveProject($args, $only);
		$catalogue = $resolution->getCatalogue();
		$out = $this->context->out;
		if ($format === 'json') {
			$data = $catalogue->toArray();
			foreach (new ConfigResolver($factory->registry)->resolveStandards(
				$resolution->resolvedConfig->phpVersion,
			) as $standard => $decisions) {
				foreach ($decisions as $path => $decision) {
					if (isset($data['decisions'][$path])) {
						$data['decisions'][$path]['standards'][$standard] = $decision->value->toData();
					}
				}
			}

			$out->write(Json::encode($data, pretty: true) . "\n");
			return 0;
		}

		$made = $resolution->resolvedConfig->decisions;
		// the sections of the core in their order, those of the plugins and of the project after them
		$decisions = $catalogue->getDecisions();
		$order = array_flip(Catalogue::CoreSections);
		uksort(
			$decisions,
			fn(string $a, string $b) => ($order[explode('.', $a)[0]] ?? PHP_INT_MAX) <=> ($order[explode('.', $b)[0]] ?? PHP_INT_MAX),
		);
		foreach ($decisions as $path => $decision) {
			$set = isset($made[$path]) && $made[$path]->layers !== [] && !$made[$path]->value->isKept();
			$out->writeLine(
				($set ? '*' : ' ')
				. ' ' . Ansi::pad($out->color($set ? 'white' : null, $path), 50)
				. ' ' . Markup::highlightCode($out, $decision->description),
			);
		}

		$out->writeLine("\n* made by the configuration");
		return 0;
	}
}
