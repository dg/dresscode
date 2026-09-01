<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Extension, Override, Rule};
use Nette\Neon\{Entity, Exception as NeonException, Neon};
use Nette\Schema\Elements\Structure;
use Nette\Schema\{Expect, Processor, Schema, ValidationException};
use function is_array, is_callable, is_float, is_int, is_object, is_string, sprintf;


/**
 * Reads dresscode.neon. Every key is a parameter of Config and nothing else is accepted, so a misspelled one is an error
 * of the file, not a setting silently ignored. What the PHP notation passes as an object or a callable, the file writes
 * as an entity: `Class(args)`, `Class::method(args)` or `Class::method(...)`.
 * @internal
 */
final class NeonReader
{
	/** @throws ConfigurationException */
	public static function read(string $file): Config
	{
		$content = @file_get_contents($file); // @ - reported as exception
		if ($content === false) {
			throw new ConfigurationException("Configuration file $file cannot be read.");
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
			return self::createConfig($data, $file);
		} catch (\InvalidArgumentException $e) { // a value the configuration refuses is an error of the file
			throw new ConfigurationException("Configuration file $file: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * The keys that were given, defaults left out: a key absent from the file must reach Config as absent, so that its
	 * default applies.
	 */
	private static function getSchema(): Structure
	{
		return Expect::structure([
			'extensions' => Expect::listOf(Expect::anyOf(Expect::string(), Expect::type(Entity::class))),
			...self::getProfileSchema(),
			'overrides' => Expect::listOf(Expect::structure([
				'paths' => Expect::listOf('string')->required(),
				...self::getProfileSchema(),
			])->skipDefaults()->castTo('array')),
			'paths' => Expect::listOf('string'),
			'excludePaths' => Expect::listOf('string'),
			'fileExtensions' => Expect::listOf('string'),
			'skipWhen' => Expect::type(Entity::class),
			'baseline' => Expect::string(),
			// a class the engine builds itself, or a class with the entity of its factory
			'analyses' => Expect::arrayOf(Expect::anyOf(Expect::string(), Expect::type(Entity::class))),
		])->skipDefaults()->castTo('array');
	}


	/**
	 * The keys of a profile, which the configuration and every override have.
	 * @return array<string, Schema>
	 */
	private static function getProfileSchema(): array
	{
		return [
			'presets' => Expect::listOf('string'),
			'groups' => Expect::listOf('string'),
			// a bare value is the decision of the rule
			'rules' => Expect::arrayOf(Expect::anyOf(Expect::bool(), Expect::string(), Expect::int(), Expect::arrayOf('mixed', 'string'), Expect::type(Entity::class)), 'string'),
			'indent' => Expect::anyOf(Expect::int(), Expect::string()),
			'eol' => Expect::string(),
			'lineLength' => Expect::anyOf(Expect::int(), false),
			'php' => Expect::anyOf(Expect::string(), Expect::int(), Expect::float()),
			'namespaces' => Expect::structure([
				'functions' => Expect::listOf('string'),
				'constants' => Expect::listOf('string'),
			])->skipDefaults()->castTo('array'),
			'nameResolution' => Expect::anyOf('certain', 'uncertain'),
			'fixRisky' => Expect::listOf('string'),
			'warnings' => Expect::listOf('string'),
		];
	}


	/**
	 * @param  array<string, mixed>  $data
	 * @throws \InvalidArgumentException
	 */
	private static function createConfig(array $data, string $file): Config
	{
		$data = self::readProfile($data, $file);
		/** @var list<string|Entity> $extensions */
		$extensions = $data['extensions'] ?? [];
		$data['extensions'] = array_map(fn(string|Entity $extension) => is_string($extension) ? $extension : self::evaluateExtension($extension), $extensions);

		/** @var list<array<string, mixed>> $overrides */
		$overrides = $data['overrides'] ?? [];
		$data['overrides'] = array_map(fn(array $override) => new Override(...self::readProfile($override, $file)), $overrides);

		if (isset($data['skipWhen'])) {
			assert($data['skipWhen'] instanceof Entity);
			$data['skipWhen'] = self::evaluateCallable($data['skipWhen'], 'The value of skipWhen');
		}

		/** @var array<int|string, string|Entity> $analyses */
		$analyses = $data['analyses'] ?? [];
		foreach ($analyses as $class => $factory) {
			$analyses[$class] = match (true) {
				is_int($class) && is_string($factory) => $factory,
				is_string($class) && $factory instanceof Entity => self::evaluateCallable($factory, "The factory of analysis $class"),
				default => throw new \InvalidArgumentException('An analysis is written as its class, or as its class with the entity of its factory.'),
			};
		}

		$data['analyses'] = $analyses;
		return new Config(...$data);
	}


	/**
	 * The keys of a profile as its constructor takes them: the entity of a rule is the recipe of its factory.
	 * @param  array<string, mixed>  $data
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException
	 */
	private static function readProfile(array $data, string $file): array
	{
		/** @var array<string, mixed> $rules */
		$rules = $data['rules'] ?? [];
		foreach ($rules as $rule => $value) {
			if ($value instanceof Entity) {
				$data['rules'][$rule] = self::createRuleFactory($rule, $value, $file);
			}
		}

		// a version written as a number has a single digit as its minor, as every PHP ever released has had
		if (isset($data['php']) && !is_string($data['php'])) {
			assert(is_int($data['php']) || is_float($data['php']));
			$data['php'] = sprintf('%.1F', $data['php']);
		}

		return $data;
	}


	/**
	 * A rule is built anew for every combination of overrides, so its entity is a recipe evaluated at every
	 * build; the class or method it starts from is looked up when the file is read, so that a misspelled one is an
	 * error of the file.
	 * @return \Closure(): Rule
	 * @throws \InvalidArgumentException
	 */
	private static function createRuleFactory(string $rule, Entity $entity, string $file): \Closure
	{
		$first = $entity->value === Neon::Chain ? $entity->attributes[0] : $entity;
		assert($first instanceof Entity && is_string($first->value));
		if (str_contains($first->value, '::')) {
			[$class, $method] = explode('::', $first->value, 2);
			self::resolveMethod($class, $method);
		} else {
			self::resolveClass($first->value);
		}

		return function () use ($rule, $entity, $file): Rule {
			try {
				$value = self::evaluate($entity);
			} catch (\InvalidArgumentException $e) {
				throw new ConfigurationException("Configuration file $file: {$e->getMessage()}", previous: $e);
			}

			return $value instanceof Rule
				? $value
				: throw new ConfigurationException("Configuration file $file: The entity of rule $rule gives " . get_debug_type($value) . ', not a rule.');
		};
	}


	/** @throws \InvalidArgumentException */
	private static function evaluateExtension(Entity $entity): Extension
	{
		$value = self::evaluate($entity);
		return $value instanceof Extension
			? $value
			: throw new \InvalidArgumentException('An extension must implement ' . Extension::class . ', ' . get_debug_type($value) . ' given.');
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
			: throw new \InvalidArgumentException("$subject must be callable, " . get_debug_type($value) . ' given.');
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
				throw new \InvalidArgumentException("$name(): {$e->getMessage()}", previous: $e);
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
			: throw new \InvalidArgumentException("Class $class does not exist.");
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
				: throw new \InvalidArgumentException("Static method $class::$method() does not exist.");
		}

		$callable = [$subject, $method];
		return is_object($subject) && is_callable($callable)
			? $callable
			: throw new \InvalidArgumentException("Method $method() cannot be called on " . get_debug_type($subject) . '.');
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
