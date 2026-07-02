<?php declare(strict_types=1);

// functions, parameters, closures and arrow functions

use DressCode\Tools\Dumper;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$input = <<<'XX'
	<?php
	function &f(int $a = 1, &$b, #[A] ?B $d = null, ...$c,): int { return 1; }
	function readonly() { } function exit() { } function clone() { }
	$f = function (A $a) use (&$b): void { };
	$g = static function () { };
	$h = fn(int $x): int => $x * 2;
	$i = static fn() => 1;
	$j = #[A] fn() => 1;
	$k = #[A] static function () { };
	XX;

$file = (new Parser)->parse($input);
Assert::same($input, (string) $file);
Assert::same(loadExpected(__FILE__, __COMPILER_HALT_OFFSET__), Dumper::dump($file));

__halt_compiler();
FileNode
  stmts: NodeList
    - FunctionNode
      attributes: NodeList
      functionKeyword: Function "function"  <OpenTag"<?php\n"  >Whitespace" "
      byRef: AmpersandNotFollowedByVarOrVararg "&"
      name: IdentifierNode
        token: Identifier "f"
      openParen: '(' "("
      params: SeparatedNodeList
        - ParameterNode
          attributes: NodeList
          modifiers: ModifiersNode
          type: NamedTypeNode
            name: NameNode
              token: Identifier "int"  >Whitespace" "
          var: VariableNode
            name: Variable "$a"  >Whitespace" "
          equals: '=' "="  >Whitespace" "
          default: IntegerNode
            token: Integer "1"
        - ',' ","  >Whitespace" "
        - ParameterNode
          attributes: NodeList
          modifiers: ModifiersNode
          byRef: AmpersandFollowedByVarOrVararg "&"
          var: VariableNode
            name: Variable "$b"
        - ',' ","  >Whitespace" "
        - ParameterNode
          attributes: NodeList
            - AttributeGroupNode
              openAttribute: Attribute "#["
              attributes: SeparatedNodeList
                - AttributeNode
                  name: NameNode
                    token: Identifier "A"
              closeBracket: ']' "]"  >Whitespace" "
          modifiers: ModifiersNode
          type: NullableTypeNode
            question: '?' "?"
            type: NamedTypeNode
              name: NameNode
                token: Identifier "B"  >Whitespace" "
          var: VariableNode
            name: Variable "$d"  >Whitespace" "
          equals: '=' "="  >Whitespace" "
          default: ConstantFetchNode
            name: NameNode
              token: Identifier "null"
        - ',' ","  >Whitespace" "
        - ParameterNode
          attributes: NodeList
          modifiers: ModifiersNode
          ellipsis: Ellipsis "..."
          var: VariableNode
            name: Variable "$c"
        - ',' ","
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
        closeBrace: '}' "}"  >EndOfLine"\n"
    - FunctionNode
      attributes: NodeList
      functionKeyword: Function "function"  >Whitespace" "
      name: IdentifierNode
        token: Readonly "readonly"
      openParen: '(' "("
      params: SeparatedNodeList
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - FunctionNode
      attributes: NodeList
      functionKeyword: Function "function"  >Whitespace" "
      name: IdentifierNode
        token: Exit "exit"
      openParen: '(' "("
      params: SeparatedNodeList
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - FunctionNode
      attributes: NodeList
      functionKeyword: Function "function"  >Whitespace" "
      name: IdentifierNode
        token: Clone "clone"
      openParen: '(' "("
      params: SeparatedNodeList
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$f"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: ClosureNode
          attributes: NodeList
          functionKeyword: Function "function"  >Whitespace" "
          openParen: '(' "("
          params: SeparatedNodeList
            - ParameterNode
              attributes: NodeList
              modifiers: ModifiersNode
              type: NamedTypeNode
                name: NameNode
                  token: Identifier "A"  >Whitespace" "
              var: VariableNode
                name: Variable "$a"
          closeParen: ')' ")"  >Whitespace" "
          uses: ClosureUsesNode
            useKeyword: Use "use"  >Whitespace" "
            openParen: '(' "("
            vars: SeparatedNodeList
              - ClosureUseNode
                byRef: AmpersandFollowedByVarOrVararg "&"
                var: VariableNode
                  name: Variable "$b"
            closeParen: ')' ")"
          colon: ':' ":"  >Whitespace" "
          returnType: NamedTypeNode
            name: NameNode
              token: Identifier "void"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$g"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: ClosureNode
          attributes: NodeList
          staticKeyword: Static "static"  >Whitespace" "
          functionKeyword: Function "function"  >Whitespace" "
          openParen: '(' "("
          params: SeparatedNodeList
          closeParen: ')' ")"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$h"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: ArrowFunctionNode
          attributes: NodeList
          fnKeyword: Fn "fn"
          openParen: '(' "("
          params: SeparatedNodeList
            - ParameterNode
              attributes: NodeList
              modifiers: ModifiersNode
              type: NamedTypeNode
                name: NameNode
                  token: Identifier "int"  >Whitespace" "
              var: VariableNode
                name: Variable "$x"
          closeParen: ')' ")"
          colon: ':' ":"  >Whitespace" "
          returnType: NamedTypeNode
            name: NameNode
              token: Identifier "int"  >Whitespace" "
          doubleArrow: DoubleArrow "=>"  >Whitespace" "
          expr: BinaryNode
            left: VariableNode
              name: Variable "$x"  >Whitespace" "
            operator: '*' "*"  >Whitespace" "
            right: IntegerNode
              token: Integer "2"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$i"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: ArrowFunctionNode
          attributes: NodeList
          staticKeyword: Static "static"  >Whitespace" "
          fnKeyword: Fn "fn"
          openParen: '(' "("
          params: SeparatedNodeList
          closeParen: ')' ")"  >Whitespace" "
          doubleArrow: DoubleArrow "=>"  >Whitespace" "
          expr: IntegerNode
            token: Integer "1"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$j"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: ArrowFunctionNode
          attributes: NodeList
            - AttributeGroupNode
              openAttribute: Attribute "#["
              attributes: SeparatedNodeList
                - AttributeNode
                  name: NameNode
                    token: Identifier "A"
              closeBracket: ']' "]"  >Whitespace" "
          fnKeyword: Fn "fn"
          openParen: '(' "("
          params: SeparatedNodeList
          closeParen: ')' ")"  >Whitespace" "
          doubleArrow: DoubleArrow "=>"  >Whitespace" "
          expr: IntegerNode
            token: Integer "1"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ExpressionStatementNode
      expr: AssignNode
        var: VariableNode
          name: Variable "$k"  >Whitespace" "
        operator: '=' "="  >Whitespace" "
        expr: ClosureNode
          attributes: NodeList
            - AttributeGroupNode
              openAttribute: Attribute "#["
              attributes: SeparatedNodeList
                - AttributeNode
                  name: NameNode
                    token: Identifier "A"
              closeBracket: ']' "]"  >Whitespace" "
          staticKeyword: Static "static"  >Whitespace" "
          functionKeyword: Function "function"  >Whitespace" "
          openParen: '(' "("
          params: SeparatedNodeList
          closeParen: ')' ")"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"
      semicolon: ';' ";"
  eof: EndOfFile ""
