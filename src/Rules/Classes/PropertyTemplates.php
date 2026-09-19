<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;


/**
 * What replaced-calls writes instead of a property: the expression a read becomes and the one an assignment does,
 * `$value` standing for what is assigned. A side without an expression is reported and left as it is.
 * @internal
 */
final readonly class PropertyTemplates
{
	public function __construct(
		public ?CallTemplate $get,
		public ?CallTemplate $set,
	) {
	}
}
