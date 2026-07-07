<?php declare(strict_types=1);

namespace DressCode;


#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class PresetInfo
{
	public function __construct(
		public string $name,
		public string $description = '',
	) {
	}


	/**
	 * Reads the attribute of a preset class.
	 * @param  Preset|class-string<Preset>  $preset
	 * @throws ConfigurationException  when the class has no PresetInfo
	 */
	public static function of(Preset|string $preset): self
	{
		static $cache = [];
		$class = $preset instanceof Preset ? $preset::class : $preset;
		return $cache[$class] ??= ((new \ReflectionClass($class))->getAttributes(self::class)[0] ?? null)?->newInstance()
			?? throw new ConfigurationException("Preset $class has no #[PresetInfo] attribute.");
	}
}
