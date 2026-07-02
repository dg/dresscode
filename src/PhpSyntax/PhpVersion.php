<?php declare(strict_types=1);

namespace PhpSyntax;


/**
 * Version of PHP the code is written for, as major.minor.
 */
final readonly class PhpVersion implements \Stringable
{
	public function __construct(
		public int $major,
		public int $minor,
	) {
	}


	/** @param string $version  "8.2" or "8.2.1" */
	public static function fromString(string $version): self
	{
		if (!preg_match('~^(\d+)\.(\d+)(?:\.\d+)?$~D', $version, $m)) {
			throw new \InvalidArgumentException("Invalid PHP version '$version'.");
		}

		return new self((int) $m[1], (int) $m[2]);
	}


	/** @param int $id  as in PHP_VERSION_ID, 80200 */
	public static function fromId(int $id): self
	{
		return new self(intdiv($id, 10000), intdiv($id % 10000, 100));
	}


	public static function current(): self
	{
		return new self(PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
	}


	public function getId(): int
	{
		return $this->major * 10000 + $this->minor * 100;
	}


	public function isAtLeast(self|string $version): bool
	{
		$version = $version instanceof self ? $version : self::fromString($version);
		return $this->getId() >= $version->getId();
	}


	public function __toString(): string
	{
		return "$this->major.$this->minor";
	}
}
