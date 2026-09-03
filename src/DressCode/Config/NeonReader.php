<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;


/**
 * Reads dresscode.neon. Every key is a method of Config and nothing else is accepted, so a misspelled one
 * is an error of the file, not a setting silently ignored.
 * @internal
 */
final class NeonReader
{
	/** @throws ConfigurationException */
	public static function read(string $file): Config
	{
		$content = @file_get_contents($file); // @ - reported as exception
		if ($content === false) {
			throw new ConfigurationException("Cannot read the configuration file $file.");
		}

		try {
			$decoded = Neon::decode($content);
		} catch (NeonException $e) {
			throw new ConfigurationException("Configuration file $file is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		try {
			/** @var array<string, mixed> $data */
			$data = (new Processor)->process(self::getSchema(), $decoded ?? []);
		} catch (ValidationException $e) {
			throw new ConfigurationException("Configuration file $file: " . implode(' ', $e->getMessages()), previous: $e);
		}

		return self::toConfig($data);
	}


	/**
	 * The keys that were given, defaults left out: a key absent from the file must not be told to Config,
	 * or an empty list would pass for a decision.
	 */
	private static function getSchema(): Structure
	{
		return Expect::structure([
			'extensions' => Expect::listOf('string'),
			'presets' => Expect::listOf('string'),
			'rules' => Expect::arrayOf(Expect::anyOf(Expect::bool(), Expect::arrayOf('mixed', 'string')), 'string'),
			'style' => Expect::structure([
				'indent' => Expect::string(),
				'eol' => Expect::string(),
			])->castTo('array'),
			'phpVersion' => Expect::string(),
			'paths' => Expect::listOf('string'),
			'excludePaths' => Expect::listOf('string'),
			'excludeRulePaths' => Expect::arrayOf(Expect::listOf('string'), 'string'),
			'fileExtensions' => Expect::listOf('string'),
			'baseline' => Expect::string(),
			'cacheDir' => Expect::string(),
			'analyses' => Expect::listOf('string'),
		])->skipDefaults()->castTo('array');
	}


	/** @param array<string, mixed> $data */
	private static function toConfig(array $data): Config
	{
		$config = Config::create();
		foreach (self::listOfStrings($data, 'extensions') as $extension) {
			$config->extension($extension);
		}

		foreach (self::listOfStrings($data, 'presets') as $preset) {
			$config->preset($preset);
		}

		/** @var array<string, bool|array<string, mixed>> $rules */
		$rules = $data['rules'] ?? [];
		foreach ($rules as $rule => $value) {
			$value === false ? $config->disable($rule) : $config->enable($rule, $value);
		}

		/** @var array{indent: ?string, eol: ?string} $style */
		$style = $data['style'] ?? ['indent' => null, 'eol' => null];
		$config->style($style['indent'] ?? null, $style['eol'] ?? null);

		if (isset($data['phpVersion'])) {
			$config->phpVersion((string) $data['phpVersion']);
		}

		if (isset($data['paths'])) {
			$config->paths(self::listOfStrings($data, 'paths'));
		}

		$config->excludePaths(self::listOfStrings($data, 'excludePaths'));
		/** @var array<string, list<string>> $ruleExcludePaths */
		$ruleExcludePaths = $data['excludeRulePaths'] ?? [];
		foreach ($ruleExcludePaths as $rule => $patterns) {
			$config->excludeRulePaths($rule, $patterns);
		}

		if (isset($data['fileExtensions'])) {
			$config->fileExtensions(self::listOfStrings($data, 'fileExtensions'));
		}

		if (isset($data['baseline'])) {
			$config->baseline((string) $data['baseline']);
		}

		if (isset($data['cacheDir'])) {
			$config->cacheDir((string) $data['cacheDir']);
		}

		foreach (self::listOfStrings($data, 'analyses') as $analysis) {
			/** @var class-string $analysis */
			$config->analysis($analysis);
		}

		return $config;
	}


	/**
	 * @param  array<string, mixed>  $data
	 * @return list<string>
	 */
	private static function listOfStrings(array $data, string $key): array
	{
		/** @var list<string> $value */
		$value = $data[$key] ?? [];
		return $value;
	}
}
