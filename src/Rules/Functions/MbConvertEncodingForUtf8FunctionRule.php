<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * `utf8_encode()` and `utf8_decode()`, deprecated in PHP 8.2, convert between ISO-8859-1 and UTF-8 and
 * nothing else, which is what `mb_convert_encoding()` says out loud. The rule runs where the code targets
 * 8.2 or later, so that a project on an older version is not told about a deprecation it has not reached.
 *
 * The fix is risky: it reaches for mbstring, which the code does not say is there, and a build without the
 * extension answers the new call with an error instead of the deprecation it answered before.
 */
#[RuleInfo(
	'dresscode/mb-convert-encoding-for-utf8-function',
	Stage::Structure,
	description: 'Replaces the deprecated utf8_encode() and utf8_decode() with mb_convert_encoding()',
	group: Group::Deprecations,
	risky: true,
)]
final class MbConvertEncodingForUtf8FunctionRule extends NodeRule
{
	private const Conversions = [
		'utf8_encode' => "'UTF-8', 'ISO-8859-1'",
		'utf8_decode' => "'ISO-8859-1'",
	];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$argument = $node instanceof Expression\FunctionCallNode ? self::readSingleArgument($node, $context) : null;
		if (
			$argument === null
			|| version_compare($context->getPhpVersion(), '8.2', '<')
			|| $node->hasComment()
		) {
			return;
		}

		$name = $node->name;
		assert($name instanceof NameNode);
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach (self::Conversions as $function => $arguments) {
			if (!$resolver->isGlobalFunctionCall($node, $function)) {
				continue;
			}

			$uncertainty = NodeHelpers::findUncertainty($node, $context);
			if (!$context->report($node, "The deprecated $function() call must be written with mb_convert_encoding()" . $uncertainty)) {
				return;
			}

			$spelling = NodeHelpers::spellGlobalFunction('mb_convert_encoding', $name, $context);
			$call = (new Parser)->parseExpression("$spelling(0, $arguments)");
			assert($call instanceof Expression\FunctionCallNode);
			$first = $call->arguments->items->getItems()[0];
			assert($first instanceof ArgumentNode);
			$first->value->replaceWith($argument->withoutEdgeTrivia());
			$node->replaceWith($call);
			return;
		}
	}


	/** The only argument of a call of a global function, null where the call is of another kind. */
	private static function readSingleArgument(Expression\FunctionCallNode $call, RuleContext $context): ?ExpressionNode
	{
		$argument = $call->arguments->items->getItems()[0] ?? null;
		return $call->name instanceof NameNode
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call)
			&& count($call->arguments->items) === 1
			&& $argument instanceof ArgumentNode
			&& !$argument->name && !$argument->ampersand && !$argument->ellipsis
			? $argument->value
			: null;
	}
}
