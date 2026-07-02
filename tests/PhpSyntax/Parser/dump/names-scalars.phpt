<?php declare(strict_types=1);

// names, identifiers, scalars and constants

use DressCode\Tools\Dumper;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$input = <<<'XX'
	<?php
	$a = 1 + 0x1F + 0b11 + 0o17 + 1_000;
	$b = .5 + 1.5e3;
	$c = 'single' . "double" . b'binary';
	$d = FOO . \Foo\BAR . namespace\BAZ . __LINE__ . __CLASS__;
	$e = true && null;
	__halt_compiler(); data
	XX;

$file = (new Parser)->parse($input);
Assert::same($input, (string) $file);
Assert::same(loadExpected(__FILE__, __COMPILER_HALT_OFFSET__), Dumper::dump($file));

__halt_compiler();
FileNode
  stmts: NodeList
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$a"  <OpenTag"<?php\n"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: BinaryNode
          left: BinaryNode
            left: BinaryNode
              left: BinaryNode
                left: IntegerNode
                  token: Integer "1"  >Whitespace" "
                operator: '+' "+"  >Whitespace" "
                right: IntegerNode
                  token: Integer "0x1F"  >Whitespace" "
              operator: '+' "+"  >Whitespace" "
              right: IntegerNode
                token: Integer "0b11"  >Whitespace" "
            operator: '+' "+"  >Whitespace" "
            right: IntegerNode
              token: Integer "0o17"  >Whitespace" "
          operator: '+' "+"  >Whitespace" "
          right: IntegerNode
            token: Integer "1_000"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$b"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: BinaryNode
          left: FloatNode
            token: Float ".5"  >Whitespace" "
          operator: '+' "+"  >Whitespace" "
          right: FloatNode
            token: Float "1.5e3"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$c"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: BinaryNode
          left: BinaryNode
            left: StringNode
              token: ConstantEncapsedString "'single'"  >Whitespace" "
            operator: '.' "."  >Whitespace" "
            right: StringNode
              token: ConstantEncapsedString "\"double\""  >Whitespace" "
          operator: '.' "."  >Whitespace" "
          right: StringNode
            token: ConstantEncapsedString "b'binary'"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$d"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: BinaryNode
          left: BinaryNode
            left: BinaryNode
              left: BinaryNode
                left: ConstantFetchNode
                  name: NameNode
                    token: Identifier "FOO"  >Whitespace" "
                operator: '.' "."  >Whitespace" "
                right: ConstantFetchNode
                  name: NameNode
                    token: NameFullyQualified "\\Foo\\BAR"  >Whitespace" "
              operator: '.' "."  >Whitespace" "
              right: ConstantFetchNode
                name: NameNode
                  token: NameRelative "namespace\\BAZ"  >Whitespace" "
            operator: '.' "."  >Whitespace" "
            right: MagicConstantNode
              token: MagicLine "__LINE__"  >Whitespace" "
          operator: '.' "."  >Whitespace" "
          right: MagicConstantNode
            token: MagicClass "__CLASS__"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$e"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: BinaryNode
          left: ConstantFetchNode
            name: NameNode
              token: Identifier "true"  >Whitespace" "
          operator: BooleanAnd "&&"  >Whitespace" "
          right: ConstantFetchNode
            name: NameNode
              token: Identifier "null"
      semicolon: ';' ";"  >EndOfLine"\n"
    - HaltCompilerNode
      haltKeyword: HaltCompiler "__halt_compiler"
      openParen: '(' "("
      closeParen: ')' ")"
      semicolon: ';' ";"
      data: HaltCompilerData " data"
  eof: EndOfFile ""
