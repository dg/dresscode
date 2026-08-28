<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\ConfigurableRule;
use DressCode\NodeHelpers;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\HeredocNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function in_array;


/**
 * Two string literals of the same kind concatenated on one line are one literal. Single-quoted ones are
 * joined; double-quoted ones are only reported, because an escape sequence could span the joint.
 * Concatenation with an empty string converts to string and nothing else, so `$x . ''` is `(string) $x`,
 * and inside a longer concatenation the empty literal simply goes away.
 */
#[RuleInfo(
	'dresscode/useless-string-concat',
	Stage::Structure,
	description: 'Joins two string literals concatenated on one line and drops concatenation with an empty string',
)]
final class UselessStringConcatRule extends Rule implements ConfigurableRule
{
	private bool $allowMultiline = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'allowMultiline' => Expect::bool(true)->description('Literals on different lines may stay concatenated'),
		]);
	}


	public function configure(array $options): void
	{
		$this->allowMultiline = $options['allowMultiline'];
	}


	public function getVisitedTypes(): array
	{
		return [BinaryNode::class];
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryNode || !$node->operator->is('.')) {
			return;
		}

		$left = $node->left;
		$right = $node->right;
		if (self::isEmptyString($left) || self::isEmptyString($right)) {
			$this->dropEmpty($node, self::isEmptyString($left) ? $right : $left, $context);
			return;
		}

		if (
			!$left instanceof StringNode
			|| !$right instanceof StringNode
			|| $left->token->kind !== TokenKind::ConstantEncapsedString
			|| $right->token->kind !== TokenKind::ConstantEncapsedString
		) {
			return;
		}

		$l = $left->token->text;
		$r = $right->token->text;
		$joint = substr($l, -2, 1) . substr($r, 1, 1);
		if (
			$l[0] !== $r[0]
			|| $joint === '?>'
			|| $joint === '<?'
			|| ($this->allowMultiline && $left->token->getLine() !== $right->token->getLine())
			|| !$context->report($node->operator, 'Useless concatenation of two string literals')
			|| $l[0] !== "'"
			|| $node->hasComment()
		) {
			return;
		}

		$node->replaceWith((new Parser)->parseExpression("'" . substr($l, 1, -1) . substr($r, 1, -1) . "'"));
	}


	private static function isEmptyString(ExpressionNode $expr): bool
	{
		return $expr instanceof StringNode && in_array($expr->token->text, ["''", '""'], strict: true);
	}


	/**
	 * The other operand alone when it is a string already or part of a longer concatenation, else cast
	 * to string, in parentheses when it is not a primary expression.
	 */
	private function dropEmpty(BinaryNode $node, ExpressionNode $other, RuleContext $context): void
	{
		if (!$context->report($node->operator, 'Useless concatenation with an empty string') || $node->hasComment()) {
			return;
		}

		$copy = clone $other;
		$copy->getFirstToken()?->setLeadingTrivia([]);
		$copy->getLastToken()?->setTrailingTrivia([]);
		$isString = $other instanceof StringNode
			|| $other instanceof InterpolatedStringNode
			|| $other instanceof HeredocNode
			|| ($other instanceof BinaryNode && $other->operator->is('.'))
			|| ($other instanceof CastNode && $other->cast->kind === TokenKind::StringCast)
			|| ($node->parent instanceof BinaryNode && $node->parent->operator->is('.'));
		if ($isString) {
			$node->replaceWith($copy);
			return;
		}

		$cast = (new Parser)->parseExpression(NodeHelpers::isPrimary($other) ? '(string) 0' : '(string) (0)');
		assert($cast instanceof CastNode);
		($cast->expr instanceof ParenthesizedNode ? $cast->expr->expr : $cast->expr)->replaceWith($copy);
		$node->replaceWith($cast);
	}
}
