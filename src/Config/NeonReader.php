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
use function is_float, is_int, is_string, sprintf;


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

		try {
			return self::toConfig($data);
		} catch (\InvalidArgumentException $e) { // a value the configuration API refuses is an error of the file
			throw new ConfigurationException("Configuration file $file: {$e->getMessage()}", previous: $e);
		}
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
			// a bare value is the decision of the rule; keep says "this decision enforces nothing", as false does in the PHP notation
			'rules' => Expect::arrayOf(Expect::anyOf(Expect::bool(), Expect::string(), Expect::int(), Expect::arrayOf('mixed', 'string')), 'string'),
			'indent' => Expect::anyOf(Expect::int(), Expect::string()),
			'eol' => Expect::string(),
			'php' => Expect::anyOf(Expect::string(), Expect::int(), Expect::float()),
			'paths' => Expect::listOf('string'),
			'excludePaths' => Expect::listOf('string'),
			'warnings' => Expect::listOf('string'),
			'overrides' => Expect::listOf(Expect::structure([
				'paths' => Expect::listOf('string')->required(),
				'rules' => Expect::arrayOf(Expect::anyOf(Expect::bool(), Expect::string(), Expect::int(), Expect::arrayOf('mixed', 'string')), 'string'),
			])->castTo('array')),
			'risky' => Expect::bool(),
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

		/** @var array<string, bool|string|int|array<string, mixed>> $rules */
		$rules = $data['rules'] ?? [];
		foreach ($rules as $rule => $value) {
			if ($value === false || $value === 'keep') {
				$config->disable($rule);
			} else {
				$config->enable($rule, $value);
			}
		}

		if (isset($data['indent'])) {
			$indent = $data['indent'];
			assert(is_int($indent) || is_string($indent));
			$config->indent($indent);
		}

		if (isset($data['eol'])) {
			$config->eol((string) $data['eol']);
		}

		if (isset($data['php'])) {
			$version = $data['php'];
			assert(is_int($version) || is_float($version) || is_string($version));
			// a version written as a number has a single digit as its minor, as every PHP ever released has had
			$config->php(is_string($version) ? $version : sprintf('%.1F', $version));
		}

		if (isset($data['paths'])) {
			$config->paths(self::listOfStrings($data, 'paths'));
		}

		$config->excludePaths(self::listOfStrings($data, 'excludePaths'));

		/** @var list<array{paths: list<string>, rules: array<string, bool|string|int|array<string, mixed>>}> $overrides */
		$overrides = $data['overrides'] ?? [];
		foreach ($overrides as $override) {
			$rules = [];
			foreach ($override['rules'] as $rule => $value) {
				$rules[$rule] = $value === 'keep' ? false : $value;
			}

			$config->override($override['paths'], $rules);
		}

		if (isset($data['warnings'])) {
			$config->warnings(self::listOfStrings($data, 'warnings'));
		}

		if (isset($data['risky'])) {
			$config->risky((bool) $data['risky']);
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
