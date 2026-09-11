<?php

namespace Acme\Mail;

/** @deprecated use Acme\Mail\Transport */
interface ITransport
{
}


interface Transport
{
}


/** @deprecated use MessageRenderer */
interface IMessageRenderer
{
}


interface MessageRenderer
{
}


class Message
{
	public const Plain = 'plain';
}


namespace Acme\Http;

/** @deprecated use \Acme\Dns\Resolver instead. */
interface IResolver
{
}


/** @deprecated */
class Gone
{
}


/** @deprecated use Acme\Missing\Replacement */
class Orphan
{
}


/** @deprecated the lookup was rewritten, see the guide */
class Explained
{
}


namespace Acme\Dns;

interface Resolver
{
}


namespace Acme\Utils;

/** @deprecated use Acme\Printable */
interface IPrintable
{
}


namespace Acme;

interface Printable
{
}
