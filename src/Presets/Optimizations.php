<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;


/**
 * Calls in the form PHP optimizes while compiling: a global function the compiler or opcache replaces is imported,
 * a function PHP calls without a frame takes its arguments positionally, and a call a construct says as well gives
 * way to the construct.
 */
#[PresetInfo('dresscode/optimizations', 'Calls in the form PHP optimizes while compiling')]
final class Optimizations implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [
			'name-fallback' => ['optimizedFunctions' => 'qualified', 'optimizedConstants' => 'qualified'],
			'no-conversion-functions' => true,
			'no-dirname-of-file' => true,
			'no-is-null' => true,
			'no-settype' => true,
			'optimized-call-notation' => true,
		]);
	}
}
