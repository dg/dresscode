<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;


/**
 * The canonical name of a function instead of its alias: `count()`, not `sizeof()`; with the set `time`,
 * `mktime()` and `gmmktime()` without arguments become `time()`.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoAliasFunctionsRule extends NodeRule
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

	/** @var array<lowercase-string, string>  alias => canonical name */
	private array $aliases = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('cleanup.aliasFunctions', new Names([
				'all' => 'every set below',
				'internal' => 'the core functions, `sizeof()` is `count()`, `join()` is `implode()`',
				'imap' => '`imap_*`',
				'ldap' => '`ldap_*`',
				'mysqli' => '`mysqli_*`',
				'pg' => '`pg_exec()` is `pg_query()`',
				'oci' => '`oci_*`',
				'odbc' => '`odbc_*`',
				'openssl' => '`openssl_*`',
				'sodium' => '`sodium_*`',
				'ftp' => '`ftp_quit()` is `ftp_close()`',
				'posix' => '`posix_errno()` is `posix_get_last_error()`',
				'pcntl' => '`pcntl_errno()` is `pcntl_get_last_error()`',
				'time' => '`mktime()` and `gmmktime()` without arguments are `time()`',
			]), 'The sets of functions whose aliases are written by their canonical names'),
		];
	}


	public function configure(Values $values): void
	{
		$sets = $values->get('cleanup.aliasFunctions')->getNames();
		$this->aliases = [];
		foreach (self::Sets as $set => $aliases) {
			if (in_array('all', $sets, true) || in_array($set, $sets, true)) {
				$this->aliases += $aliases;
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			// the function the name reaches, which an import may call by another name
			|| ($alias = GlobalCalls::findFunction($node, $this->aliases, $context)) === null
		) {
			return;
		}

		$canonical = $this->aliases[$alias];
		if (
			($canonical === 'time' && !$node->arguments->items->isEmpty())
			|| !$context->report(
				$node->name,
				"The alias `$alias()` must be written `$canonical()`.",
				risk: ($uncertainty = GlobalCalls::findUncertainty($node, $context)) === null ? null : Risk::NameUncertain,
				because: $uncertainty,
			)
		) {
			return;
		}

		$node->name->text = CodeWriter::spellFunction($canonical, $node->name, $context);
	}
}
