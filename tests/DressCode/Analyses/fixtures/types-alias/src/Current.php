<?php

namespace Acme\Alias;

class Current
{
}


class_alias(Current::class, Old::class);
