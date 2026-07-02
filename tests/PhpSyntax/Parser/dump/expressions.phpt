<?php declare(strict_types=1);

// variables, accesses, calls, arrays, operators and the remaining expressions

use DressCode\Tools\Dumper;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$input = <<<'XX'
	<?php
	$a; $$a; ${'a' . 'b'}; $a[1]; $a[]; $a->b; $a?->b; $a->{'b'}; $a->$b; A::$b; $a::$b;
	A::B; A::class; A::{$x}; $a::B; static::B;
	f(1, ...$a, name: 2); $f(); $a->m(); $a?->m(); A::m(); $a::m(); A::{'m'}(); $a->{'m'}(); 'f'(); (fn() => 1)();
	new A(1); new A; new static; new $b; new ($c); new class(1) extends B implements C { }; f(...); $a->m(...);
	[1, 'k' => 2, ...$x, &$y, 3 => &$z]; array(1,); [, $b] = $x; list($a, , $b) = $c; [$a, [$b]] = $c; [$k => $v] = $c;
	$a = $b += $c .= $d ??= $e; $a = &$b; $a++; $a--; ++$a; --$a; +$a; -$a; !$a; ~$a; @$a;
	$a + $b - $c * $d / $e % $f ** $g; $a . $b; $a & $b | $c ^ $d << $e >> $f;
	$a == $b; $a != $b; $a === $b; $a !== $b; $a < $b; $a <= $b; $a > $b; $a >= $b; $a <=> $b; $a && $b || $c and $d or $e xor $f;
	$a ? $b : $c; $a ?: $c; $a ?? $b; $a instanceof B; $a instanceof $b; $a instanceof (B); $a |> f(...);
	(int) $a; ( int ) $a; (float) $a; (string) $a; (array) $a; (object) $a; (bool) $a; (void) f();
	isset($a, $b,); empty($a); eval('1'); include 'a'; include_once 'a'; require 'a'; require_once 'a';
	exit; exit(1); die('x'); print $a; throw new E; clone $a; clone($a, [1]);
	match ($a) { 1, 2 => 'x', default => 'y', };
	function () { }; static function (&$a) use ($b, &$c): int { return 1; }; fn($a) => $a; static fn&() => 1;
	yield; yield $a; yield $k => $v; yield from $g; `ls $a`;
	XX;

$file = (new Parser)->parse($input);
Assert::same($input, (string) $file);
Assert::same(loadExpected(__FILE__, __COMPILER_HALT_OFFSET__), Dumper::dump($file));

