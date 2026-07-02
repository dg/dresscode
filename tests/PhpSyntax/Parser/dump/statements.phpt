<?php declare(strict_types=1);

// control structures in both syntaxes and the other statements

use DressCode\Tools\Dumper;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

$input = <<<'XX'
	<?php
	if ($a) foo(); elseif ($b) { } else if ($c) { } else { }
	if ($a): foo(); elseif ($b): else: endif;
	while ($a) { } while ($a): endwhile;
	do foo(); while ($a);
	for (;;) { } for ($i = 0, $j = 1; $i < 1; $i++, $j--): endfor;
	foreach ($a as $v) { } foreach ($a as $k => &$v): endforeach; foreach ($a as [$x, $y]) { } foreach ($a as list($x)) { }
	switch ($a) { case 1: case 2; foo(); default: } switch ($a) { ; case 1: } switch ($a): ; case 1: endswitch;
	break; break 2; continue; continue 2; return; return 1;
	global $a, $b; static $c, $d = 1; echo 1, 2; unset($a, $b,);
	declare(strict_types=1); declare(ticks=1) { } declare(ticks=1): enddeclare;
	try { } catch (A | B $e) { } catch (C) { } finally { }
	goto end; end: ;
	{ ; }
	?>html<?php ; ?>
	XX;

$file = (new Parser)->parse($input);
Assert::same($input, (string) $file);
Assert::same(loadExpected(__FILE__, __COMPILER_HALT_OFFSET__), Dumper::dump($file));

