<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\ConfigurationException;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use function count, is_array, is_string;


/**
 * Known violations left unreported, by file and fingerprint; generated from a run and kept in a file of
 * the format the configuration is written in. A run marks the entries it matched, so the stale ones can
 * be reported.
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
		if (!is_file($file)) {
			throw new ConfigurationException("Cannot read the baseline file $file.");
		}

		if (self::isNeon($file)) {
			try {
				$data = Neon::decode((string) @file_get_contents($file)); // @ - a file that vanished meanwhile decodes as empty
			} catch (NeonException $e) {
				throw new ConfigurationException("The baseline file $file is not valid NEON: {$e->getMessage()}", previous: $e);
			}
		} else {
			$data = require $file;
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


	/** In the format of the file name: the one the configuration is written in, so there is no third format. */
	public function save(string $file): void
	{
		$files = [];
		ksort($this->entries, SORT_STRING);
		foreach ($this->entries as $path => $violations) {
			foreach ($violations as $fingerprint => $entry) {
				$files[$path][] = $entry + ['fingerprint' => $fingerprint];
			}
		}

		$content = self::isNeon($file)
			? Neon::encode(['files' => $files], blockMode: true)
			: "<?php declare(strict_types=1);\n\nreturn " . self::export(['files' => $files], "\n") . ";\n";
		if (@file_put_contents($file, $content) === false) { // @ - reported as exception
			throw new \RuntimeException("Cannot write the baseline file $file.");
		}
	}


	/**
	 * The format follows the extension, as it does for the configuration; anything else is a typo in the
	 * configured name, not a format to guess at.
	 * @throws ConfigurationException
	 */
	private static function isNeon(string $file): bool
	{
		return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
			'neon' => true,
			'php' => false,
			default => throw new ConfigurationException("The baseline file $file must be a .neon or a .php file."),
		};
	}


	/** @param array<int|string, mixed> $value */
	private static function export(array $value, string $indent): string
	{
		$items = [];
		foreach ($value as $key => $item) {
			$items[] = $indent . "\t" . (is_string($key) ? var_export($key, return: true) . ' => ' : '')
				. (is_array($item) ? self::export($item, $indent . "\t") : var_export($item, return: true)) . ',';
		}

		return $items ? '[' . implode('', $items) . "$indent]" : '[]';
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
