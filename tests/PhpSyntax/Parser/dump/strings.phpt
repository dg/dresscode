<?php declare(strict_types=1);

// interpolated strings, heredoc, nowdoc, shell exec

use DressCode\Tools\Dumper;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$input = <<<'XX'
	<?php
	"a $b c {$d->e} ${f} $g[0] $g[key] $g[-1] $g[$i] $h->i $j?->k ${l['x']} {$m[1][2]} {$n /* c */ }";
	$a = <<<EOT
		x $b
		 y
		EOT;
	$c = <<<'EOT'
	raw $d
	EOT;
	$e = <<<EOT
	EOT;
	`ls $f {$g}`;
	XX;

$file = (new Parser)->parse($input);
Assert::same($input, (string) $file);
Assert::same(loadExpected(__FILE__, __COMPILER_HALT_OFFSET__), Dumper::dump($file));

__halt_compiler();
FileNode
  stmts: NodeList
    - ExpressionStatementNode
      expr: InterpolatedStringNode
        openQuote: '"' "\""  <OpenTag"<?php\n"
        parts: NodeList
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace "a "
          - VariableNode
            name: Variable "$b"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " c "
          - InterpolationNode
            openBrace: CurlyOpen "{"
            expr: PropertyFetchNode
              object: VariableNode
                name: Variable "$d"
              operator: ObjectOperator "->"
              name: IdentifierNode
                token: Identifier "e"
            closeBrace: '}' "}"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - InterpolationNode
            openBrace: DollarOpenCurlyBraces "${"
            expr: VariableNode
              name: StringVarname "f"
            closeBrace: '}' "}"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - ArrayDimFetchNode
            var: VariableNode
              name: Variable "$g"
            openBracket: '[' "["
            dim: IntegerNode
              token: NumString "0"
            closeBracket: ']' "]"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - ArrayDimFetchNode
            var: VariableNode
              name: Variable "$g"
            openBracket: '[' "["
            dim: StringNode
              token: Identifier "key"
            closeBracket: ']' "]"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - ArrayDimFetchNode
            var: VariableNode
              name: Variable "$g"
            openBracket: '[' "["
            dim: UnaryNode
              operator: '-' "-"
              expr: IntegerNode
                token: NumString "1"
            closeBracket: ']' "]"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - ArrayDimFetchNode
            var: VariableNode
              name: Variable "$g"
            openBracket: '[' "["
            dim: VariableNode
              name: Variable "$i"
            closeBracket: ']' "]"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - PropertyFetchNode
            object: VariableNode
              name: Variable "$h"
            operator: ObjectOperator "->"
            name: IdentifierNode
              token: Identifier "i"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - PropertyFetchNode
            object: VariableNode
              name: Variable "$j"
            operator: NullsafeObjectOperator "?->"
            name: IdentifierNode
              token: Identifier "k"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - InterpolationNode
            openBrace: DollarOpenCurlyBraces "${"
            expr: ArrayDimFetchNode
              var: VariableNode
                name: StringVarname "l"
              openBracket: '[' "["
              dim: StringNode
                token: ConstantEncapsedString "'x'"
              closeBracket: ']' "]"
            closeBrace: '}' "}"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - InterpolationNode
            openBrace: CurlyOpen "{"
            expr: ArrayDimFetchNode
              var: ArrayDimFetchNode
                var: VariableNode
                  name: Variable "$m"
                openBracket: '[' "["
                dim: IntegerNode
                  token: Integer "1"
                closeBracket: ']' "]"
              openBracket: '[' "["
              dim: IntegerNode
                token: Integer "2"
              closeBracket: ']' "]"
            closeBrace: '}' "}"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - InterpolationNode
            openBrace: CurlyOpen "{"
            expr: VariableNode
              name: Variable "$n"  >Whitespace*" " Comment*"/* c */" Whitespace*" "
            closeBrace: '}' "}"
        closeQuote: '"' "\""
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$a"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: HeredocNode
          openDelimiter: StartHeredoc "<<<EOT\n"
          parts: NodeList
            - InterpolatedStringPartNode
              text: EncapsedAndWhitespace "\tx "
            - VariableNode
              name: Variable "$b"
            - InterpolatedStringPartNode
              text: EncapsedAndWhitespace "\n\t y\n"
          closeDelimiter: EndHeredoc "\tEOT"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$c"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: HeredocNode
          openDelimiter: StartHeredoc "<<<'EOT'\n"
          parts: NodeList
            - InterpolatedStringPartNode
              text: EncapsedAndWhitespace "raw $d\n"
          closeDelimiter: EndHeredoc "EOT"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$e"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: HeredocNode
          openDelimiter: StartHeredoc "<<<EOT\n"
          parts: NodeList
          closeDelimiter: EndHeredoc "EOT"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: ShellExecNode
        openBacktick: '`' "`"
        parts: NodeList
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace "ls "
          - VariableNode
            name: Variable "$f"
          - InterpolatedStringPartNode
            text: EncapsedAndWhitespace " "
          - InterpolationNode
            openBrace: CurlyOpen "{"
            expr: VariableNode
              name: Variable "$g"
            closeBrace: '}' "}"
        closeBacktick: '`' "`"
      semicolon: ';' ";"
  eof: EndOfFile ""