__halt_compiler();
FileNode
  stmts: NodeList
    - ExpressionStatementNode
      expr: VariableNode
        name: Variable "$a"  <OpenTag"<?php\n"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: VariableNode
        dollar: '$' "$"
        name: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: VariableNode
        dollar: '$' "$"
        openBrace: '{' "{"
        name: BinaryNode
          left: StringNode
            token: ConstantEncapsedString "'a'"  >Whitespace" "
          operator: '.' "."  >Whitespace" "
          right: StringNode
            token: ConstantEncapsedString "'b'"
        closeBrace: '}' "}"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ArrayDimFetchNode
        var: VariableNode
          name: Variable "$a"
        openBracket: '[' "["
        dim: IntegerNode
          token: Integer "1"
        closeBracket: ']' "]"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ArrayDimFetchNode
        var: VariableNode
          name: Variable "$a"
        openBracket: '[' "["
        closeBracket: ']' "]"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PropertyFetchNode
        object: VariableNode
          name: Variable "$a"
        operator: ObjectOperator "->"
        name: IdentifierNode
          token: Identifier "b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PropertyFetchNode
        object: VariableNode
          name: Variable "$a"
        operator: NullsafeObjectOperator "?->"
        name: IdentifierNode
          token: Identifier "b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PropertyFetchNode
        object: VariableNode
          name: Variable "$a"
        operator: ObjectOperator "->"
        openBrace: '{' "{"
        name: StringNode
          token: ConstantEncapsedString "'b'"
        closeBrace: '}' "}"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PropertyFetchNode
        object: VariableNode
          name: Variable "$a"
        operator: ObjectOperator "->"
        name: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: StaticPropertyFetchNode
        class: NameNode
          token: Identifier "A"
        doubleColon: DoubleColon "::"
        name: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: StaticPropertyFetchNode
        class: VariableNode
          name: Variable "$a"
        doubleColon: DoubleColon "::"
        name: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: ClassConstantFetchNode
        class: NameNode
          token: Identifier "A"
        doubleColon: DoubleColon "::"
        name: IdentifierNode
          token: Identifier "B"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ClassConstantFetchNode
        class: NameNode
          token: Identifier "A"
        doubleColon: DoubleColon "::"
        name: IdentifierNode
          token: ClassKeyword "class"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ClassConstantFetchNode
        class: NameNode
          token: Identifier "A"
        doubleColon: DoubleColon "::"
        openBrace: '{' "{"
        name: VariableNode
          name: Variable "$x"
        closeBrace: '}' "}"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ClassConstantFetchNode
        class: VariableNode
          name: Variable "$a"
        doubleColon: DoubleColon "::"
        name: IdentifierNode
          token: Identifier "B"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ClassConstantFetchNode
        class: NameNode
          token: Static "static"
        doubleColon: DoubleColon "::"
        name: IdentifierNode
          token: Identifier "B"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: FunctionCallNode
        name: NameNode
          token: Identifier "f"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - ArgumentNode
              expr: IntegerNode
                token: Integer "1"
            - ',' ","  >Whitespace" "
            - ArgumentNode
              ellipsis: Ellipsis "..."
              expr: VariableNode
                name: Variable "$a"
            - ',' ","  >Whitespace" "
            - ArgumentNode
              name: IdentifierNode
                token: Identifier "name"
              colon: ':' ":"  >Whitespace" "
              expr: IntegerNode
                token: Integer "2"
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: FunctionCallNode
        name: VariableNode
          name: Variable "$f"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: MethodCallNode
        object: VariableNode
          name: Variable "$a"
        operator: ObjectOperator "->"
        name: IdentifierNode
          token: Identifier "m"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: MethodCallNode
        object: VariableNode
          name: Variable "$a"
        operator: NullsafeObjectOperator "?->"
        name: IdentifierNode
          token: Identifier "m"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: StaticCallNode
        class: NameNode
          token: Identifier "A"
        doubleColon: DoubleColon "::"
        name: IdentifierNode
          token: Identifier "m"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: StaticCallNode
        class: VariableNode
          name: Variable "$a"
        doubleColon: DoubleColon "::"
        name: IdentifierNode
          token: Identifier "m"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: StaticCallNode
        class: NameNode
          token: Identifier "A"
        doubleColon: DoubleColon "::"
        openBrace: '{' "{"
        name: StringNode
          token: ConstantEncapsedString "'m'"
        closeBrace: '}' "}"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: MethodCallNode
        object: VariableNode
          name: Variable "$a"
        operator: ObjectOperator "->"
        openBrace: '{' "{"
        name: StringNode
          token: ConstantEncapsedString "'m'"
        closeBrace: '}' "}"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: FunctionCallNode
        name: StringNode
          token: ConstantEncapsedString "'f'"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: FunctionCallNode
        name: ParenthesizedNode
          openParen: '(' "("
          expr: ArrowFunctionNode
            attributes: NodeList
            fnKeyword: Fn "fn"
            openParen: '(' "("
            params: SeparatedNodeList
            closeParen: ')' ")"  >Whitespace" "
            doubleArrow: DoubleArrow "=>"  >Whitespace" "
            expr: IntegerNode
              token: Integer "1"
          closeParen: ')' ")"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
          closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: NewNode
        newKeyword: New "new"  >Whitespace" "
        class: NameNode
          token: Identifier "A"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - ArgumentNode
              expr: IntegerNode
                token: Integer "1"
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: NewNode
        newKeyword: New "new"  >Whitespace" "
        class: NameNode
          token: Identifier "A"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: NewNode
        newKeyword: New "new"  >Whitespace" "
        class: NameNode
          token: Static "static"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: NewNode
        newKeyword: New "new"  >Whitespace" "
        class: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: NewNode
        newKeyword: New "new"  >Whitespace" "
        class: ParenthesizedNode
          openParen: '(' "("
          expr: VariableNode
            name: Variable "$c"
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: NewNode
        newKeyword: New "new"  >Whitespace" "
        class: AnonymousClassNode
          attributes: NodeList
          modifiers: ModifiersNode
          classKeyword: ClassKeyword "class"
          args: ArgumentListNode
            openParen: '(' "("
            args: SeparatedNodeList
              - ArgumentNode
                expr: IntegerNode
                  token: Integer "1"
            closeParen: ')' ")"  >Whitespace" "
          extendsKeyword: Extends "extends"  >Whitespace" "
          extends: NameNode
            token: Identifier "B"  >Whitespace" "
          implementsKeyword: Implements "implements"  >Whitespace" "
          implements: SeparatedNodeList
            - NameNode
              token: Identifier "C"  >Whitespace" "
          openBrace: '{' "{"  >Whitespace" "
          members: NodeList
          closeBrace: '}' "}"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: FunctionCallNode
        name: NameNode
          token: Identifier "f"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - VariadicPlaceholderNode
              ellipsis: Ellipsis "..."
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: MethodCallNode
        object: VariableNode
          name: Variable "$a"
        operator: ObjectOperator "->"
        name: IdentifierNode
          token: Identifier "m"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - VariadicPlaceholderNode
              ellipsis: Ellipsis "..."
          closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: ArrayNode
        openDelimiter: '[' "["
        items: SeparatedNodeList
          - ArrayItemNode
            value: IntegerNode
              token: Integer "1"
          - ',' ","  >Whitespace" "
          - ArrayItemNode
            key: StringNode
              token: ConstantEncapsedString "'k'"  >Whitespace" "
            doubleArrow: DoubleArrow "=>"  >Whitespace" "
            value: IntegerNode
              token: Integer "2"
          - ',' ","  >Whitespace" "
          - ArrayItemNode
            ellipsis: Ellipsis "..."
            value: VariableNode
              name: Variable "$x"
          - ',' ","  >Whitespace" "
          - ArrayItemNode
            byRef: AmpersandFollowedByVarOrVararg "&"
            value: VariableNode
              name: Variable "$y"
          - ',' ","  >Whitespace" "
          - ArrayItemNode
            key: IntegerNode
              token: Integer "3"  >Whitespace" "
            doubleArrow: DoubleArrow "=>"  >Whitespace" "
            byRef: AmpersandFollowedByVarOrVararg "&"
            value: VariableNode
              name: Variable "$z"
        closeDelimiter: ']' "]"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ArrayNode
        arrayKeyword: Array "array"
        openDelimiter: '(' "("
        items: SeparatedNodeList
          - ArrayItemNode
            value: IntegerNode
              token: Integer "1"
          - ',' ","
        closeDelimiter: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: AssignNode
        var: ArrayNode
          openDelimiter: '[' "["
          items: SeparatedNodeList
            - EmptyArrayItemNode
            - ',' ","  >Whitespace" "
            - ArrayItemNode
              value: VariableNode
                name: Variable "$b"
          closeDelimiter: ']' "]"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: VariableNode
          name: Variable "$x"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: AssignNode
        var: ListNode
          listKeyword: List "list"
          openParen: '(' "("
          items: SeparatedNodeList
            - ArrayItemNode
              value: VariableNode
                name: Variable "$a"
            - ',' ","  >Whitespace" "
            - EmptyArrayItemNode
            - ',' ","  >Whitespace" "
            - ArrayItemNode
              value: VariableNode
                name: Variable "$b"
          closeParen: ')' ")"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: VariableNode
          name: Variable "$c"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: AssignNode
        var: ArrayNode
          openDelimiter: '[' "["
          items: SeparatedNodeList
            - ArrayItemNode
              value: VariableNode
                name: Variable "$a"
            - ',' ","  >Whitespace" "
            - ArrayItemNode
              value: ArrayNode
                openDelimiter: '[' "["
                items: SeparatedNodeList
                  - ArrayItemNode
                    value: VariableNode
                      name: Variable "$b"
                closeDelimiter: ']' "]"
          closeDelimiter: ']' "]"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: VariableNode
          name: Variable "$c"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: AssignNode
        var: ArrayNode
          openDelimiter: '[' "["
          items: SeparatedNodeList
            - ArrayItemNode
              key: VariableNode
                name: Variable "$k"  >Whitespace" "
              doubleArrow: DoubleArrow "=>"  >Whitespace" "
              value: VariableNode
                name: Variable "$v"
          closeDelimiter: ']' "]"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: VariableNode
          name: Variable "$c"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: AssignNode
          var: VariableNode
            name: Variable "$b"  >Whitespace" "
          operator: PlusEqual "+="  >Whitespace" "
          expr: AssignNode
            var: VariableNode
              name: Variable "$c"  >Whitespace" "
            operator: ConcatEqual ".="  >Whitespace" "
            expr: AssignNode
              var: VariableNode
                name: Variable "$d"  >Whitespace" "
              operator: CoalesceEqual "??="  >Whitespace" "
              expr: VariableNode
                name: Variable "$e"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: AssignRefNode
        var: VariableNode
          name: Variable "$a"  >Whitespace" "
        equals: '=' "="  >Whitespace" "
        ampersand: AmpersandFollowedByVarOrVararg "&"
        expr: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PostfixNode
        expr: VariableNode
          name: Variable "$a"
        operator: Increment "++"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PostfixNode
        expr: VariableNode
          name: Variable "$a"
        operator: Decrement "--"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: Increment "++"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: Decrement "--"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: '+' "+"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: '-' "-"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: '!' "!"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: '~' "~"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: UnaryNode
        operator: '@' "@"
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: BinaryNode
        left: BinaryNode
          left: VariableNode
            name: Variable "$a"  >Whitespace" "
          operator: '+' "+"  >Whitespace" "
          right: VariableNode
            name: Variable "$b"  >Whitespace" "
        operator: '-' "-"  >Whitespace" "
        right: BinaryNode
          left: BinaryNode
            left: BinaryNode
              left: VariableNode
                name: Variable "$c"  >Whitespace" "
              operator: '*' "*"  >Whitespace" "
              right: VariableNode
                name: Variable "$d"  >Whitespace" "
            operator: '/' "/"  >Whitespace" "
            right: VariableNode
              name: Variable "$e"  >Whitespace" "
          operator: '%' "%"  >Whitespace" "
          right: BinaryNode
            left: VariableNode
              name: Variable "$f"  >Whitespace" "
            operator: Pow "**"  >Whitespace" "
            right: VariableNode
              name: Variable "$g"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: '.' "."  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: BinaryNode
          left: VariableNode
            name: Variable "$a"  >Whitespace" "
          operator: AmpersandFollowedByVarOrVararg "&"  >Whitespace" "
          right: VariableNode
            name: Variable "$b"  >Whitespace" "
        operator: '|' "|"  >Whitespace" "
        right: BinaryNode
          left: VariableNode
            name: Variable "$c"  >Whitespace" "
          operator: '^' "^"  >Whitespace" "
          right: BinaryNode
            left: BinaryNode
              left: VariableNode
                name: Variable "$d"  >Whitespace" "
              operator: ShiftLeft "<<"  >Whitespace" "
              right: VariableNode
                name: Variable "$e"  >Whitespace" "
            operator: ShiftRight ">>"  >Whitespace" "
            right: VariableNode
              name: Variable "$f"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: IsEqual "=="  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: IsNotEqual "!="  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: IsIdentical "==="  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: IsNotIdentical "!=="  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: '<' "<"  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: IsSmallerOrEqual "<="  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: '>' ">"  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: IsGreaterOrEqual ">="  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: Spaceship "<=>"  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: BinaryNode
          left: BinaryNode
            left: BinaryNode
              left: VariableNode
                name: Variable "$a"  >Whitespace" "
              operator: BooleanAnd "&&"  >Whitespace" "
              right: VariableNode
                name: Variable "$b"  >Whitespace" "
            operator: BooleanOr "||"  >Whitespace" "
            right: VariableNode
              name: Variable "$c"  >Whitespace" "
          operator: LogicalAnd "and"  >Whitespace" "
          right: VariableNode
            name: Variable "$d"  >Whitespace" "
        operator: LogicalOr "or"  >Whitespace" "
        right: BinaryNode
          left: VariableNode
            name: Variable "$e"  >Whitespace" "
          operator: LogicalXor "xor"  >Whitespace" "
          right: VariableNode
            name: Variable "$f"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: TernaryNode
        cond: VariableNode
          name: Variable "$a"  >Whitespace" "
        question: '?' "?"  >Whitespace" "
        if: VariableNode
          name: Variable "$b"  >Whitespace" "
        colon: ':' ":"  >Whitespace" "
        else: VariableNode
          name: Variable "$c"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: TernaryNode
        cond: VariableNode
          name: Variable "$a"  >Whitespace" "
        question: '?' "?"
        colon: ':' ":"  >Whitespace" "
        else: VariableNode
          name: Variable "$c"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: Coalesce "??"  >Whitespace" "
        right: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: InstanceofNode
        expr: VariableNode
          name: Variable "$a"  >Whitespace" "
        instanceofKeyword: Instanceof "instanceof"  >Whitespace" "
        class: NameNode
          token: Identifier "B"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: InstanceofNode
        expr: VariableNode
          name: Variable "$a"  >Whitespace" "
        instanceofKeyword: Instanceof "instanceof"  >Whitespace" "
        class: VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: InstanceofNode
        expr: VariableNode
          name: Variable "$a"  >Whitespace" "
        instanceofKeyword: Instanceof "instanceof"  >Whitespace" "
        class: ParenthesizedNode
          openParen: '(' "("
          expr: ConstantFetchNode
            name: NameNode
              token: Identifier "B"
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: BinaryNode
        left: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: Pipe "|>"  >Whitespace" "
        right: FunctionCallNode
          name: NameNode
            token: Identifier "f"
          args: ArgumentListNode
            openParen: '(' "("
            args: SeparatedNodeList
              - VariadicPlaceholderNode
                ellipsis: Ellipsis "..."
            closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: CastNode
        cast: IntCast "(int)"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: IntCast "( int )"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: FloatCast "(float)"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: StringCast "(string)"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: ArrayCast "(array)"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: ObjectCast "(object)"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: BoolCast "(bool)"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CastNode
        cast: VoidCast "(void)"  >Whitespace" "
        expr: FunctionCallNode
          name: NameNode
            token: Identifier "f"
          args: ArgumentListNode
            openParen: '(' "("
            args: SeparatedNodeList
            closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: IssetNode
        issetKeyword: Isset "isset"
        openParen: '(' "("
        vars: SeparatedNodeList
          - VariableNode
            name: Variable "$a"
          - ',' ","  >Whitespace" "
          - VariableNode
            name: Variable "$b"
          - ',' ","
        closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: EmptyNode
        emptyKeyword: Empty "empty"
        openParen: '(' "("
        expr: VariableNode
          name: Variable "$a"
        closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: EvalNode
        evalKeyword: Eval "eval"
        openParen: '(' "("
        expr: StringNode
          token: ConstantEncapsedString "'1'"
        closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: IncludeNode
        includeKeyword: Include "include"  >Whitespace" "
        expr: StringNode
          token: ConstantEncapsedString "'a'"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: IncludeNode
        includeKeyword: IncludeOnce "include_once"  >Whitespace" "
        expr: StringNode
          token: ConstantEncapsedString "'a'"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: IncludeNode
        includeKeyword: Require "require"  >Whitespace" "
        expr: StringNode
          token: ConstantEncapsedString "'a'"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: IncludeNode
        includeKeyword: RequireOnce "require_once"  >Whitespace" "
        expr: StringNode
          token: ConstantEncapsedString "'a'"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: ExitNode
        exitKeyword: Exit "exit"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ExitNode
        exitKeyword: Exit "exit"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - ArgumentNode
              expr: IntegerNode
                token: Integer "1"
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ExitNode
        exitKeyword: Exit "die"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - ArgumentNode
              expr: StringNode
                token: ConstantEncapsedString "'x'"
          closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: PrintNode
        printKeyword: Print "print"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ThrowNode
        throwKeyword: Throw "throw"  >Whitespace" "
        expr: NewNode
          newKeyword: New "new"  >Whitespace" "
          class: NameNode
            token: Identifier "E"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: CloneNode
        cloneKeyword: Clone "clone"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: FunctionCallNode
        name: NameNode
          token: Clone "clone"
        args: ArgumentListNode
          openParen: '(' "("
          args: SeparatedNodeList
            - ArgumentNode
              expr: VariableNode
                name: Variable "$a"
            - ',' ","  >Whitespace" "
            - ArgumentNode
              expr: ArrayNode
                openDelimiter: '[' "["
                items: SeparatedNodeList
                  - ArrayItemNode
                    value: IntegerNode
                      token: Integer "1"
                closeDelimiter: ']' "]"
          closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: MatchNode
        matchKeyword: Match "match"  >Whitespace" "
        openParen: '(' "("
        cond: VariableNode
          name: Variable "$a"
        closeParen: ')' ")"  >Whitespace" "
        openBrace: '{' "{"  >Whitespace" "
        arms: SeparatedNodeList
          - MatchArmNode
            conds: SeparatedNodeList
              - IntegerNode
                token: Integer "1"
              - ',' ","  >Whitespace" "
              - IntegerNode
                token: Integer "2"  >Whitespace" "
            doubleArrow: DoubleArrow "=>"  >Whitespace" "
            body: StringNode
              token: ConstantEncapsedString "'x'"
          - ',' ","  >Whitespace" "
          - MatchArmNode
            defaultKeyword: Default "default"  >Whitespace" "
            doubleArrow: DoubleArrow "=>"  >Whitespace" "
            body: StringNode
              token: ConstantEncapsedString "'y'"
          - ',' ","  >Whitespace" "
        closeBrace: '}' "}"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: ClosureNode
        attributes: NodeList
        functionKeyword: Function "function"  >Whitespace" "
        openParen: '(' "("
        params: SeparatedNodeList
        closeParen: ')' ")"  >Whitespace" "
        body: BlockNode
          openBrace: '{' "{"  >Whitespace" "
          stmts: NodeList
          closeBrace: '}' "}"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ClosureNode
        attributes: NodeList
        staticKeyword: Static "static"  >Whitespace" "
        functionKeyword: Function "function"  >Whitespace" "
        openParen: '(' "("
        params: SeparatedNodeList
          - ParameterNode
            attributes: NodeList
            modifiers: ModifiersNode
            byRef: AmpersandFollowedByVarOrVararg "&"
            var: VariableNode
              name: Variable "$a"
        closeParen: ')' ")"  >Whitespace" "
        uses: ClosureUsesNode
          useKeyword: Use "use"  >Whitespace" "
          openParen: '(' "("
          vars: SeparatedNodeList
            - ClosureUseNode
              var: VariableNode
                name: Variable "$b"
            - ',' ","  >Whitespace" "
            - ClosureUseNode
              byRef: AmpersandFollowedByVarOrVararg "&"
              var: VariableNode
                name: Variable "$c"
          closeParen: ')' ")"
        colon: ':' ":"  >Whitespace" "
        returnType: NamedTypeNode
          name: NameNode
            token: Identifier "int"  >Whitespace" "
        body: BlockNode
          openBrace: '{' "{"  >Whitespace" "
          stmts: NodeList
            - ReturnNode
              returnKeyword: Return "return"  >Whitespace" "
              expr: IntegerNode
                token: Integer "1"
              semicolon: ';' ";"  >Whitespace" "
          closeBrace: '}' "}"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ArrowFunctionNode
        attributes: NodeList
        fnKeyword: Fn "fn"
        openParen: '(' "("
        params: SeparatedNodeList
          - ParameterNode
            attributes: NodeList
            modifiers: ModifiersNode
            var: VariableNode
              name: Variable "$a"
        closeParen: ')' ")"  >Whitespace" "
        doubleArrow: DoubleArrow "=>"  >Whitespace" "
        expr: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ArrowFunctionNode
        attributes: NodeList
        staticKeyword: Static "static"  >Whitespace" "
        fnKeyword: Fn "fn"
        byRef: AmpersandNotFollowedByVarOrVararg "&"
        openParen: '(' "("
        params: SeparatedNodeList
        closeParen: ')' ")"  >Whitespace" "
        doubleArrow: DoubleArrow "=>"  >Whitespace" "
        expr: IntegerNode
          token: Integer "1"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: YieldNode
        yieldKeyword: Yield "yield"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: YieldNode
        yieldKeyword: Yield "yield"  >Whitespace" "
        value: VariableNode
          name: Variable "$a"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: YieldNode
        yieldKeyword: Yield "yield"  >Whitespace" "
        key: VariableNode
          name: Variable "$k"  >Whitespace" "
        doubleArrow: DoubleArrow "=>"  >Whitespace" "
        value: VariableNode
          name: Variable "$v"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: YieldFromNode
        yieldFromKeyword: YieldFrom "yield from"  >Whitespace" "
        expr: VariableNode
          name: Variable "$g"
      semicolon: ';' ";"  >Whitespace" "
    - ExpressionStatementNode
      expr: ShellExecNode
        openBacktick: '`' "`"
        parts: NodeList
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace "ls "
          - VariableNode
            name: Variable "$a"
        closeBacktick: '`' "`"
      semicolon: ';' ";"
  eof: EndOfFile ""
