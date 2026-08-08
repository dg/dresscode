<?php declare(strict_types=1);

namespace DressCode\Engine;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use function is_array, is_int, is_string;


/**
 * Remembers which file contents came out clean under an effective configuration, so an unchanged file is not
 * processed again. One JSON file per project root; entries of another configuration are dropped, entries
 * untouched for a month expire.
 * @internal
 */
final class ResultCache
{
	private const Expiration = 30 * 24 * 3600;

	/** @var array<string, int>  content hash → time last confirmed */
	private array $entries = [];

	/** @var array<string, true> */
	private array $touched = [];


	public function __construct(
		private readonly string $file,
		private readonly string $configHash,
	) {
	}


	/** Loads the entries of the file when it belongs to the same configuration; an unreadable file is an empty cache. */
	public static function load(string $file, string $configHash): self
	{
		$cache = new self($file, $configHash);
		$json = @file_get_contents($file); // @ - a missing or unreadable file is an empty cache
		try {
			$data = $json === false ? null : Json::decode($json, forceArrays: true);
		} catch (JsonException) {
			$data = null;
		}

		if (is_array($data) && ($data['config'] ?? null) === $configHash && is_array($data['entries'] ?? null)) {
			foreach ($data['entries'] as $key => $time) {
				if (is_string($key) && is_int($time)) {
					$cache->entries[$key] = $time;
				}
			}
		}

		return $cache;
	}


	public static function hashContent(string $code): string
	{
		return hash('xxh128', $code);
	}


	/** Whether the content is known to be clean; asking keeps the entry alive. */
	public function isClean(string $key): bool
	{
		if (!isset($this->entries[$key])) {
			return false;
		}

		$this->touched[$key] = true;
		return true;
	}


	public function markClean(string $key): void
	{
		$this->entries[$key] = time();
		$this->touched[$key] = true;
	}


	/** Writes the entries touched by this run and the recent ones; failures are silent, a cache is a convenience. */
	public function save(): void
	{
		$now = time();
		$entries = [];
		foreach ($this->entries as $key => $time) {
			if (isset($this->touched[$key])) {
				$entries[$key] = $now;
			} elseif ($now - $time < self::Expiration) {
				$entries[$key] = $time;
			}
		}

		$dir = dirname($this->file);
		if (!is_dir($dir) && !@mkdir($dir, recursive: true) && !is_dir($dir)) { // @ - failure is silent
			return;
		}

		$json = Json::encode(['config' => $this->configHash, 'entries' => (object) $entries]);
		@file_put_contents($this->file, $json, LOCK_EX); // @ - failure is silent; a torn read loads as an empty cache
	}


	public function count(): int
	{
		return \count($this->entries);
	}
}
