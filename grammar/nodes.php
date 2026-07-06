<?php declare(strict_types=1);

/**
 * Schema of the node classes generated into src/PhpSyntax/Nodes/** by build.php.
 *
 * Key: class relative to PhpSyntax\Nodes. Slots are listed in source order; a slot type is Token or a node class
 * (relative to PhpSyntax\Nodes, or one of the base classes in PhpSyntax), optionally nullable (?), a union (A|B),
 * or a list (NodeList<T>, SeparatedNodeList<T>). The parent defaults by directory: Expression and Scalar extend
 * ExpressionNode, Statement extends StatementNode, Type extends TypeNode, Member extends MemberNode, the rest Node.
 * 'traits' lists handwritten traits (relative to PhpSyntax\Nodes) with the methods of the class;
 * 'manual' marks a handwritten class the schema only documents.
 */

return [
	// ---------- names, identifiers, arguments, parameters ----------

	'NameNode' => [
		'description' => 'Name of a class, function, constant or namespace: one token of any kind, including keywords the grammar accepts as names (static, array, readonly).',
		'traits' => ['NameQueries'],
		'slots' => ['token' => 'Token'],
	],
	'IdentifierNode' => [
		'description' => 'Identifier of a member, label, hook or alias: one token of any kind, including reserved words.',
		'slots' => ['token' => 'Token'],
	],
	'ArgumentListNode' => [
		'description' => 'Parenthesized arguments of a call, instantiation, attribute or exit.',
		'slots' => [
			'openParen' => 'Token',
			'args' => 'SeparatedNodeList<ArgumentNode|VariadicPlaceholderNode>',
			'closeParen' => 'Token',
		],
	],
	'ArgumentNode' => [
		'description' => 'Argument of a call: optionally named, by reference or unpacked.',
		'slots' => [
			'name' => '?IdentifierNode',
			'colon' => '?Token',
			'byRef' => '?Token',
			'ellipsis' => '?Token',
			'expr' => 'ExpressionNode',
		],
	],
	'VariadicPlaceholderNode' => [
		'description' => 'The ... placeholder of a first-class callable: f(...).',
		'slots' => ['ellipsis' => 'Token'],
	],
	'ParameterNode' => [
		'description' => 'Parameter of a function, method, closure or hook; with modifiers it promotes a property.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'type' => '?TypeNode',
			'byRef' => '?Token',
			'ellipsis' => '?Token',
			'var' => 'Expression\VariableNode',
			'equals' => '?Token',
			'default' => '?ExpressionNode',
			'openBrace' => '?Token',
			'hooks' => '?NodeList<Member\PropertyHookNode>',
			'closeBrace' => '?Token',
		],
	],
	'AttributeGroupNode' => [
		'description' => 'One #[...] group of attributes.',
		'slots' => [
			'openAttribute' => 'Token',
			'attributes' => 'SeparatedNodeList<AttributeNode>',
			'closeBracket' => 'Token',
		],
	],
	'AttributeNode' => [
		'description' => 'Attribute with optional arguments.',
		'slots' => [
			'name' => 'NameNode',
			'args' => '?ArgumentListNode',
		],
	],
	'ModifiersNode' => [
		'manual' => true,
		'description' => 'Modifier keywords in source order; may be empty.',
		'slots' => [],
	],
	'FileNode' => [
		'manual' => true,
		'description' => 'Root of the tree.',
		'slots' => [
			'stmts' => 'NodeList<StatementNode>',
			'eof' => 'Token',
		],
	],
	'AnonymousClassNode' => [
		'implements' => ['ClassLikeNode'],
		'description' => 'Anonymous class in a new expression: new class(...) extends A implements B { ... }.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'classKeyword' => 'Token',
			'args' => '?ArgumentListNode',
			'extendsKeyword' => '?Token',
			'extends' => '?NameNode',
			'implementsKeyword' => '?Token',
			'implements' => '?SeparatedNodeList<NameNode>',
			'openBrace' => 'Token',
			'members' => 'NodeList<MemberNode>',
			'closeBrace' => 'Token',
		],
	],

	// ---------- items of lists ----------

	'ArrayItemNode' => [
		'description' => 'Item of an array literal or a destructuring list: value with optional key, by reference or unpacked.',
		'slots' => [
			'key' => '?ExpressionNode',
			'doubleArrow' => '?Token',
			'byRef' => '?Token',
			'ellipsis' => '?Token',
			'value' => 'ExpressionNode',
		],
	],
	'EmptyArrayItemNode' => [
		'description' => 'Skipped item of a destructuring list ([, $b] = $x); has no tokens.',
		'slots' => [],
	],
	'MatchArmNode' => [
		'description' => 'Arm of a match expression: conditions or default, and the result.',
		'slots' => [
			'conds' => '?SeparatedNodeList<ExpressionNode>',
			'defaultKeyword' => '?Token',
			'defaultComma' => '?Token',
			'doubleArrow' => 'Token',
			'body' => 'ExpressionNode',
		],
	],
	'ClosureUsesNode' => [
		'description' => 'The use (...) clause of a closure.',
		'slots' => [
			'useKeyword' => 'Token',
			'openParen' => 'Token',
			'vars' => 'SeparatedNodeList<ClosureUseNode>',
			'closeParen' => 'Token',
		],
	],
	'ClosureUseNode' => [
		'description' => 'Variable captured by a closure, optionally by reference.',
		'slots' => [
			'byRef' => '?Token',
			'var' => 'Expression\VariableNode',
		],
	],
	'ElseIfNode' => [
		'description' => 'elseif branch, in either syntax.',
		'slots' => [
			'elseifKeyword' => 'Token',
			'openParen' => 'Token',
			'cond' => 'ExpressionNode',
			'closeParen' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
		],
	],
	'ElseNode' => [
		'description' => 'else branch, in either syntax; else if is an else with an if statement as the body.',
		'slots' => [
			'elseKeyword' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
		],
	],
	'CaseNode' => [
		'description' => 'case or default of a switch; the separator is a colon or a semicolon.',
		'slots' => [
			'caseKeyword' => 'Token',
			'cond' => '?ExpressionNode',
			'separator' => 'Token',
			'stmts' => 'NodeList<StatementNode>',
		],
	],
	'CatchNode' => [
		'description' => 'catch clause with one or more types and an optional variable.',
		'slots' => [
			'catchKeyword' => 'Token',
			'openParen' => 'Token',
			'types' => 'SeparatedNodeList<NameNode>',
			'var' => '?Expression\VariableNode',
			'closeParen' => 'Token',
			'body' => 'Statement\BlockNode',
		],
	],
	'FinallyNode' => [
		'description' => 'finally clause.',
		'slots' => [
			'finallyKeyword' => 'Token',
			'body' => 'Statement\BlockNode',
		],
	],
	'DeclareItemNode' => [
		'description' => 'Directive of a declare statement: strict_types=1.',
		'slots' => [
			'name' => 'IdentifierNode',
			'equals' => 'Token',
			'value' => 'ExpressionNode',
		],
	],
	'StaticVarNode' => [
		'description' => 'Variable of a static statement with an optional initializer.',
		'slots' => [
			'var' => 'Expression\VariableNode',
			'equals' => '?Token',
			'default' => '?ExpressionNode',
		],
	],
	'UseItemNode' => [
		'description' => 'Imported name with an optional alias; the type (function, const) appears only inside a group use.',
		'slots' => [
			'type' => '?Token',
			'name' => 'NameNode',
			'asKeyword' => '?Token',
			'alias' => '?IdentifierNode',
		],
	],
	'ConstItemNode' => [
		'description' => 'Constant of a const statement or a class constant declaration.',
		'slots' => [
			'name' => 'IdentifierNode',
			'equals' => 'Token',
			'value' => 'ExpressionNode',
		],
	],

	// ---------- expressions ----------

	'Expression\VariableNode' => [
		'description' => 'Variable: $a, $$a, ${expr}; inside a string also a bare name in ${name}.',
		'slots' => [
			'dollar' => '?Token',
			'openBrace' => '?Token',
			'name' => 'Token|ExpressionNode',
			'closeBrace' => '?Token',
		],
	],
	'Expression\ArrayDimFetchNode' => [
		'description' => 'Array or string offset access: $a[$i], $a[].',
		'slots' => [
			'var' => 'ExpressionNode',
			'openBracket' => 'Token',
			'dim' => '?ExpressionNode',
			'closeBracket' => 'Token',
		],
	],
	'Expression\PropertyFetchNode' => [
		'description' => 'Property access with -> or ?->; the name may be an identifier, a variable or a braced expression.',
		'slots' => [
			'object' => 'ExpressionNode',
			'operator' => 'Token',
			'openBrace' => '?Token',
			'name' => 'IdentifierNode|ExpressionNode',
			'closeBrace' => '?Token',
		],
	],
	'Expression\StaticPropertyFetchNode' => [
		'description' => 'Static property access: A::$b.',
		'slots' => [
			'class' => 'NameNode|ExpressionNode',
			'doubleColon' => 'Token',
			'name' => 'Expression\VariableNode',
		],
	],
	'Expression\ClassConstantFetchNode' => [
		'description' => 'Class constant access: A::B, A::class, A::{expr}.',
		'slots' => [
			'class' => 'NameNode|ExpressionNode',
			'doubleColon' => 'Token',
			'openBrace' => '?Token',
			'name' => 'IdentifierNode|ExpressionNode',
			'closeBrace' => '?Token',
		],
	],
	'Expression\ConstantFetchNode' => [
		'description' => 'Constant access by name: FOO, \Foo\BAR, true.',
		'slots' => ['name' => 'NameNode'],
	],
	'Expression\FunctionCallNode' => [
		'description' => 'Function call by name or on an expression.',
		'slots' => [
			'name' => 'NameNode|ExpressionNode',
			'args' => 'ArgumentListNode',
		],
	],
	'Expression\MethodCallNode' => [
		'description' => 'Method call with -> or ?->.',
		'slots' => [
			'object' => 'ExpressionNode',
			'operator' => 'Token',
			'openBrace' => '?Token',
			'name' => 'IdentifierNode|ExpressionNode',
			'closeBrace' => '?Token',
			'args' => 'ArgumentListNode',
		],
	],
	'Expression\StaticCallNode' => [
		'description' => 'Static method call: A::b(), $a::b(), A::{expr}().',
		'slots' => [
			'class' => 'NameNode|ExpressionNode',
			'doubleColon' => 'Token',
			'openBrace' => '?Token',
			'name' => 'IdentifierNode|ExpressionNode',
			'closeBrace' => '?Token',
			'args' => 'ArgumentListNode',
		],
	],
	'Expression\NewNode' => [
		'description' => 'Instantiation of a named, dynamic or anonymous class.',
		'slots' => [
			'newKeyword' => 'Token',
			'class' => 'NameNode|ExpressionNode|AnonymousClassNode',
			'args' => '?ArgumentListNode',
		],
	],
	'Expression\ArrayNode' => [
		'description' => 'Array literal in either syntax: [...] or array(...); on the left of an assignment it destructures.',
		'slots' => [
			'arrayKeyword' => '?Token',
			'openDelimiter' => 'Token',
			'items' => 'SeparatedNodeList<ArrayItemNode|EmptyArrayItemNode>',
			'closeDelimiter' => 'Token',
		],
	],
	'Expression\ListNode' => [
		'description' => 'Destructuring with list(...).',
		'slots' => [
			'listKeyword' => 'Token',
			'openParen' => 'Token',
			'items' => 'SeparatedNodeList<ArrayItemNode|EmptyArrayItemNode>',
			'closeParen' => 'Token',
		],
	],
	'Expression\AssignNode' => [
		'description' => 'Assignment, plain or compound (+=, ??=); the operator token tells which.',
		'slots' => [
			'var' => 'ExpressionNode',
			'operator' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\AssignRefNode' => [
		'description' => 'Assignment by reference: $a = &$b.',
		'slots' => [
			'var' => 'ExpressionNode',
			'equals' => 'Token',
			'ampersand' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\BinaryNode' => [
		'description' => 'Binary operation; the operator token tells which (arithmetic, comparison, logical, bitwise, concatenation, coalesce, pipe).',
		'slots' => [
			'left' => 'ExpressionNode',
			'operator' => 'Token',
			'right' => 'ExpressionNode',
		],
	],
	'Expression\UnaryNode' => [
		'description' => 'Prefix operation: +, -, !, ~, @, ++, --.',
		'slots' => [
			'operator' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\PostfixNode' => [
		'description' => 'Postfix increment or decrement.',
		'slots' => [
			'expr' => 'ExpressionNode',
			'operator' => 'Token',
		],
	],
	'Expression\CastNode' => [
		'description' => 'Type cast; the cast token keeps its spelling including inner whitespace: ( int ).',
		'slots' => [
			'cast' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\TernaryNode' => [
		'description' => 'Ternary conditional ($cond ? $if : $else) or the elvis form ($cond ?: $else).',
		'slots' => [
			'cond' => 'ExpressionNode',
			'question' => 'Token',
			'if' => '?ExpressionNode',
			'colon' => 'Token',
			'else' => 'ExpressionNode',
		],
	],
	'Expression\InstanceofNode' => [
		'description' => 'instanceof check against a name or a dynamic class.',
		'slots' => [
			'expr' => 'ExpressionNode',
			'instanceofKeyword' => 'Token',
			'class' => 'NameNode|ExpressionNode',
		],
	],
	'Expression\ParenthesizedNode' => [
		'description' => 'Expression in parentheses.',
		'slots' => [
			'openParen' => 'Token',
			'expr' => 'ExpressionNode',
			'closeParen' => 'Token',
		],
	],
	'Expression\IssetNode' => [
		'description' => 'isset(...) with one or more variables.',
		'slots' => [
			'issetKeyword' => 'Token',
			'openParen' => 'Token',
			'vars' => 'SeparatedNodeList<ExpressionNode>',
			'closeParen' => 'Token',
		],
	],
	'Expression\EmptyNode' => [
		'description' => 'empty(...).',
		'slots' => [
			'emptyKeyword' => 'Token',
			'openParen' => 'Token',
			'expr' => 'ExpressionNode',
			'closeParen' => 'Token',
		],
	],
	'Expression\EvalNode' => [
		'description' => 'eval(...).',
		'slots' => [
			'evalKeyword' => 'Token',
			'openParen' => 'Token',
			'expr' => 'ExpressionNode',
			'closeParen' => 'Token',
		],
	],
	'Expression\IncludeNode' => [
		'description' => 'include, include_once, require or require_once; the keyword token tells which.',
		'slots' => [
			'includeKeyword' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\ExitNode' => [
		'description' => 'exit or die with optional arguments.',
		'slots' => [
			'exitKeyword' => 'Token',
			'args' => '?ArgumentListNode',
		],
	],
	'Expression\PrintNode' => [
		'description' => 'print expression.',
		'slots' => [
			'printKeyword' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\YieldNode' => [
		'description' => 'yield, yield $value or yield $key => $value.',
		'slots' => [
			'yieldKeyword' => 'Token',
			'key' => '?ExpressionNode',
			'doubleArrow' => '?Token',
			'value' => '?ExpressionNode',
		],
	],
	'Expression\YieldFromNode' => [
		'description' => 'yield from expression.',
		'slots' => [
			'yieldFromKeyword' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\ThrowNode' => [
		'description' => 'throw expression.',
		'slots' => [
			'throwKeyword' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\CloneNode' => [
		'description' => 'clone expression; clone(...) with arguments is a function call.',
		'slots' => [
			'cloneKeyword' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\MatchNode' => [
		'description' => 'match expression.',
		'slots' => [
			'matchKeyword' => 'Token',
			'openParen' => 'Token',
			'cond' => 'ExpressionNode',
			'closeParen' => 'Token',
			'openBrace' => 'Token',
			'arms' => 'SeparatedNodeList<MatchArmNode>',
			'closeBrace' => 'Token',
		],
	],
	'Expression\ClosureNode' => [
		'description' => 'Anonymous function, optionally static, with captured variables.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'staticKeyword' => '?Token',
			'functionKeyword' => 'Token',
			'byRef' => '?Token',
			'openParen' => 'Token',
			'params' => 'SeparatedNodeList<ParameterNode>',
			'closeParen' => 'Token',
			'uses' => '?ClosureUsesNode',
			'colon' => '?Token',
			'returnType' => '?TypeNode',
			'body' => 'Statement\BlockNode',
		],
	],
	'Expression\ArrowFunctionNode' => [
		'description' => 'Arrow function fn(...) => expr, optionally static.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'staticKeyword' => '?Token',
			'fnKeyword' => 'Token',
			'byRef' => '?Token',
			'openParen' => 'Token',
			'params' => 'SeparatedNodeList<ParameterNode>',
			'closeParen' => 'Token',
			'colon' => '?Token',
			'returnType' => '?TypeNode',
			'doubleArrow' => 'Token',
			'expr' => 'ExpressionNode',
		],
	],
	'Expression\ShellExecNode' => [
		'description' => 'Command in backticks with interpolation.',
		'slots' => [
			'openBacktick' => 'Token',
			'parts' => 'NodeList<Scalar\InterpolatedStringPartNode|Scalar\InterpolationNode|ExpressionNode>',
			'closeBacktick' => 'Token',
		],
	],

	// ---------- scalars ----------

	'Scalar\IntegerNode' => [
		'description' => 'Integer literal in any base, kept as written.',
		'slots' => ['token' => 'Token'],
	],
	'Scalar\FloatNode' => [
		'description' => 'Floating-point literal, kept as written.',
		'slots' => ['token' => 'Token'],
	],
	'Scalar\StringNode' => [
		'description' => 'String literal without interpolation, quotes included; inside an interpolated string also a bare offset name ($a[key]).',
		'slots' => ['token' => 'Token'],
	],
	'Scalar\MagicConstantNode' => [
		'description' => 'Magic constant: __LINE__, __FILE__, __DIR__, __CLASS__, __TRAIT__, __METHOD__, __FUNCTION__, __PROPERTY__, __NAMESPACE__.',
		'slots' => ['token' => 'Token'],
	],
	'Scalar\InterpolatedStringNode' => [
		'description' => 'Double-quoted string with interpolated variables or expressions.',
		'slots' => [
			'openQuote' => 'Token',
			'parts' => 'NodeList<Scalar\InterpolatedStringPartNode|Scalar\InterpolationNode|ExpressionNode>',
			'closeQuote' => 'Token',
		],
	],
	'Scalar\HeredocNode' => [
		'description' => 'Heredoc or nowdoc; the closing delimiter keeps its indentation, the parts keep theirs.',
		'slots' => [
			'openDelimiter' => 'Token',
			'parts' => 'NodeList<Scalar\InterpolatedStringPartNode|Scalar\InterpolationNode|ExpressionNode>',
			'closeDelimiter' => 'Token',
		],
	],
	'Scalar\InterpolatedStringPartNode' => [
		'extends' => 'Node',
		'description' => 'Literal text between interpolations, whitespace included.',
		'slots' => ['text' => 'Token'],
	],
	'Scalar\InterpolationNode' => [
		'extends' => 'Node',
		'description' => 'Braced interpolation inside a string: {$expr} or ${name}.',
		'slots' => [
			'openBrace' => 'Token',
			'expr' => 'ExpressionNode',
			'closeBrace' => 'Token',
		],
	],

	// ---------- types ----------

	'Type\NamedTypeNode' => [
		'description' => 'Type given by a name: builtin (int, static, array, callable) or a class.',
		'slots' => ['name' => 'NameNode'],
	],
	'Type\NullableTypeNode' => [
		'description' => 'Nullable type: ?T.',
		'slots' => [
			'question' => 'Token',
			'type' => 'TypeNode',
		],
	],
	'Type\UnionTypeNode' => [
		'description' => 'Union type A|B; a member may be a parenthesized intersection (DNF).',
		'slots' => ['types' => 'SeparatedNodeList<TypeNode>'],
	],
	'Type\IntersectionTypeNode' => [
		'description' => 'Intersection type A&B, parenthesized inside a union.',
		'slots' => [
			'openParen' => '?Token',
			'types' => 'SeparatedNodeList<TypeNode>',
			'closeParen' => '?Token',
		],
	],

	// ---------- statements ----------

	'Statement\NamespaceNode' => [
		'description' => 'namespace declaration; after "namespace A;" the following statements are nested in it.',
		'slots' => [
			'namespaceKeyword' => 'Token',
			'name' => '?NameNode',
			'semicolon' => '?Token',
			'openBrace' => '?Token',
			'stmts' => 'NodeList<StatementNode>',
			'closeBrace' => '?Token',
		],
	],
	'Statement\UseNode' => [
		'description' => 'use import of classes, functions or constants.',
		'slots' => [
			'useKeyword' => 'Token',
			'type' => '?Token',
			'items' => 'SeparatedNodeList<UseItemNode>',
			'semicolon' => 'Token',
		],
	],
	'Statement\GroupUseNode' => [
		'description' => 'Group use import: use A\{B, C};.',
		'slots' => [
			'useKeyword' => 'Token',
			'type' => '?Token',
			'prefix' => 'NameNode',
			'namespaceSeparator' => 'Token',
			'openBrace' => 'Token',
			'items' => 'SeparatedNodeList<UseItemNode>',
			'closeBrace' => 'Token',
			'semicolon' => 'Token',
		],
	],
	'Statement\ConstNode' => [
		'description' => 'const statement outside a class.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'constKeyword' => 'Token',
			'items' => 'SeparatedNodeList<ConstItemNode>',
			'semicolon' => 'Token',
		],
	],
	'Statement\HaltCompilerNode' => [
		'description' => '__halt_compiler(); the rest of the file is the data token.',
		'slots' => [
			'haltKeyword' => 'Token',
			'openParen' => 'Token',
			'closeParen' => 'Token',
			'semicolon' => 'Token',
			'data' => '?Token',
		],
	],
	'Statement\InlineHtmlNode' => [
		'description' => 'Text outside PHP tags, including a BOM or a hashbang line.',
		'slots' => ['html' => 'Token'],
	],
	'Statement\EmptyStatementNode' => [
		'description' => 'Bare semicolon, or a close tag after a terminated statement.',
		'slots' => ['semicolon' => 'Token'],
	],
	'Statement\ExpressionStatementNode' => [
		'description' => 'Expression as a statement.',
		'slots' => [
			'expr' => 'ExpressionNode',
			'semicolon' => 'Token',
		],
	],
	'Statement\BlockNode' => [
		'description' => 'Statements in braces.',
		'slots' => [
			'openBrace' => 'Token',
			'stmts' => 'NodeList<StatementNode>',
			'closeBrace' => 'Token',
		],
	],
	'Statement\IfNode' => [
		'description' => 'if statement in either syntax; the body is a statement, the alternative syntax fills stmts.',
		'slots' => [
			'ifKeyword' => 'Token',
			'openParen' => 'Token',
			'cond' => 'ExpressionNode',
			'closeParen' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
			'elseifs' => 'NodeList<ElseIfNode>',
			'else' => '?ElseNode',
			'endKeyword' => '?Token',
			'semicolon' => '?Token',
		],
	],
	'Statement\WhileNode' => [
		'description' => 'while loop in either syntax.',
		'slots' => [
			'whileKeyword' => 'Token',
			'openParen' => 'Token',
			'cond' => 'ExpressionNode',
			'closeParen' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
			'endKeyword' => '?Token',
			'semicolon' => '?Token',
		],
	],
	'Statement\DoWhileNode' => [
		'description' => 'do-while loop.',
		'slots' => [
			'doKeyword' => 'Token',
			'body' => 'StatementNode',
			'whileKeyword' => 'Token',
			'openParen' => 'Token',
			'cond' => 'ExpressionNode',
			'closeParen' => 'Token',
			'semicolon' => 'Token',
		],
	],
	'Statement\ForNode' => [
		'description' => 'for loop in either syntax.',
		'slots' => [
			'forKeyword' => 'Token',
			'openParen' => 'Token',
			'init' => 'SeparatedNodeList<ExpressionNode>',
			'initSemicolon' => 'Token',
			'cond' => 'SeparatedNodeList<ExpressionNode>',
			'condSemicolon' => 'Token',
			'loop' => 'SeparatedNodeList<ExpressionNode>',
			'closeParen' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
			'endKeyword' => '?Token',
			'semicolon' => '?Token',
		],
	],
	'Statement\ForeachNode' => [
		'description' => 'foreach loop in either syntax; the value may be a variable, a list or an array pattern.',
		'slots' => [
			'foreachKeyword' => 'Token',
			'openParen' => 'Token',
			'expr' => 'ExpressionNode',
			'asKeyword' => 'Token',
			'keyVar' => '?ExpressionNode',
			'doubleArrow' => '?Token',
			'byRef' => '?Token',
			'valueVar' => 'ExpressionNode',
			'closeParen' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
			'endKeyword' => '?Token',
			'semicolon' => '?Token',
		],
	],
	'Statement\SwitchNode' => [
		'description' => 'switch statement in either syntax; a semicolon may precede the first case.',
		'slots' => [
			'switchKeyword' => 'Token',
			'openParen' => 'Token',
			'cond' => 'ExpressionNode',
			'closeParen' => 'Token',
			'openBrace' => '?Token',
			'colon' => '?Token',
			'leadingSemicolon' => '?Token',
			'cases' => 'NodeList<CaseNode>',
			'closeBrace' => '?Token',
			'endKeyword' => '?Token',
			'semicolon' => '?Token',
		],
	],
	'Statement\BreakNode' => [
		'description' => 'break with an optional level.',
		'slots' => [
			'breakKeyword' => 'Token',
			'expr' => '?ExpressionNode',
			'semicolon' => 'Token',
		],
	],
	'Statement\ContinueNode' => [
		'description' => 'continue with an optional level.',
		'slots' => [
			'continueKeyword' => 'Token',
			'expr' => '?ExpressionNode',
			'semicolon' => 'Token',
		],
	],
	'Statement\ReturnNode' => [
		'description' => 'return with an optional value.',
		'slots' => [
			'returnKeyword' => 'Token',
			'expr' => '?ExpressionNode',
			'semicolon' => 'Token',
		],
	],
	'Statement\GlobalNode' => [
		'description' => 'global statement.',
		'slots' => [
			'globalKeyword' => 'Token',
			'vars' => 'SeparatedNodeList<ExpressionNode>',
			'semicolon' => 'Token',
		],
	],
	'Statement\StaticNode' => [
		'description' => 'static variable declaration.',
		'slots' => [
			'staticKeyword' => 'Token',
			'vars' => 'SeparatedNodeList<StaticVarNode>',
			'semicolon' => 'Token',
		],
	],
	'Statement\EchoNode' => [
		'description' => 'echo statement; the keyword may be the <?= open tag and the semicolon a close tag.',
		'slots' => [
			'echoKeyword' => 'Token',
			'exprs' => 'SeparatedNodeList<ExpressionNode>',
			'semicolon' => 'Token',
		],
	],
	'Statement\UnsetNode' => [
		'description' => 'unset statement.',
		'slots' => [
			'unsetKeyword' => 'Token',
			'openParen' => 'Token',
			'vars' => 'SeparatedNodeList<ExpressionNode>',
			'closeParen' => 'Token',
			'semicolon' => 'Token',
		],
	],
	'Statement\DeclareNode' => [
		'description' => 'declare statement in its three forms: with a body, with a bare semicolon, or with the alternative syntax.',
		'slots' => [
			'declareKeyword' => 'Token',
			'openParen' => 'Token',
			'items' => 'SeparatedNodeList<DeclareItemNode>',
			'closeParen' => 'Token',
			'body' => '?StatementNode',
			'colon' => '?Token',
			'stmts' => '?NodeList<StatementNode>',
			'endKeyword' => '?Token',
			'semicolon' => '?Token',
		],
	],
	'Statement\TryNode' => [
		'description' => 'try statement with catches and an optional finally.',
		'slots' => [
			'tryKeyword' => 'Token',
			'body' => 'Statement\BlockNode',
			'catches' => 'NodeList<CatchNode>',
			'finally' => '?FinallyNode',
		],
	],
	'Statement\GotoNode' => [
		'description' => 'goto statement.',
		'slots' => [
			'gotoKeyword' => 'Token',
			'label' => 'IdentifierNode',
			'semicolon' => 'Token',
		],
	],
	'Statement\LabelNode' => [
		'description' => 'Label for goto.',
		'slots' => [
			'name' => 'IdentifierNode',
			'colon' => 'Token',
		],
	],
	'Statement\FunctionNode' => [
		'description' => 'Function declaration.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'functionKeyword' => 'Token',
			'byRef' => '?Token',
			'name' => 'IdentifierNode',
			'openParen' => 'Token',
			'params' => 'SeparatedNodeList<ParameterNode>',
			'closeParen' => 'Token',
			'colon' => '?Token',
			'returnType' => '?TypeNode',
			'body' => 'Statement\BlockNode',
		],
	],
	'Statement\ClassNode' => [
		'implements' => ['ClassLikeNode'],
		'description' => 'Class declaration.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'classKeyword' => 'Token',
			'name' => 'IdentifierNode',
			'extendsKeyword' => '?Token',
			'extends' => '?NameNode',
			'implementsKeyword' => '?Token',
			'implements' => '?SeparatedNodeList<NameNode>',
			'openBrace' => 'Token',
			'members' => 'NodeList<MemberNode>',
			'closeBrace' => 'Token',
		],
	],
	'Statement\InterfaceNode' => [
		'implements' => ['ClassLikeNode'],
		'description' => 'Interface declaration.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'interfaceKeyword' => 'Token',
			'name' => 'IdentifierNode',
			'extendsKeyword' => '?Token',
			'extends' => '?SeparatedNodeList<NameNode>',
			'openBrace' => 'Token',
			'members' => 'NodeList<MemberNode>',
			'closeBrace' => 'Token',
		],
	],
	'Statement\TraitNode' => [
		'implements' => ['ClassLikeNode'],
		'description' => 'Trait declaration.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'traitKeyword' => 'Token',
			'name' => 'IdentifierNode',
			'openBrace' => 'Token',
			'members' => 'NodeList<MemberNode>',
			'closeBrace' => 'Token',
		],
	],
	'Statement\EnumNode' => [
		'implements' => ['ClassLikeNode'],
		'description' => 'Enum declaration, optionally backed by a scalar type.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'enumKeyword' => 'Token',
			'name' => 'IdentifierNode',
			'colon' => '?Token',
			'scalarType' => '?TypeNode',
			'implementsKeyword' => '?Token',
			'implements' => '?SeparatedNodeList<NameNode>',
			'openBrace' => 'Token',
			'members' => 'NodeList<MemberNode>',
			'closeBrace' => 'Token',
		],
	],

	// ---------- members ----------

	'Member\PropertyNode' => [
		'description' => 'Property declaration; one or more properties, optionally with hooks.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'type' => '?TypeNode',
			'items' => 'SeparatedNodeList<Member\PropertyItemNode>',
			'semicolon' => '?Token',
			'openBrace' => '?Token',
			'hooks' => '?NodeList<Member\PropertyHookNode>',
			'closeBrace' => '?Token',
		],
	],
	'Member\PropertyItemNode' => [
		'extends' => 'Node',
		'description' => 'One property of a declaration with an optional default.',
		'slots' => [
			'name' => 'Token',
			'equals' => '?Token',
			'default' => '?ExpressionNode',
		],
	],
	'Member\PropertyHookNode' => [
		'extends' => 'Node',
		'description' => 'Property hook (get, set) with a block body, an arrow body or none.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'byRef' => '?Token',
			'name' => 'IdentifierNode',
			'openParen' => '?Token',
			'params' => '?SeparatedNodeList<ParameterNode>',
			'closeParen' => '?Token',
			'body' => '?Statement\BlockNode',
			'doubleArrow' => '?Token',
			'expr' => '?ExpressionNode',
			'semicolon' => '?Token',
		],
	],
	'Member\ClassConstNode' => [
		'description' => 'Class constant declaration, optionally typed.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'constKeyword' => 'Token',
			'type' => '?TypeNode',
			'items' => 'SeparatedNodeList<ConstItemNode>',
			'semicolon' => 'Token',
		],
	],
	'Member\MethodNode' => [
		'description' => 'Method declaration; abstract and interface methods end with a semicolon instead of a body.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'modifiers' => 'ModifiersNode',
			'functionKeyword' => 'Token',
			'byRef' => '?Token',
			'name' => 'IdentifierNode',
			'openParen' => 'Token',
			'params' => 'SeparatedNodeList<ParameterNode>',
			'closeParen' => 'Token',
			'colon' => '?Token',
			'returnType' => '?TypeNode',
			'body' => '?Statement\BlockNode',
			'semicolon' => '?Token',
		],
	],
	'Member\TraitUseNode' => [
		'description' => 'use of traits with optional adaptations in braces.',
		'slots' => [
			'useKeyword' => 'Token',
			'traits' => 'SeparatedNodeList<NameNode>',
			'semicolon' => '?Token',
			'openBrace' => '?Token',
			'adaptations' => '?NodeList<Member\TraitPrecedenceNode|Member\TraitAliasNode>',
			'closeBrace' => '?Token',
		],
	],
	'Member\TraitPrecedenceNode' => [
		'extends' => 'Node',
		'description' => 'Trait adaptation A::m insteadof B;.',
		'slots' => [
			'trait' => 'NameNode',
			'doubleColon' => 'Token',
			'method' => 'IdentifierNode',
			'insteadofKeyword' => 'Token',
			'traits' => 'SeparatedNodeList<NameNode>',
			'semicolon' => 'Token',
		],
	],
	'Member\TraitAliasNode' => [
		'extends' => 'Node',
		'description' => 'Trait adaptation m as [modifier] [alias];.',
		'slots' => [
			'trait' => '?NameNode',
			'doubleColon' => '?Token',
			'method' => 'IdentifierNode',
			'asKeyword' => 'Token',
			'modifier' => '?Token',
			'alias' => '?IdentifierNode',
			'semicolon' => 'Token',
		],
	],
	'Member\EnumCaseNode' => [
		'description' => 'Enum case with an optional backing value.',
		'slots' => [
			'attributes' => 'NodeList<AttributeGroupNode>',
			'caseKeyword' => 'Token',
			'name' => 'IdentifierNode',
			'equals' => '?Token',
			'value' => '?ExpressionNode',
			'semicolon' => 'Token',
		],
	],
];
