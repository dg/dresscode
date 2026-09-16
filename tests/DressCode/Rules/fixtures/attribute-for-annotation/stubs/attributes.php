<?php

namespace Acme\Validation;

#[\Attribute]
class Size
{
	public function __construct(?int $min = null, ?int $max = null, ?string $message = null)
	{
	}
}


#[\Attribute]
class Required
{
}


#[\Attribute]
class Pattern
{
	public function __construct(string $regex, ?string $message = null)
	{
	}
}


#[\Attribute]
class Each
{
	public function __construct(array $constraints = [])
	{
	}
}


class Group
{
}


namespace Acme\Http\Attribute;

#[\Attribute]
class Endpoint
{
}


namespace Acme\Security\Attribute;

#[\Attribute]
class RequiresRole
{
}
