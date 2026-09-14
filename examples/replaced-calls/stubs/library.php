<?php

namespace Acme;

class Playlist
{
	public function addTrack(string $name, ?string $label = null, bool $multiple = false): Settings
	{
		return new Settings;
	}


	public function addAlbum(string $name, ?string $label = null): Settings
	{
		return new Settings;
	}
}


class Settings
{
	public function getOption(string $key): mixed
	{
		return null;
	}
}


class Invoker
{
}
