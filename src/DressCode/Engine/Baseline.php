<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\ConfigurationException;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use function count, is_array, is_string;


/**
 * Known violations left unreported, by file and fingerprint; generated from a run and kept in a JSON file.
 * A run marks the entries it matched, so the stale ones can be reported.
 * @internal
 */
final class Baseline
{
	/** @var array<string, array<string, array{rule: string, message: string}>>  path → fingerprint → entry */
	private array $entries = [];

	/** @var array<string, array<string, true>>  path → fingerprints no violation matched yet */
	private array $unused = [];

	private int $matched = 0;


	/** @throws ConfigurationException */
	public static function load(string $file): self
	{
		$json = @file_get_contents($file); // @ - reported as exception
		if ($json === false) {
			throw new ConfigurationException("Cannot read the baseline file $file.");
		}

		try {
			$data = Json::decode($json, forceArrays: true);
		} catch (JsonException $e) {
			throw new ConfigurationException("The baseline file $file is not valid JSON: {$e->getMessage()}", previous: $e);
		}

		$baseline = new self;
		foreach (is_array($data) && is_array($data['files'] ?? null) ? $data['files'] : [] as $path => $violations) {
			foreach (is_array($violations) ? $violations : [] as $violation) {
				if (
					!is_string($path)
					|| !is_array($violation)
					|| !is_string($violation['fingerprint'] ?? null)
					|| !is_string($violation['rule'] ?? null)
					|| !is_string($violation['message'] ?? null)
				) {
					throw new ConfigurationException("The baseline file $file has an unexpected shape.");
				}

				$baseline->add($path, $violation['fingerprint'], $violation['rule'], $violation['message']);
			}
		}

		return $baseline;
	}


	/** @param list<FileResult> $results */
	public static function fromResults(array $results): self
	{
		$baseline = new self;
		foreach ($results as $result) {
			foreach ($result->violations as $violation) {
				$baseline->add($result->path, $violation->fingerprint, $violation->ruleName, $violation->message);
			}
		}

		return $baseline;
	}


	private function add(string $path, string $fingerprint, string $rule, string $message): void
	{
		$this->entries[$path][$fingerprint] = ['rule' => $rule, 'message' => $message];
		$this->unused[$path][$fingerprint] = true;
	}


	public function save(string $file): void
	{
		$files = [];
		ksort($this->entries, SORT_STRING);
		foreach ($this->entries as $path => $violations) {
			foreach ($violations as $fingerprint => $entry) {
				$files[$path][] = $entry + ['fingerprint' => $fingerprint];
			}
		}

		$json = Json::encode(['files' => (object) $files], pretty: true) . "\n";
		if (@file_put_contents($file, $json) === false) { // @ - reported as exception
			throw new \RuntimeException("Cannot write the baseline file $file.");
		}
	}


	public function count(): int
	{
		return array_sum(array_map(count(...), $this->entries));
	}


	/**
	 * The result without the violations the baseline knows; those count as matched.
	 */
	public function filter(FileResult $result): FileResult
	{
		$known = $this->entries[$result->path] ?? [];
		$kept = [];
		foreach ($result->violations as $violation) {
			if (isset($known[$violation->fingerprint])) {
				unset($this->unused[$result->path][$violation->fingerprint]);
				$this->matched++;
			} else {
				$kept[] = $violation;
			}
		}

		if (count($kept) === count($result->violations)) {
			return $result;
		}

		$filtered = new FileResult(
			$result->path,
			$result->code,
			$result->output,
			$kept,
			$result->warnings,
			$result->error,
			$result->errorLine,
			$result->passes,
			$result->failure,
		);
		$filtered->written = $result->written;
		return $filtered;
	}


	/** Violations of the run the baseline silenced. */
	public function countMatched(): int
	{
		return $this->matched;
	}


	/** Entries no violation of the run matched. */
	public function countUnused(): int
	{
		return array_sum(array_map(count(...), $this->unused));
	}
}
