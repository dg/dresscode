<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use function in_array;


/**
 * The canonical name of a function instead of its alias: `count()`, not `sizeof()`; with the set `time`,
 * `mktime()` and `gmmktime()` without arguments become `time()`.
 */
#[RuleInfo(
	'dresscode/no-alias-functions',
	Stage::Structure,
	description: 'Calls a function by its canonical name instead of an alias',
	group: Group::Cleanup,
)]
final class NoAliasFunctionsRule extends NodeRule implements ConfigurableRule
{
	private const Sets = [
		'internal' => [
			'diskfreespace' => 'disk_free_space',
			'dns_check_record' => 'checkdnsrr',
			'dns_get_mx' => 'getmxrr',
			'session_commit' => 'session_write_close',
			'stream_register_wrapper' => 'stream_wrapper_register',
			'set_file_buffer' => 'stream_set_write_buffer',
			'socket_set_blocking' => 'stream_set_blocking',
			'socket_get_status' => 'stream_get_meta_data',
			'socket_set_timeout' => 'stream_set_timeout',
			'socket_getopt' => 'socket_get_option',
			'socket_setopt' => 'socket_set_option',
			'chop' => 'rtrim',
			'doubleval' => 'floatval',
			'fputs' => 'fwrite',
			'get_required_files' => 'get_included_files',
			'ini_alter' => 'ini_set',
			'is_double' => 'is_float',
			'is_integer' => 'is_int',
			'is_long' => 'is_int',
			'is_writeable' => 'is_writable',
			'join' => 'implode',
			'key_exists' => 'array_key_exists',
			'pos' => 'current',
			'show_source' => 'highlight_file',
			'sizeof' => 'count',
			'strchr' => 'strstr',
			'user_error' => 'trigger_error',
		],
		'imap' => [
			'imap_create' => 'imap_createmailbox',
			'imap_fetchtext' => 'imap_body',
			'imap_listmailbox' => 'imap_list',
			'imap_listsubscribed' => 'imap_lsub',
			'imap_rename' => 'imap_renamemailbox',
			'imap_scan' => 'imap_listscan',
			'imap_scanmailbox' => 'imap_listscan',
		],
		'ldap' => [
			'ldap_close' => 'ldap_unbind',
			'ldap_modify' => 'ldap_mod_replace',
		],
		'mysqli' => [
			'mysqli_execute' => 'mysqli_stmt_execute',
			'mysqli_set_opt' => 'mysqli_options',
			'mysqli_escape_string' => 'mysqli_real_escape_string',
		],
		'pg' => [
			'pg_exec' => 'pg_query',
		],
		'oci' => [
			'oci_free_cursor' => 'oci_free_statement',
		],
		'odbc' => [
			'odbc_do' => 'odbc_exec',
			'odbc_field_precision' => 'odbc_field_len',
		],
		'openssl' => [
			'openssl_get_publickey' => 'openssl_pkey_get_public',
			'openssl_get_privatekey' => 'openssl_pkey_get_private',
		],
		'sodium' => [
			'sodium_crypto_scalarmult_base' => 'sodium_crypto_box_publickey_from_secretkey',
		],
		'ftp' => [
			'ftp_quit' => 'ftp_close',
		],
		'posix' => [
			'posix_errno' => 'posix_get_last_error',
		],
		'pcntl' => [
			'pcntl_errno' => 'pcntl_get_last_error',
		],
		'time' => [
			'mktime' => 'time',
			'gmmktime' => 'time',
		],
	];

	/** @var array<string, string>  alias => canonical name */
	private array $aliases = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'sets' => Expect::listOf(Expect::anyOf('all', ...array_keys(self::Sets)))
				->default(['internal', 'imap', 'pg'])
				->description('Groups of aliases to replace: all, internal (core functions), imap, ldap, mysqli, pg, oci, odbc, openssl, sodium, ftp, posix, pcntl, time (mktime() without arguments)'),
		]);
	}


	public function configure(array $options): void
	{
		$this->aliases = [];
		foreach (self::Sets as $set => $aliases) {
			if (in_array('all', $options['sets'], true) || in_array($set, $options['sets'], true)) {
				$this->aliases += $aliases;
			}
		}
	}


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$resolver->isGlobalFunctionCall($node)
		) {
			return;
		}

		// the function the name reaches, which an import may call by another name
		$alias = strtolower($resolver->resolveFunction($node->name));
		$canonical = $this->aliases[$alias] ?? null;
		if (
			$canonical === null
			|| ($canonical === 'time' && !$node->arguments->items->isEmpty())
			|| !$context->report(
				$node->name,
				"The function $alias() is an alias of $canonical()" . ($uncertainty = NodeHelpers::findUncertainty($node, $context)),
				risky: $uncertainty !== null,
			)
		) {
			return;
		}

		$node->name->text = NodeHelpers::spellGlobalFunction($canonical, $node->name, $context);
	}
}
