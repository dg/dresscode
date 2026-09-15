<?php

// the declarations the types of tests/DressCode/Rules/upgrading-interplay.phpt are read from

namespace Old;

interface Router
{
	public const Secured = 1;
	public const OneWay = 2;
}


class Legacy
{
	public const OldName = 1;
}


namespace Fresh;

interface Router
{
	public const OneWay = 2;
}


class Modern
{
	public const NewName = 1;
}
