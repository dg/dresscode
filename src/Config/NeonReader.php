<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Override, Plugin, Profile};
use Nette\Neon\{Entity, Exception as NeonException, Neon};
use Nette\Schema\Elements\Structure;
use Nette\Schema\{Expect, Processor, Schema, ValidationException};
use Nette\Utils\Helpers;
use function is_array, is_int, is_object, is_string;


/**
 * Reads dresscode.neon. Every key is a parameter of Config or a section of the decisions, which the resolver reads
 * against the catalogue, so a misspelled one is an error of the file, not a setting silently ignored. What the PHP
 * notation passes as an object or a callable, the file writes as an entity: `Class(args)`, `Class::method(args)` or
 * `Class::method(...)`.
 * @internal
 */
final class NeonReader
{
	/** the keys of Config beside those a file reserves for good, and `only`, which belongs to the command line */
	private const OtherKeys = ['ruleUrl', 'decisions', 'only'];


	/** @throws ConfigurationException */
	public static function read(string $file): Config
	{
		$content = @file_get_contents($file); // @ is escalated to exception
		if ($content === false) {
			throw new ConfigurationException("Configuration file `$file` cannot be read.");
		}

		try {
			$decoded = Neon::decode($content);
		} catch (NeonException $e) {
			throw new ConfigurationException("Configuration file `$file` is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		try {
			/** @var array<string, mixed> $data */
			$data = (new Processor)->process(self::getSchema(), self::separateDecisions($decoded ?? []));
		} catch (ValidationException $e) {
			throw new ConfigurationException("Configuration file `$file`: " . implode(' ', $e->getMessages()), previous: $e);
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Configuration file `$file`: {$e->getMessage()}", previous: $e);
		}

		try {
			return self::createConfig($data, $file);
		} catch (\InvalidArgumentException $e) { // a value the configuration refuses is an error of the file
			throw new ConfigurationException("Configuration file `$file`: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * The keys of the configuration as they are, and every other one, a section of the decisions, moved under
	 * `decisions`, in the configuration and in each of its overrides; a key one letter off a key of the configuration
	 * stays, so that the schema names the key meant.
	 */
	private static function separateDecisions(mixed $data): mixed
	{
		if (!is_array($data)) {
			return $data;
		}

		$decisions = [];
		foreach ($data as $key => $value) {
			if (
				is_string($key)
				&& !in_array($key, Catalogue::ReservedKeys, true)
				&& !in_array($key, self::OtherKeys, true)
				&& Helpers::getSuggestion([...Catalogue::ReservedKeys, ...self::OtherKeys], $key) === null
			) {
				$decisions[$key] = $value;
				unset($data[$key]);
			}
		}

		if (isset($data['overrides']) && is_array($data['overrides'])) {
			$data['overrides'] = array_map(self::separateDecisions(...), $data['overrides']);
		}

		if ($decisions === []) {
			return $data;
		} elseif (isset($data['decisions'])) {
			throw new \InvalidArgumentException('The decisions are written as sections at the top or under `decisions`, not both; `' . array_key_first($decisions) . '` stands beside `decisions`.');
		}

		return $data + ['decisions' => $decisions];
	}


	/**
	 * The keys that were given, defaults left out: a key absent from the file must reach Config as absent, so that its
	 * default applies.
	 */
	private static function getSchema(): Structure
	{
		return Expect::structure([
			...self::getProfileSchema(),
			// a rule of the project by its class; one built by a factory is given by dresscode.php
			'rules' => Expect::arrayOf('mixed'),
			'overrides' => Expect::listOf(Expect::structure([
				'paths' => Expect::listOf('string', wrap: true)->required(),
				...self::getProfileSchema(),
			])->skipDefaults()->castTo('array')),
			'paths' => Expect::listOf('string', wrap: true),
			'excludePaths' => Expect::listOf('string', wrap: true),
			'fileExtensions' => Expect::listOf('string', wrap: true),
			'skipWhen' => Expect::type(Entity::class),
			// a class the engine builds itself, or a class with the entity of its factory
			'analyses' => Expect::arrayOf(Expect::anyOf(Expect::string(), Expect::type(Entity::class))),
			'ruleUrl' => Expect::string(),
		])->skipDefaults()->castTo('array');
	}


	/**
	 * The keys of a profile, which the configuration and every override have.
	 * @return array<string, Schema>
	 */
	private static function getProfileSchema(): array
	{
		return [
			'targets' => Expect::arrayOf(Expect::anyOf(Expect::string(), Expect::int(), Expect::float()), Expect::string()),
			'namespaces' => Expect::structure([
				'functions' => Expect::listOf('string', wrap: true),
				'constants' => Expect::listOf('string', wrap: true),
			])->skipDefaults()->castTo('array'),
			'nameResolution' => Expect::anyOf('certain', 'uncertain'),
			'fixRisky' => Expect::listOf('string', wrap: true),
			'warnOnly' => Expect::listOf('string', wrap: true),
			'suppressionComments' => Expect::arrayOf(Expect::listOf('string', wrap: true), 'string'),
			// a plugin with arguments is an entity
			'use' => Expect::listOf(Expect::anyOf(Expect::string(), Expect::type(Entity::class)), wrap: true),
			'decisions' => Expect::arrayOf('mixed', 'string'),
		];
	}


	/**
	 * @param  array<string, mixed>  $data
	 * @throws \InvalidArgumentException
	 */
	private static function createConfig(array $data, string $file): Config
	{
		$data = self::readProfile($data, $file);
		/** @var array<mixed> $rules */
		$rules = $data['rules'] ?? [];
		if (!array_is_list($rules) || !array_all($rules, fn($rule) => is_string($rule))) {
			throw new \InvalidArgumentException('A rule of the project is written as its class; a rule built by a factory is given by `dresscode.php`.');
		}

		/** @var list<array<string, mixed>> $overrides */
		$overrides = $data['overrides'] ?? [];
		$data['overrides'] = array_map(fn(array $override) => new Override($override['paths'], new Profile(...self::readProfile(array_diff_key($override, ['paths' => true]), $file))), $overrides);

		if (isset($data['skipWhen'])) {
			assert($data['skipWhen'] instanceof Entity);
			$data['skipWhen'] = self::evaluateCallable($data['skipWhen'], 'The value of `skipWhen`');
		}

		/** @var array<int|string, string|Entity> $analyses */
		$analyses = $data['analyses'] ?? [];
		foreach ($analyses as $class => $factory) {
			$analyses[$class] = match (true) {
				is_int($class) && is_string($factory) => $factory,
				is_string($class) && $factory instanceof Entity => self::evaluateCallable($factory, "The factory of analysis `$class`"),
				default => throw new \InvalidArgumentException('An analysis is written as its class, or as its class with the entity of its factory.'),
			};
		}

		$data['analyses'] = $analyses;

		return new Config(...$data);
	}


	/**
	 * The keys of a profile as its constructor takes them.
	 * @param  array<string, mixed>  $data
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException
	 */
	private static function readProfile(array $data, string $file): array
	{
		/** @var list<string|Entity> $use */
		$use = $data['use'] ?? [];
		$data['use'] = array_map(fn(string|Entity $entry) => is_string($entry) ? self::resolvePresetFile($entry, dirname($file)) : self::evaluatePlugin($entry), $use);

		/** @var array<string, string|int|float> $targets */
		$targets = $data['targets'] ?? [];
		foreach ($targets as $target => $version) {
			$data['targets'][$target] = match (true) {
				is_string($version) => $version,
				// a version of PHP written as a number has a single digit as its minor, as every PHP ever released has had
				default => sprintf('%.1F', $version),
			};
		}

		return $data;
	}


	/**
	 * Reads a preset, a file of the shape of the configuration, which carries the decisions, the presets it uses and the
	 * comments that silence a line, and nothing else, so that a file laid below the project never gets what only the
	 * project may say.
	 * @param  ?string  $name  the name the preset is registered under, which an error tells it by
	 * @throws ConfigurationException
	 */
	public static function readPreset(string $file, ?string $name = null): Profile
	{
		$label = $name === null ? "Preset file `$file`" : "Preset `$name`";
		try {
			$decoded = Neon::decodeFile($file);
		} catch (NeonException $e) {
			throw new ConfigurationException("$label is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		try {
			$data = (array) self::separateDecisions($decoded ?? []);
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("$label: {$e->getMessage()}", previous: $e);
		}

		$keys = ['use' => true, 'suppressionComments' => true, 'decisions' => true];
		if ($other = array_diff_key($data, $keys)) {
			throw new ConfigurationException("$label sets `" . array_key_first($other) . '`, which the project decides, not a preset.');
		}

		try {
			/** @var array{use?: list<string|Entity>, suppressionComments?: array<string, string|list<string>>, decisions?: array<string, mixed>} $data */
			$data = (new Processor)->process(Expect::structure(array_intersect_key(self::getProfileSchema(), $keys))->skipDefaults()->castTo('array'), $data);
		} catch (ValidationException $e) {
			throw new ConfigurationException("$label: " . implode(' ', $e->getMessages()), previous: $e);
		}

		try {
			return new Profile(
				use: self::readProfile($data, $file)['use'] ?? [],
				suppressionComments: $data['suppressionComments'] ?? [],
				decisions: $data['decisions'] ?? [],
			);
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("$label: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * An entry of `use` naming a file, one ending in `.neon`, as the absolute path, taken relative to the directory
	 * of what writes it; the name of a preset as it is.
	 * @throws \InvalidArgumentException
	 */
	public static function resolvePresetFile(string $preset, string $directory): string
	{
		if (!str_ends_with($preset, '.neon')) {
			return $preset;
		}

		$real = realpath(RunnerFactory::toAbsolutePath($preset, $directory));
		return $real === false
			? throw new \InvalidArgumentException("Preset file `$preset` in `use` does not exist.")
			: strtr($real, '\\', '/');
	}


	/**
	 * The plugin an entry of `use` builds; the class it starts from must be a plugin, and its arguments plain data,
	 * before anything is called, so that a configuration read from a repository cannot run other code by naming it.
	 * @throws \InvalidArgumentException
	 */
	private static function evaluatePlugin(Entity $entity): Plugin
	{
		$links = $entity->value === Neon::Chain ? $entity->attributes : [$entity];
		$first = $links[0];
		assert($first instanceof Entity && is_string($first->value));
		$class = self::resolveClass(explode('::', ltrim($first->value, ':'), 2)[0]);
		if (!is_subclass_of($class, Plugin::class)) {
			throw new \InvalidArgumentException("An entity in `use` is a plugin, which `$class` is not.");
		}

		foreach ($links as $link) {
			assert($link instanceof Entity);
			$arguments = $link->attributes;
			array_walk_recursive($arguments, fn(mixed $value) => $value instanceof Entity
				? throw new \InvalidArgumentException("The arguments of plugin `$class` in `use` are data, not an entity.")
				: null);
		}

		$value = self::evaluate($entity);
		return $value instanceof Plugin
			? $value
			: throw new \InvalidArgumentException('A plugin must implement `' . Plugin::class . '`, `' . get_debug_type($value) . '` given.');
	}


	/**
	 * @return callable(mixed...): mixed
	 * @throws \InvalidArgumentException
	 */
	private static function evaluateCallable(Entity $entity, string $subject): callable
	{
		$value = self::evaluate($entity);
		return is_callable($value)
			? $value
			: throw new \InvalidArgumentException("$subject must be callable, `" . get_debug_type($value) . '` given.');
	}


	/**
	 * What an entity stands for: `Class(args)` is a new object, `Class::method(args)` what the static method
	 * returns and `Class::method(...)` the method itself; in a chain `Class(args)::method(args)` a link calls
	 * the method on what the link before it gave. Arguments may be named and may be entities themselves.
	 * @throws \InvalidArgumentException
	 */
	private static function evaluate(Entity $entity): mixed
	{
		$value = null;
		$links = $entity->value === Neon::Chain ? $entity->attributes : [$entity];
		foreach (array_values($links) as $index => $link) {
			assert($link instanceof Entity && is_string($link->value));
			$name = ltrim($link->value, ':');
			if ($index > 0) {
				$callable = self::resolveMethod($value, $name);
			} elseif (str_contains($name, '::')) {
				[$class, $method] = explode('::', $name, 2);
				$callable = self::resolveMethod($class, $method);
			} else {
				$class = self::resolveClass($name);
				$callable = fn(mixed ...$arguments): object => new $class(...$arguments);
			}

			if ($link->attributes === ['...']) {
				$value = $callable(...);
				continue;
			}

			try {
				$value = $callable(...self::evaluateArguments($link->attributes));
			} catch (\Error $e) {
				throw new \InvalidArgumentException("`$name()`: {$e->getMessage()}", previous: $e);
			}
		}

		return $value;
	}


	/**
	 * @return class-string
	 * @throws \InvalidArgumentException
	 */
	private static function resolveClass(string $class): string
	{
		return class_exists($class)
			? $class
			: throw new \InvalidArgumentException("Class `$class` does not exist.");
	}


	/**
	 * A static method of the class a string names, or a method of the object the link before gave.
	 * @return callable(mixed...): mixed
	 * @throws \InvalidArgumentException
	 */
	private static function resolveMethod(mixed $subject, string $method): callable
	{
		if (is_string($subject)) {
			$class = self::resolveClass($subject);
			$callable = [$class, $method];
			return method_exists($class, $method) && new \ReflectionMethod($class, $method)->isStatic() && is_callable($callable)
				? $callable
				: throw new \InvalidArgumentException("Static method `$class::$method()` does not exist.");
		}

		$callable = [$subject, $method];
		return is_object($subject) && is_callable($callable)
			? $callable
			: throw new \InvalidArgumentException("Method `$method()` cannot be called on `" . get_debug_type($subject) . '`.');
	}


	/**
	 * @param  mixed[]  $arguments
	 * @return mixed[]
	 * @throws \InvalidArgumentException
	 */
	private static function evaluateArguments(array $arguments): array
	{
		foreach ($arguments as $key => $argument) {
			$arguments[$key] = match (true) {
				$argument instanceof Entity => self::evaluate($argument),
				is_array($argument) => self::evaluateArguments($argument),
				default => $argument,
			};
		}

		return $arguments;
	}
}
