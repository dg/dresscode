<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;


/**
 * The canonical name of a function instead of its alias: `count()`, not `sizeof()`; `mktime()` without
 * arguments becomes `time()`.
 */
#[RuleInfo(
	'dresscode/no-alias-functions',
	Stage::Structure,
	description: 'Calls a function by its canonical name instead of an alias',
)]
final class NoAliasFunctionsRule extends Rule implements ConfigurableRule
{
	private const Sets = [
		'@internal' => [
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
			'close' => 'closedir',
			'doubleval' => 'floatval',
			'fputs' => 'fwrite',
			'get_required_files' => 'get_included_files',
			'ini_alter' => 'ini_set',
			'is_double' => 'is_float',
			'is_integer' => 'is_int',
			'is_long' => 'is_int',
			'is_real' => 'is_float',
			'is_writeable' => 'is_writable',
			'join' => 'implode',
			'key_exists' => 'array_key_exists',
			'magic_quotes_runtime' => 'set_magic_quotes_runtime',
			'pos' => 'current',
			'show_source' => 'highlight_file',
			'sizeof' => 'count',
			'strchr' => 'strstr',
			'user_error' => 'trigger_error',
		],
		'@IMAP' => [
			'imap_create' => 'imap_createmailbox',
			'imap_fetchtext' => 'imap_body',
			'imap_header' => 'imap_headerinfo',
			'imap_listmailbox' => 'imap_list',
			'imap_listsubscribed' => 'imap_lsub',
			'imap_rename' => 'imap_renamemailbox',
			'imap_scan' => 'imap_listscan',
			'imap_scanmailbox' => 'imap_listscan',
		],
		'@ldap' => [
			'ldap_close' => 'ldap_unbind',
			'ldap_modify' => 'ldap_mod_replace',
		],
		'@mysqli' => [
			'mysqli_execute' => 'mysqli_stmt_execute',
			'mysqli_set_opt' => 'mysqli_options',
			'mysqli_escape_string' => 'mysqli_real_escape_string',
		],
		'@pg' => [
			'pg_exec' => 'pg_query',
		],
		'@oci' => [
			'oci_free_cursor' => 'oci_free_statement',
		],
		'@odbc' => [
			'odbc_do' => 'odbc_exec',
			'odbc_field_precision' => 'odbc_field_len',
		],
		'@mbreg' => [
			'mbereg' => 'mb_ereg',
			'mbereg_match' => 'mb_ereg_match',
			'mbereg_replace' => 'mb_ereg_replace',
			'mbereg_search' => 'mb_ereg_search',
			'mbereg_search_getpos' => 'mb_ereg_search_getpos',
			'mbereg_search_getregs' => 'mb_ereg_search_getregs',
			'mbereg_search_init' => 'mb_ereg_search_init',
			'mbereg_search_pos' => 'mb_ereg_search_pos',
			'mbereg_search_regs' => 'mb_ereg_search_regs',
			'mbereg_search_setpos' => 'mb_ereg_search_setpos',
			'mberegi' => 'mb_eregi',
			'mberegi_replace' => 'mb_eregi_replace',
			'mbregex_encoding' => 'mb_regex_encoding',
			'mbsplit' => 'mb_split',
		],
		'@openssl' => [
			'openssl_get_publickey' => 'openssl_pkey_get_public',
			'openssl_get_privatekey' => 'openssl_pkey_get_private',
		],
		'@sodium' => [
			'sodium_crypto_scalarmult_base' => 'sodium_crypto_box_publickey_from_secretkey',
		],
		'@exif' => [
			'read_exif_data' => 'exif_read_data',
		],
		'@ftp' => [
			'ftp_quit' => 'ftp_close',
		],
		'@posix' => [
			'posix_errno' => 'posix_get_last_error',
		],
		'@pcntl' => [
			'pcntl_errno' => 'pcntl_get_last_error',
		],
		'@time' => [
			'mktime' => 'time',
			'gmmktime' => 'time',
		],
	];

	/** @var array<string, string>  alias → canonical name */
	private array $aliases = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'sets' => Expect::listOf(Expect::anyOf('@all', ...array_keys(self::Sets)))
				->default(['@internal', '@IMAP', '@pg'])
				->description('Groups of aliases to replace: @all, @internal (core functions), @IMAP, @ldap, @mysqli, @pg, @oci, @odbc, @mbreg, @openssl, @sodium, @exif, @ftp, @posix, @pcntl, @time (mktime() without arguments)'),
		]);
	}


	public function configure(array $options): void
	{
		$this->aliases = [];
		foreach (self::Sets as $set => $aliases) {
			if (in_array('@all', $options['sets'], strict: true) || in_array($set, $options['sets'], strict: true)) {
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
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node)
		) {
			return;
		}

		$alias = strtolower($node->name->getParts()[0]);
		$canonical = $this->aliases[$alias] ?? null;
		if (
			$canonical === null
			|| ($canonical === 'time' && !$node->args->args->isEmpty())
			|| !$context->report($node->name, "The function $alias() is an alias of $canonical()")
		) {
			return;
		}

		$old = $node->name->token;
		$token = new Token($old->kind, ($node->name->getKind() === NameKind::FullyQualified ? '\\' : '') . $canonical);
		$token->setLeadingTrivia($old->leadingTrivia);
		$token->setTrailingTrivia($old->trailingTrivia);
		$node->name->setToken($token);
	}
}