__halt_compiler();
FileNode
  stmts: NodeList
    - IfNode
      ifKeyword: If "if"  <OpenTag"<?php\n"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"  >Whitespace" "
      body: ExpressionStatementNode
        expr: FunctionCallNode
          name: NameNode
            token: Identifier "foo"
          args: ArgumentListNode
            openParen: '(' "("
            args: SeparatedNodeList
            closeParen: ')' ")"
        semicolon: ';' ";"  >Whitespace" "
      elseifs: NodeList
        - ElseIfNode
          elseifKeyword: Elseif "elseif"  >Whitespace" "
          openParen: '(' "("
          cond: VariableNode
            name: Variable "$b"
          closeParen: ')' ")"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"  >Whitespace" "
      else: ElseNode
        elseKeyword: Else "else"  >Whitespace" "
        body: IfNode
          ifKeyword: If "if"  >Whitespace" "
          openParen: '(' "("
          cond: VariableNode
            name: Variable "$c"
          closeParen: ')' ")"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"  >Whitespace" "
          elseifs: NodeList
          else: ElseNode
            elseKeyword: Else "else"  >Whitespace" "
            body: BlockNode
              openBrace: '{' "{"  >Whitespace" "
              stmts: NodeList
              closeBrace: '}' "}"  >EndOfLine"\n"
    - IfNode
      ifKeyword: If "if"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"
      colon: ':' ":"  >Whitespace" "
      stmts: NodeList
        - ExpressionStatementNode
          expr: FunctionCallNode
            name: NameNode
              token: Identifier "foo"
            args: ArgumentListNode
              openParen: '(' "("
              args: SeparatedNodeList
              closeParen: ')' ")"
          semicolon: ';' ";"  >Whitespace" "
      elseifs: NodeList
        - ElseIfNode
          elseifKeyword: Elseif "elseif"  >Whitespace" "
          openParen: '(' "("
          cond: VariableNode
            name: Variable "$b"
          closeParen: ')' ")"
          colon: ':' ":"  >Whitespace" "
          stmts: NodeList
      else: ElseNode
        elseKeyword: Else "else"
        colon: ':' ":"  >Whitespace" "
        stmts: NodeList
      endKeyword: Endif "endif"
      semicolon: ';' ";"  >EndOfLine"\n"
    - WhileNode
      whileKeyword: While "while"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - WhileNode
      whileKeyword: While "while"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"
      colon: ':' ":"  >Whitespace" "
      stmts: NodeList
      endKeyword: Endwhile "endwhile"
      semicolon: ';' ";"  >EndOfLine"\n"
    - DoWhileNode
      doKeyword: Do "do"  >Whitespace" "
      body: ExpressionStatementNode
        expr: FunctionCallNode
          name: NameNode
            token: Identifier "foo"
          args: ArgumentListNode
            openParen: '(' "("
            args: SeparatedNodeList
            closeParen: ')' ")"
        semicolon: ';' ";"  >Whitespace" "
      whileKeyword: While "while"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ForNode
      forKeyword: For "for"  >Whitespace" "
      openParen: '(' "("
      init: SeparatedNodeList
      initSemicolon: ';' ";"
      cond: SeparatedNodeList
      condSemicolon: ';' ";"
      loop: SeparatedNodeList
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - ForNode
      forKeyword: For "for"  >Whitespace" "
      openParen: '(' "("
      init: SeparatedNodeList
        - AssignNode
          var: VariableNode
            name: Variable "$i"  >Whitespace" "
          operator: '=' "="  >Whitespace" "
          expr: IntegerNode
            token: Integer "0"
        - ',' ","  >Whitespace" "
        - AssignNode
          var: VariableNode
            name: Variable "$j"  >Whitespace" "
          operator: '=' "="  >Whitespace" "
          expr: IntegerNode
            token: Integer "1"
      initSemicolon: ';' ";"  >Whitespace" "
      cond: SeparatedNodeList
        - BinaryNode
          left: VariableNode
            name: Variable "$i"  >Whitespace" "
          operator: '<' "<"  >Whitespace" "
          right: IntegerNode
            token: Integer "1"
      condSemicolon: ';' ";"  >Whitespace" "
      loop: SeparatedNodeList
        - PostfixNode
          expr: VariableNode
            name: Variable "$i"
          operator: Increment "++"
        - ',' ","  >Whitespace" "
        - PostfixNode
          expr: VariableNode
            name: Variable "$j"
          operator: Decrement "--"
      closeParen: ')' ")"
      colon: ':' ":"  >Whitespace" "
      stmts: NodeList
      endKeyword: Endfor "endfor"
      semicolon: ';' ";"  >EndOfLine"\n"
    - ForeachNode
      foreachKeyword: Foreach "foreach"  >Whitespace" "
      openParen: '(' "("
      expr: VariableNode
        name: Variable "$a"  >Whitespace" "
      asKeyword: As "as"  >Whitespace" "
      valueVar: VariableNode
        name: Variable "$v"
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - ForeachNode
      foreachKeyword: Foreach "foreach"  >Whitespace" "
      openParen: '(' "("
      expr: VariableNode
        name: Variable "$a"  >Whitespace" "
      asKeyword: As "as"  >Whitespace" "
      keyVar: VariableNode
        name: Variable "$k"  >Whitespace" "
      doubleArrow: DoubleArrow "=>"  >Whitespace" "
      byRef: AmpersandFollowedByVarOrVararg "&"
      valueVar: VariableNode
        name: Variable "$v"
      closeParen: ')' ")"
      colon: ':' ":"  >Whitespace" "
      stmts: NodeList
      endKeyword: Endforeach "endforeach"
      semicolon: ';' ";"  >Whitespace" "
    - ForeachNode
      foreachKeyword: Foreach "foreach"  >Whitespace" "
      openParen: '(' "("
      expr: VariableNode
        name: Variable "$a"  >Whitespace" "
      asKeyword: As "as"  >Whitespace" "
      valueVar: ArrayNode
        openDelimiter: '[' "["
        items: SeparatedNodeList
          - ArrayItemNode
            value: VariableNode
              name: Variable "$x"
          - ',' ","  >Whitespace" "
          - ArrayItemNode
            value: VariableNode
              name: Variable "$y"
        closeDelimiter: ']' "]"
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - ForeachNode
      foreachKeyword: Foreach "foreach"  >Whitespace" "
      openParen: '(' "("
      expr: VariableNode
        name: Variable "$a"  >Whitespace" "
      asKeyword: As "as"  >Whitespace" "
      valueVar: ListNode
        listKeyword: List "list"
        openParen: '(' "("
        items: SeparatedNodeList
          - ArrayItemNode
            value: VariableNode
              name: Variable "$x"
        closeParen: ')' ")"
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >EndOfLine"\n"
    - SwitchNode
      switchKeyword: Switch "switch"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"  >Whitespace" "
      openBrace: '{' "{"  >Whitespace" "
      cases: NodeList
        - CaseNode
          caseKeyword: Case "case"  >Whitespace" "
          cond: IntegerNode
            token: Integer "1"
          separator: ':' ":"  >Whitespace" "
          stmts: NodeList
        - CaseNode
          caseKeyword: Case "case"  >Whitespace" "
          cond: IntegerNode
            token: Integer "2"
          separator: ';' ";"  >Whitespace" "
          stmts: NodeList
            - ExpressionStatementNode
              expr: FunctionCallNode
                name: NameNode
                  token: Identifier "foo"
                args: ArgumentListNode
                  openParen: '(' "("
                  args: SeparatedNodeList
                  closeParen: ')' ")"
              semicolon: ';' ";"  >Whitespace" "
        - CaseNode
          caseKeyword: Default "default"
          separator: ':' ":"  >Whitespace" "
          stmts: NodeList
      closeBrace: '}' "}"  >Whitespace" "
    - SwitchNode
      switchKeyword: Switch "switch"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"  >Whitespace" "
      openBrace: '{' "{"  >Whitespace" "
      leadingSemicolon: ';' ";"  >Whitespace" "
      cases: NodeList
        - CaseNode
          caseKeyword: Case "case"  >Whitespace" "
          cond: IntegerNode
            token: Integer "1"
          separator: ':' ":"  >Whitespace" "
          stmts: NodeList
      closeBrace: '}' "}"  >Whitespace" "
    - SwitchNode
      switchKeyword: Switch "switch"  >Whitespace" "
      openParen: '(' "("
      cond: VariableNode
        name: Variable "$a"
      closeParen: ')' ")"
      colon: ':' ":"  >Whitespace" "
      leadingSemicolon: ';' ";"  >Whitespace" "
      cases: NodeList
        - CaseNode
          caseKeyword: Case "case"  >Whitespace" "
          cond: IntegerNode
            token: Integer "1"
          separator: ':' ":"  >Whitespace" "
          stmts: NodeList
      endKeyword: Endswitch "endswitch"
      semicolon: ';' ";"  >EndOfLine"\n"
    - BreakNode
      breakKeyword: Break "break"
      semicolon: ';' ";"  >Whitespace" "
    - BreakNode
      breakKeyword: Break "break"  >Whitespace" "
      expr: IntegerNode
        token: Integer "2"
      semicolon: ';' ";"  >Whitespace" "
    - ContinueNode
      continueKeyword: Continue "continue"
      semicolon: ';' ";"  >Whitespace" "
    - ContinueNode
      continueKeyword: Continue "continue"  >Whitespace" "
      expr: IntegerNode
        token: Integer "2"
      semicolon: ';' ";"  >Whitespace" "
    - ReturnNode
      returnKeyword: Return "return"
      semicolon: ';' ";"  >Whitespace" "
    - ReturnNode
      returnKeyword: Return "return"  >Whitespace" "
      expr: IntegerNode
        token: Integer "1"
      semicolon: ';' ";"  >EndOfLine"\n"
    - GlobalNode
      globalKeyword: Global "global"  >Whitespace" "
      vars: SeparatedNodeList
        - VariableNode
          name: Variable "$a"
        - ',' ","  >Whitespace" "
        - VariableNode
          name: Variable "$b"
      semicolon: ';' ";"  >Whitespace" "
    - StaticNode
      staticKeyword: Static "static"  >Whitespace" "
      vars: SeparatedNodeList
        - StaticVarNode
          var: VariableNode
            name: Variable "$c"
        - ',' ","  >Whitespace" "
        - StaticVarNode
          var: VariableNode
            name: Variable "$d"  >Whitespace" "
          equals: '=' "="  >Whitespace" "
          default: IntegerNode
            token: Integer "1"
      semicolon: ';' ";"  >Whitespace" "
    - EchoNode
      echoKeyword: Echo "echo"  >Whitespace" "
      exprs: SeparatedNodeList
        - IntegerNode
          token: Integer "1"
        - ',' ","  >Whitespace" "
        - IntegerNode
          token: Integer "2"
      semicolon: ';' ";"  >Whitespace" "
    - UnsetNode
      unsetKeyword: Unset "unset"
      openParen: '(' "("
      vars: SeparatedNodeList
        - VariableNode
          name: Variable "$a"
        - ',' ","  >Whitespace" "
        - VariableNode
          name: Variable "$b"
        - ',' ","
      closeParen: ')' ")"
      semicolon: ';' ";"  >EndOfLine"\n"
    - DeclareNode
      declareKeyword: Declare "declare"
      openParen: '(' "("
      items: SeparatedNodeList
        - DeclareItemNode
          name: IdentifierNode
            token: Identifier "strict_types"
          equals: '=' "="
          value: IntegerNode
            token: Integer "1"
      closeParen: ')' ")"
      semicolon: ';' ";"  >Whitespace" "
    - DeclareNode
      declareKeyword: Declare "declare"
      openParen: '(' "("
      items: SeparatedNodeList
        - DeclareItemNode
          name: IdentifierNode
            token: Identifier "ticks"
          equals: '=' "="
          value: IntegerNode
            token: Integer "1"
      closeParen: ')' ")"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
    - DeclareNode
      declareKeyword: Declare "declare"
      openParen: '(' "("
      items: SeparatedNodeList
        - DeclareItemNode
          name: IdentifierNode
            token: Identifier "ticks"
          equals: '=' "="
          value: IntegerNode
            token: Integer "1"
      closeParen: ')' ")"
      colon: ':' ":"  >Whitespace" "
      stmts: NodeList
      endKeyword: Enddeclare "enddeclare"
      semicolon: ';' ";"  >EndOfLine"\n"
    - TryNode
      tryKeyword: Try "try"  >Whitespace" "
      body: BlockNode
        openBrace: '{' "{"  >Whitespace" "
        stmts: NodeList
        closeBrace: '}' "}"  >Whitespace" "
      catches: NodeList
        - CatchNode
          catchKeyword: Catch "catch"  >Whitespace" "
          openParen: '(' "("
          types: SeparatedNodeList
            - NameNode
              token: Identifier "A"  >Whitespace" "
            - '|' "|"  >Whitespace" "
            - NameNode
              token: Identifier "B"  >Whitespace" "
          var: VariableNode
            name: Variable "$e"
          closeParen: ')' ")"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"  >Whitespace" "
        - CatchNode
          catchKeyword: Catch "catch"  >Whitespace" "
          openParen: '(' "("
          types: SeparatedNodeList
            - NameNode
              token: Identifier "C"
          closeParen: ')' ")"  >Whitespace" "
          body: BlockNode
            openBrace: '{' "{"  >Whitespace" "
            stmts: NodeList
            closeBrace: '}' "}"  >Whitespace" "
      finally: FinallyNode
        finallyKeyword: Finally "finally"  >Whitespace" "
        body: BlockNode
          openBrace: '{' "{"  >Whitespace" "
          stmts: NodeList
          closeBrace: '}' "}"  >EndOfLine"\n"
    - GotoNode
      gotoKeyword: Goto "goto"  >Whitespace" "
      label: IdentifierNode
        token: Identifier "end"
      semicolon: ';' ";"  >Whitespace" "
    - LabelNode
      name: IdentifierNode
        token: Identifier "end"
      colon: ':' ":"  >Whitespace" "
    - EmptyStatementNode
      semicolon: ';' ";"  >EndOfLine"\n"
    - BlockNode
      openBrace: '{' "{"  >Whitespace" "
      stmts: NodeList
        - EmptyStatementNode
          semicolon: ';' ";"  >Whitespace" "
      closeBrace: '}' "}"  >EndOfLine"\n"
    - EmptyStatementNode
      semicolon: CloseTag "?>"
    - InlineHtmlNode
      html: InlineHtml "html"
    - EmptyStatementNode
      semicolon: ';' ";"  <OpenTag"<?php "  >Whitespace" "
    - EmptyStatementNode
      semicolon: CloseTag "?>"
  eof: EndOfFile ""
