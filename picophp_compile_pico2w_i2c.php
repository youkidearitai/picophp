#!/usr/bin/env php
<?php
/**
 * picophp_compile.php
 *
 * Minimal compiler for the PicoPHP VM prototype.
 *
 * Usage:
 *   php picophp_compile.php blink.pphp > program_bytecode.h
 *   php picophp_compile.php --demo > program_bytecode.h
 *
 * Supported subset:
 *   <?php                         optional
 *   const NAME = expr;
 *   $var = expr;
 *   call statements: print("x\n"); gpio_write(25, 1);
 *   if (expr) { ... } else { ... }
 *   while (expr) { ... }
 *
 * Expressions:
 *   int, float, string
 *   $var
 *   NAME constants
 *   unary -
 *   + - * / % .
 *   == != < <= > >=
 *   $str[$i]
 *   strlen($s)
 *   native calls: print, sleep_ms, gpio_mode, gpio_write, millis,
 *                 sin, cos, tan, sqrt, abs, chr, ord, bin2hex
 *
 * Not supported yet:
 *   arrays, for, &&, ||, !, return, class, include/eval
 */

declare(strict_types=1);

final class PicoCompileError extends Exception {}

final class Op {
    public const OP_HALT = 0;

    public const OP_CONST = 1;
    public const OP_NULL = 2;
    public const OP_TRUE = 3;
    public const OP_FALSE = 4;

    public const OP_GET_GLOBAL = 5;
    public const OP_SET_GLOBAL = 6;
    public const OP_POP = 7;
    public const OP_DUP = 8;

    public const OP_ADD = 9;
    public const OP_SUB = 10;
    public const OP_MUL = 11;
    public const OP_DIV = 12;
    public const OP_MOD = 13;
    public const OP_NEG = 14;

    public const OP_EQ = 15;
    public const OP_NE = 16;
    public const OP_LT = 17;
    public const OP_LE = 18;
    public const OP_GT = 19;
    public const OP_GE = 20;

    public const OP_JMP = 21;
    public const OP_JMP_IF_FALSE = 22;

    public const OP_CALL_NATIVE = 23;

    public const OP_STRLEN = 24;
    public const OP_STR_INDEX = 25;
    public const OP_CONCAT = 26;

    public const OP_GET_LOCAL = 27;
    public const OP_SET_LOCAL = 28;
    public const OP_CALL = 29;
    public const OP_RET = 30;

    public const OP_BIT_AND = 31;
    public const OP_BIT_OR = 32;
    public const OP_BIT_XOR = 33;
    public const OP_BIT_NOT = 34;
    public const OP_SHL = 35;
    public const OP_SHR = 36;

    public const OP_CONST16 = 37;
}

final class NativeId {
    public const NATIVE_PRINT = 0;
    public const NATIVE_SLEEP_MS = 1;
    public const NATIVE_GPIO_MODE = 2;
    public const NATIVE_GPIO_WRITE = 3;
    public const NATIVE_MILLIS = 4;
    public const NATIVE_SIN = 5;
    public const NATIVE_COS = 6;
    public const NATIVE_TAN = 7;
    public const NATIVE_SQRT = 8;
    public const NATIVE_ABS = 9;
    public const NATIVE_CHR = 10;
    public const NATIVE_ORD = 11;
    public const NATIVE_BIN2HEX = 12;
    public const NATIVE_LED_WRITE = 13;
    public const NATIVE_I2C_INIT = 14;
    public const NATIVE_I2C_WRITE = 15;
    public const NATIVE_I2C_READ = 16;
    public const NATIVE_I2C_WRITE_READ = 17;
    public const NATIVE_I2C_SCAN = 18;
    public const NATIVE_I2C_WRITE_CTRL = 19;
    public const NATIVE_ARENA_RESET = 20;
    public const NATIVE_FORMAT_DEC1 = 21;
    public const NATIVE_KEYBOARD_INIT = 22;
    public const NATIVE_KEYBOARD_KEY = 23;
    public const NATIVE_KEYBOARD_PRESS = 24;
    public const NATIVE_KEYBOARD_RELEASE = 25;
    public const NATIVE_KEYBOARD_TYPE = 26;
    public const NATIVE_KEYBOARD_COMBO = 27;
    public const NATIVE_ADC_INIT = 28;
    public const NATIVE_ADC_READ = 29;
    public const NATIVE_ADC_READ_GPIO = 30;
    public const NATIVE_PWM_INIT = 31;
    public const NATIVE_PWM_WRITE = 32;
    public const NATIVE_PWM_WRITE_PERCENT = 33;
}

const NATIVE_IDS = [
    'print' => NativeId::NATIVE_PRINT,
    'sleep_ms' => NativeId::NATIVE_SLEEP_MS,
    'gpio_mode' => NativeId::NATIVE_GPIO_MODE,
    'gpio_write' => NativeId::NATIVE_GPIO_WRITE,
    'millis' => NativeId::NATIVE_MILLIS,
    'sin' => NativeId::NATIVE_SIN,
    'cos' => NativeId::NATIVE_COS,
    'tan' => NativeId::NATIVE_TAN,
    'sqrt' => NativeId::NATIVE_SQRT,
    'abs' => NativeId::NATIVE_ABS,
    'chr' => NativeId::NATIVE_CHR,
    'ord' => NativeId::NATIVE_ORD,
    'bin2hex' => NativeId::NATIVE_BIN2HEX,
    'led_write' => NativeId::NATIVE_LED_WRITE,
    'i2c_init' => NativeId::NATIVE_I2C_INIT,
    'i2c_write' => NativeId::NATIVE_I2C_WRITE,
    'i2c_read' => NativeId::NATIVE_I2C_READ,
    'i2c_write_read' => NativeId::NATIVE_I2C_WRITE_READ,
    'i2c_scan' => NativeId::NATIVE_I2C_SCAN,
    'i2c_write_ctrl' => NativeId::NATIVE_I2C_WRITE_CTRL,
    'arena_reset' => NativeId::NATIVE_ARENA_RESET,
    'format_dec1' => NativeId::NATIVE_FORMAT_DEC1,
    'keyboard_init' => NativeId::NATIVE_KEYBOARD_INIT,
    'keyboard_key'  => NativeId::NATIVE_KEYBOARD_KEY,
    'keyboard_press' => NativeId::NATIVE_KEYBOARD_PRESS,
    'keyboard_release' => NativeId::NATIVE_KEYBOARD_RELEASE,
    'keyboard_type' => NativeId::NATIVE_KEYBOARD_TYPE,
    'keyboard_combo' => NativeId::NATIVE_KEYBOARD_COMBO,
    'adc_init' => NativeId::NATIVE_ADC_INIT,
    'adc_read' => NativeId::NATIVE_ADC_READ,
    'adc_read_gpio' => NativeId::NATIVE_ADC_READ_GPIO,
    'pwm_init' => NativeId::NATIVE_PWM_INIT,
    'pwm_write' => NativeId::NATIVE_PWM_WRITE,
    'pwm_write_percent' => NativeId::NATIVE_PWM_WRITE_PERCENT,
];

const DEFAULT_CONSTANTS = [
    'HIGH' => 1,
    'LOW' => 0,
    'OUTPUT' => 1,
    'INPUT' => 0,
    'M_PI' => 3.1415927,
];

const OP_NAMES = [
    0 => 'HALT', 1 => 'CONST', 2 => 'NULL', 3 => 'TRUE', 4 => 'FALSE',
    5 => 'GET_GLOBAL', 6 => 'SET_GLOBAL', 7 => 'POP', 8 => 'DUP',
    9 => 'ADD', 10 => 'SUB', 11 => 'MUL', 12 => 'DIV', 13 => 'MOD', 14 => 'NEG',
    15 => 'EQ', 16 => 'NE', 17 => 'LT', 18 => 'LE', 19 => 'GT', 20 => 'GE',
    21 => 'JMP', 22 => 'JMP_IF_FALSE', 23 => 'CALL_NATIVE',
    24 => 'STRLEN', 25 => 'STR_INDEX', 26 => 'CONCAT',
    27 => 'GET_LOCAL', 28 => 'SET_LOCAL', 29 => 'CALL', 30 => 'RET',
    31 => 'BIT_AND', 32 => 'BIT_OR', 33 => 'BIT_XOR', 34 => 'BIT_NOT',
    35 => 'SHL', 36 => 'SHR',
];

const NATIVE_NAMES = [
    0 => 'print', 1 => 'sleep_ms', 2 => 'gpio_mode', 3 => 'gpio_write',
    4 => 'millis', 5 => 'sin', 6 => 'cos', 7 => 'tan', 8 => 'sqrt', 9 => 'abs',
    10 => 'chr', 11 => 'ord', 12 => 'bin2hex', 13 => 'led_write',
    14 => 'i2c_init', 15 => 'i2c_write', 16 => 'i2c_read',
    17 => 'i2c_write_read', 18 => 'i2c_scan', 19 => 'i2c_write_ctrl',
    20 => 'arena_reset', 21 => 'format_dec1',
    22 => 'keyboard_init',
    23 => 'keyboard_key',
    24 => 'keyboard_press',
    25 => 'keyboard_release',
    26 => 'keyboard_type',
    27 => 'keyboard_combo',
    28 => 'adc_init',
    29 => 'adc_read',
    30 => 'adc_read_gpio',
    31 => 'pwm_init',
    32 => 'pwm_write',
    33 => 'pwm_write_percent',
];

final class Token {
    public function __construct(
        public string $kind,
        public string $text,
        public mixed $value,
        public int $line,
        public int $col,
        public string $file = '<stdin>'
    ) {}
}

function strip_php_open_tag(string $src): string {
    $src = ltrim($src);
    if (str_starts_with($src, '<?php')) {
        return substr($src, 5);
    }
    if (str_starts_with($src, '<?')) {
        return substr($src, 2);
    }
    return $src;
}

function decode_php_string_literal(string $raw): string {
    $quote = $raw[0];
    $body = substr($raw, 1, -1);
    $out = '';
    $i = 0;
    $n = strlen($body);

    while ($i < $n) {
        $c = $body[$i];
        if ($c !== '\\') {
            $out .= $c;
            $i++;
            continue;
        }

        $i++;
        if ($i >= $n) {
            throw new PicoCompileError('unterminated escape sequence');
        }

        $e = $body[$i];
        $i++;

        if ($quote === "'") {
            // PHP single quoted strings only treat \\ and \' specially.
            if ($e === '\\') {
                $out .= '\\';
            } elseif ($e === "'") {
                $out .= "'";
            } else {
                $out .= '\\' . $e;
            }
            continue;
        }

        switch ($e) {
            case 'n': $out .= "\n"; break;
            case 'r': $out .= "\r"; break;
            case 't': $out .= "\t"; break;
            case '0': $out .= "\0"; break;
            case '\\': $out .= '\\'; break;
            case '"': $out .= '"'; break;
            case "'": $out .= "'"; break;
            case 'x':
                $hex = substr($body, $i, 2);
                if (strlen($hex) !== 2 || !preg_match('/^[0-9a-fA-F]{2}$/', $hex)) {
                    throw new PicoCompileError('\\x escape requires two hex digits');
                }
                $out .= chr(hexdec($hex));
                $i += 2;
                break;
            default:
                // Permissive, PHP-ish enough for now.
                $out .= $e;
                break;
        }
    }

    return $out;
}

/**
 * @return list<Token>
 */
function lex_source(string $src, string $defaultFile = '<stdin>'): array {
    $src = strip_php_open_tag($src);
    $tokens = [];
    $i = 0;
    $len = strlen($src);
    $line = 1;
    $col = 1;
    $currentFile = $defaultFile;
    $lineBase = 0;

    $logicalLine = function () use (&$line, &$lineBase): int {
        $n = $line - $lineBase;
        return $n > 0 ? $n : 1;
    };

    $advance = function (string $ch) use (&$line, &$col): void {
        if ($ch === "\n") {
            $line++;
            $col = 1;
        } else {
            $col++;
        }
    };

    $keywords = [
        'const' => true,
        'if' => true,
        'else' => true,
        'while' => true,
        'function' => true,
        'return' => true,
        'true' => true,
        'false' => true,
        'null' => true,
    ];

    $twoChar = [
        '==' => true,
        '!=' => true,
        '<=' => true,
        '>=' => true,
        '<<' => true,
        '>>' => true,
    ];

    $oneChar = '{}()[];,=+-*/%.<>&|^~';

    while ($i < $len) {
        $ch = $src[$i];

        if (ctype_space($ch)) {
            $advance($ch);
            $i++;
            continue;
        }

        if (substr($src, $i, 2) === '//') {
            $commentStartLine = $line;
            $j = $i;
            while ($j < $len && $src[$j] !== "\n") {
                $j++;
            }
            $comment = substr($src, $i, $j - $i);
            if (preg_match('/^\/\/__PICOPHP_FILE__:([^:]+)(?::([0-9]+))?$/', $comment, $m)) {
                $decoded = base64_decode(trim($m[1]), true);
                if ($decoded !== false && $decoded !== '') {
                    $currentFile = $decoded;
                    $sourceLine = isset($m[2]) ? max(1, (int)$m[2]) : 1;
                    // The next physical source line corresponds to $sourceLine of this file.
                    $lineBase = $commentStartLine - ($sourceLine - 1);
                }
            }
            while ($i < $len && $src[$i] !== "\n") {
                $advance($src[$i]);
                $i++;
            }
            continue;
        }

        if (substr($src, $i, 2) === '/*') {
            $startLine = $line;
            $startCol = $col;
            $i += 2;
            $col += 2;
            while ($i < $len && substr($src, $i, 2) !== '*/') {
                $advance($src[$i]);
                $i++;
            }
            if ($i >= $len) {
                throw new PicoCompileError("unterminated comment at {$startLine}:{$startCol}");
            }
            $i += 2;
            $col += 2;
            continue;
        }

        $startLine = $logicalLine();
        $startCol = $col;
        $startFile = $currentFile;

        if ($ch === '$') {
            $j = $i + 1;
            if ($j >= $len || !(ctype_alpha($src[$j]) || $src[$j] === '_')) {
                throw new PicoCompileError("bad variable at {$line}:{$col}");
            }
            $j++;
            while ($j < $len && (ctype_alnum($src[$j]) || $src[$j] === '_')) {
                $j++;
            }
            $text = substr($src, $i, $j - $i);
            $tokens[] = new Token('VAR', $text, substr($text, 1), $startLine, $startCol, $startFile);
            while ($i < $j) {
                $advance($src[$i]);
                $i++;
            }
            continue;
        }

        if (ctype_alpha($ch) || $ch === '_') {
            $j = $i + 1;
            while ($j < $len && (ctype_alnum($src[$j]) || $src[$j] === '_')) {
                $j++;
            }
            $text = substr($src, $i, $j - $i);
            $kind = isset($keywords[$text]) ? $text : 'IDENT';
            $tokens[] = new Token($kind, $text, $text, $startLine, $startCol, $startFile);
            while ($i < $j) {
                $advance($src[$i]);
                $i++;
            }
            continue;
        }

        if (ctype_digit($ch)) {
            $j = $i;
            while ($j < $len && ctype_digit($src[$j])) {
                $j++;
            }
            $isFloat = false;
            if ($j < $len && $src[$j] === '.' && $j + 1 < $len && ctype_digit($src[$j + 1])) {
                $isFloat = true;
                $j++;
                while ($j < $len && ctype_digit($src[$j])) {
                    $j++;
                }
            }
            $text = substr($src, $i, $j - $i);
            $tokens[] = new Token($isFloat ? 'FLOAT' : 'INT', $text, $isFloat ? (float)$text : (int)$text, $startLine, $startCol, $startFile);
            while ($i < $j) {
                $advance($src[$i]);
                $i++;
            }
            continue;
        }

        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $j = $i + 1;
            $escaped = false;
            while ($j < $len) {
                $c = $src[$j];
                if ($escaped) {
                    $escaped = false;
                    $j++;
                    continue;
                }
                if ($c === '\\') {
                    $escaped = true;
                    $j++;
                    continue;
                }
                if ($c === $quote) {
                    break;
                }
                $j++;
            }
            if ($j >= $len) {
                throw new PicoCompileError("unterminated string at {$line}:{$col}");
            }
            $raw = substr($src, $i, $j - $i + 1);
            $tokens[] = new Token('STRING', $raw, decode_php_string_literal($raw), $startLine, $startCol, $startFile);
            while ($i <= $j) {
                $advance($src[$i]);
                $i++;
            }
            continue;
        }

        $two = substr($src, $i, 2);
        if (isset($twoChar[$two])) {
            $tokens[] = new Token($two, $two, $two, $startLine, $startCol, $startFile);
            $advance($src[$i]);
            $advance($src[$i + 1]);
            $i += 2;
            continue;
        }

        if (str_contains($oneChar, $ch)) {
            $tokens[] = new Token($ch, $ch, $ch, $startLine, $startCol, $startFile);
            $advance($ch);
            $i++;
            continue;
        }

        throw new PicoCompileError("unexpected character " . var_export($ch, true) . " at {$line}:{$col}");
    }

    $tokens[] = new Token('EOF', '', null, $line, $col, $currentFile);
    return $tokens;
}

abstract class Expr {
    public string $file = '<stdin>';
    public int $line = 1;
    public int $col = 1;
}
abstract class Stmt {
    public string $file = '<stdin>';
    public int $line = 1;
    public int $col = 1;
}

function attach_loc(object $node, object $loc): object {
    $node->file = $loc->file ?? '<stdin>';
    $node->line = (int)($loc->line ?? 1);
    $node->col = (int)($loc->col ?? 1);
    return $node;
}

final class ProgramNode {
    /** @param list<Stmt> $stmts */
    public function __construct(public array $stmts) {}
}

final class ConstStmt extends Stmt {
    public function __construct(public string $name, public Expr $expr) {}
}

final class AssignStmt extends Stmt {
    public function __construct(public string $name, public Expr $expr) {}
}

final class ExprStmt extends Stmt {
    public function __construct(public Expr $expr) {}
}

final class IfStmt extends Stmt {
    /** @param list<Stmt> $thenBody @param list<Stmt> $elseBody */
    public function __construct(public Expr $cond, public array $thenBody, public array $elseBody) {}
}

final class WhileStmt extends Stmt {
    /** @param list<Stmt> $body */
    public function __construct(public Expr $cond, public array $body) {}
}

final class FunctionStmt extends Stmt {
    /** @param list<string> $params @param list<Stmt> $body */
    public function __construct(public string $name, public array $params, public array $body) {}
}

final class ReturnStmt extends Stmt {
    public function __construct(public ?Expr $expr) {}
}

final class LiteralExpr extends Expr {
    public function __construct(public mixed $value) {}
}

final class VarExpr extends Expr {
    public function __construct(public string $name) {}
}

final class NameExpr extends Expr {
    public function __construct(public string $name) {}
}

final class UnaryExpr extends Expr {
    public function __construct(public string $op, public Expr $expr) {}
}

final class BinaryExpr extends Expr {
    public function __construct(public string $op, public Expr $left, public Expr $right) {}
}

final class CallExpr extends Expr {
    /** @param list<Expr> $args */
    public function __construct(public string $name, public array $args) {}
}

final class IndexExpr extends Expr {
    public function __construct(public Expr $target, public Expr $index) {}
}

final class Parser {
    /** @param list<Token> $tokens */
    public function __construct(private array $tokens, private int $i = 0) {}

    private function cur(): Token {
        return $this->tokens[$this->i];
    }

    private function match(string ...$kinds): ?Token {
        $cur = $this->cur();
        if (in_array($cur->kind, $kinds, true)) {
            $this->i++;
            return $cur;
        }
        return null;
    }

    private function expect(string $kind): Token {
        $t = $this->match($kind);
        if ($t === null) {
            $c = $this->cur();
            throw new PicoCompileError("expected {$kind}, got {$c->kind} at {$c->line}:{$c->col}");
        }
        return $t;
    }

    public function parse(): ProgramNode {
        $stmts = [];
        while ($this->cur()->kind !== 'EOF') {
            $stmts[] = $this->parseStmt();
        }
        return new ProgramNode($stmts);
    }

    /** @return list<Stmt> */
    private function parseBlock(): array {
        $this->expect('{');
        $body = [];
        while ($this->cur()->kind !== '}') {
            if ($this->cur()->kind === 'EOF') {
                throw new PicoCompileError('unterminated block');
            }
            $body[] = $this->parseStmt();
        }
        $this->expect('}');
        return $body;
    }

    private function parseStmt(): Stmt {
        $stmtTok = $this->cur();

        if ($this->match('const') !== null) {
            $name = $this->expect('IDENT')->value;
            $this->expect('=');
            $expr = $this->parseExpr();
            $this->expect(';');
            return attach_loc(new ConstStmt($name, $expr), $stmtTok);
        }

        if ($this->match('function') !== null) {
            $name = $this->expect('IDENT')->value;
            $this->expect('(');
            $params = [];
            if ($this->cur()->kind !== ')') {
                while (true) {
                    $params[] = $this->expect('VAR')->value;
                    if ($this->match(',') === null) {
                        break;
                    }
                }
            }
            $this->expect(')');
            return attach_loc(new FunctionStmt($name, $params, $this->parseBlock()), $stmtTok);
        }

        if ($this->match('return') !== null) {
            if ($this->match(';') !== null) {
                return attach_loc(new ReturnStmt(null), $stmtTok);
            }
            $expr = $this->parseExpr();
            $this->expect(';');
            return attach_loc(new ReturnStmt($expr), $stmtTok);
        }

        if ($this->match('if') !== null) {
            $this->expect('(');
            $cond = $this->parseExpr();
            $this->expect(')');
            $thenBody = $this->parseBlock();
            $elseBody = [];
            if ($this->match('else') !== null) {
                $elseBody = $this->parseBlock();
            }
            return attach_loc(new IfStmt($cond, $thenBody, $elseBody), $stmtTok);
        }

        if ($this->match('while') !== null) {
            $this->expect('(');
            $cond = $this->parseExpr();
            $this->expect(')');
            return attach_loc(new WhileStmt($cond, $this->parseBlock()), $stmtTok);
        }

        if ($this->cur()->kind === 'VAR' && $this->tokens[$this->i + 1]->kind === '=') {
            $name = $this->expect('VAR')->value;
            $this->expect('=');
            $expr = $this->parseExpr();
            $this->expect(';');
            return attach_loc(new AssignStmt($name, $expr), $stmtTok);
        }

        $expr = $this->parseExpr();
        $this->expect(';');
        return attach_loc(new ExprStmt($expr), $stmtTok);
    }

    private function parseExpr(): Expr {
        return $this->parseEquality();
    }

    private function parseEquality(): Expr {
        $expr = $this->parseComparison();
        while (($t = $this->match('==', '!=')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseComparison()), $t);
        }
        return $expr;
    }

    private function parseComparison(): Expr {
        $expr = $this->parseBitOr();
        while (($t = $this->match('<', '<=', '>', '>=')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseBitOr()), $t);
        }
        return $expr;
    }

    private function parseBitOr(): Expr {
        $expr = $this->parseBitXor();
        while (($t = $this->match('|')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseBitXor()), $t);
        }
        return $expr;
    }

    private function parseBitXor(): Expr {
        $expr = $this->parseBitAnd();
        while (($t = $this->match('^')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseBitAnd()), $t);
        }
        return $expr;
    }

    private function parseBitAnd(): Expr {
        $expr = $this->parseShift();
        while (($t = $this->match('&')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseShift()), $t);
        }
        return $expr;
    }

    private function parseShift(): Expr {
        $expr = $this->parseTerm();
        while (($t = $this->match('<<', '>>')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseTerm()), $t);
        }
        return $expr;
    }

    private function parseTerm(): Expr {
        $expr = $this->parseFactor();
        while (($t = $this->match('+', '-', '.')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseFactor()), $t);
        }
        return $expr;
    }

    private function parseFactor(): Expr {
        $expr = $this->parseUnary();
        while (($t = $this->match('*', '/', '%')) !== null) {
            $expr = attach_loc(new BinaryExpr($t->kind, $expr, $this->parseUnary()), $t);
        }
        return $expr;
    }

    private function parseUnary(): Expr {
        if (($t = $this->match('-')) !== null) {
            return attach_loc(new UnaryExpr($t->kind, $this->parseUnary()), $t);
        }
        if (($t = $this->match('~')) !== null) {
            return attach_loc(new UnaryExpr($t->kind, $this->parseUnary()), $t);
        }
        return $this->parsePostfix();
    }

    private function parsePostfix(): Expr {
        $expr = $this->parsePrimary();
        while (true) {
            if ($this->match('[') !== null) {
                $idx = $this->parseExpr();
                $this->expect(']');
                $expr = attach_loc(new IndexExpr($expr, $idx), $expr);
                continue;
            }
            return $expr;
        }
    }

    private function parsePrimary(): Expr {
        if (($t = $this->match('INT', 'FLOAT', 'STRING')) !== null) {
            return attach_loc(new LiteralExpr($t->value), $t);
        }
        if (($t = $this->match('true')) !== null) {
            return attach_loc(new LiteralExpr(true), $t);
        }
        if (($t = $this->match('false')) !== null) {
            return attach_loc(new LiteralExpr(false), $t);
        }
        if (($t = $this->match('null')) !== null) {
            return attach_loc(new LiteralExpr(null), $t);
        }
        if (($t = $this->match('VAR')) !== null) {
            return attach_loc(new VarExpr($t->value), $t);
        }
        if (($t = $this->match('IDENT')) !== null) {
            $name = $t->value;
            if ($this->match('(') !== null) {
                $args = [];
                if ($this->cur()->kind !== ')') {
                    while (true) {
                        $args[] = $this->parseExpr();
                        if ($this->match(',') === null) {
                            break;
                        }
                    }
                }
                $this->expect(')');
                return attach_loc(new CallExpr($name, $args), $t);
            }
            return attach_loc(new NameExpr($name), $t);
        }
        if ($this->match('(') !== null) {
            $expr = $this->parseExpr();
            $this->expect(')');
            return $expr;
        }
        $c = $this->cur();
        throw new PicoCompileError("expected expression, got {$c->kind} at {$c->line}:{$c->col}");
    }
}

final class FunctionInfo {
    /** @param list<string> $params @param list<Stmt> $body */
    public function __construct(
        public string $name,
        public array $params,
        public array $body,
        public int $id,
        public int $entry = 0,
        public int $localCount = 0,
    ) {}
}

final class ConstValue {
    public function __construct(public string $type, public mixed $value) {}

    public function key(): string {
        return match ($this->type) {
            'null' => 'null:',
            'bool' => 'bool:' . ($this->value ? '1' : '0'),
            'int' => 'int:' . (string)$this->value,
            'float' => 'float:' . sprintf('%.9g', (float)$this->value),
            'string' => 'string:' . bin2hex($this->value),
            default => throw new LogicException('bad const type'),
        };
    }
}

final class Compiler {
    /** @var list<int> */
    private array $code = [];
    /** @var list<string> */
    private array $debugFiles = [];
    /** @var array<string,int> */
    private array $debugFileIds = [];
    /** @var list<array{ip:int,file_id:int,line:int}> */
    private array $debugLines = [];

    /** @var list<ConstValue> */
    private array $consts = [];

    /** @var array<string,int> */
    private array $constMap = [];

    /** @var array<string,int> */
    private array $globals = [];

    /** @var array<string,mixed> */
    private array $compileConsts;

    /** @var array<string,FunctionInfo> */
    private array $functions = [];

    /** @var array<string,int> */
    private array $locals = [];

    private bool $inFunction = false;
    private ?FunctionInfo $currentFunction = null;

    private ?string $debugPathBase = null;

    public function __construct() {
        $this->compileConsts = DEFAULT_CONSTANTS;
    }

    private function shortDebugPath(string $file): string {
        $file = str_replace('\\', '/', $file);

        if ($this->debugPathBase !== null) {
            $base = rtrim(str_replace('\\', '/', $this->debugPathBase), '/') . '/';

            if (str_starts_with($file, $base)) {
                return substr($file, strlen($base));
            }
        }

        // /path/to/lib/bmp280.pphp -> lib/bmp280.pphp を拾いたい場合
        $pos = strpos($file, '/lib/');
        if ($pos !== false) {
            return substr($file, $pos + 1);
        }

        return basename($file);
    }

    private function emit(int ...$bytes): int {
        $pos = count($this->code);
        foreach ($bytes as $b) {
            $this->code[] = $b & 0xff;
        }
        return $pos;
    }

    private function emitU16(int $v): void {
        if ($v < 0 || $v > 0xffff) {
            throw new PicoCompileError("u16 operand out of range: {$v}");
        }
        $this->code[] = $v & 0xff;
        $this->code[] = ($v >> 8) & 0xff;
    }

    private function emitConstId(int $id): void {
        if ($id < 0 || $id > 0xffff) {
            throw new PicoCompileError("constant id out of range: {$id}");
        }
        if ($id <= 0xff) {
            $this->emit(Op::OP_CONST, $id);
            return;
        }
        $this->emit(Op::OP_CONST16);
        $this->emitU16($id);
    }

    private function emitI16Placeholder(): int {
        $pos = count($this->code);
        $this->code[] = 0;
        $this->code[] = 0;
        return $pos;
    }

    private function patchI16Relative(int $pos, int $target): void {
        $after = $pos + 2;
        $off = $target - $after;
        if ($off < -32768 || $off > 32767) {
            throw new PicoCompileError('jump offset out of i16 range');
        }
        if ($off < 0) {
            $off += 0x10000;
        }
        $this->code[$pos] = $off & 0xff;
        $this->code[$pos + 1] = ($off >> 8) & 0xff;
    }

    private function addConst(mixed $value): int {
        if ($value === null) {
            $c = new ConstValue('null', null);
        } elseif (is_bool($value)) {
            $c = new ConstValue('bool', $value);
        } elseif (is_int($value)) {
            if ($value < -2147483648 || $value > 2147483647) {
                throw new PicoCompileError("int32 constant out of range: {$value}");
            }
            $c = new ConstValue('int', $value);
        } elseif (is_float($value)) {
            $c = new ConstValue('float', $value);
        } elseif (is_string($value)) {
            $c = new ConstValue('string', $value);
        } else {
            throw new PicoCompileError('unsupported constant');
        }

        $key = $c->key();
        if (isset($this->constMap[$key])) {
            return $this->constMap[$key];
        }
        if (count($this->consts) >= 1024) {
            throw new PicoCompileError('too many constants');
        }
        $id = count($this->consts);
        $this->consts[] = $c;
        $this->constMap[$key] = $id;
        return $id;
    }

    private function globalSlot(string $name): int {
        if (!array_key_exists($name, $this->globals)) {
            if (count($this->globals) >= 256) {
                throw new PicoCompileError('too many globals');
            }
            $this->globals[$name] = count($this->globals);
        }
        return $this->globals[$name];
    }

    private function localSlot(string $name): int {
        if (!array_key_exists($name, $this->locals)) {
            if (count($this->locals) >= 256) {
                throw new PicoCompileError('too many locals');
            }
            $this->locals[$name] = count($this->locals);
        }
        return $this->locals[$name];
    }

    private function collectFunctions(ProgramNode $program): void {
        foreach ($program->stmts as $stmt) {
            if ($stmt instanceof FunctionStmt) {
                if (array_key_exists($stmt->name, $this->functions)) {
                    throw new PicoCompileError("duplicate function {$stmt->name}");
                }
                if (count($stmt->params) > 255) {
                    throw new PicoCompileError("too many parameters in {$stmt->name}");
                }
                $this->functions[$stmt->name] = new FunctionInfo(
                    $stmt->name,
                    $stmt->params,
                    $stmt->body,
                    count($this->functions),
                );
            }
        }
    }

    public function compile(ProgramNode $program): void {
        $this->collectFunctions($program);

        foreach ($program->stmts as $stmt) {
            if (!$stmt instanceof FunctionStmt) {
                $this->compileStmt($stmt);
            }
        }
        $this->emit(Op::OP_HALT);

        foreach ($this->functions as $fn) {
            $this->compileFunction($fn);
        }
    }

    private function compileFunction(FunctionInfo $fn): void {
        $oldInFunction = $this->inFunction;
        $oldCurrent = $this->currentFunction;
        $oldLocals = $this->locals;

        $this->inFunction = true;
        $this->currentFunction = $fn;
        $this->locals = [];

        $fn->entry = count($this->code);
        foreach ($fn->params as $param) {
            $this->localSlot($param);
        }

        foreach ($fn->body as $stmt) {
            $this->compileStmt($stmt);
        }

        // Implicit return null.
        $this->emit(Op::OP_NULL);
        $this->emit(Op::OP_RET);

        $fn->localCount = count($this->locals);

        $this->inFunction = $oldInFunction;
        $this->currentFunction = $oldCurrent;
        $this->locals = $oldLocals;
    }

    private function evalConstExpr(Expr $expr): mixed {
        if ($expr instanceof LiteralExpr) {
            return $expr->value;
        }
        if ($expr instanceof NameExpr) {
            if (!array_key_exists($expr->name, $this->compileConsts)) {
                throw new PicoCompileError("unknown constant {$expr->name}");
            }
            return $this->compileConsts[$expr->name];
        }
        if ($expr instanceof UnaryExpr) {
            $v = $this->evalConstExpr($expr->expr);
            if ($expr->op === '-' && (is_int($v) || is_float($v))) {
                return -$v;
            }
        }
        if ($expr instanceof BinaryExpr) {
            $a = $this->evalConstExpr($expr->left);
            $b = $this->evalConstExpr($expr->right);
            return match ($expr->op) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => (float)$a / (float)$b,
                '%' => (int)$a % (int)$b,
                '.' => is_string($a) && is_string($b)
                    ? $a . $b
                    : throw new PicoCompileError('constant . requires strings'),
                default => throw new PicoCompileError('unsupported constant operator'),
            };
        }

        throw new PicoCompileError('const initializer must be a constant expression');
    }

    private function debugFileId(string $file): int {
        $file = $this->shortDebugPath($file);

        if (isset($this->debugFileIds[$file])) {
            return $this->debugFileIds[$file];
        }
        $id = count($this->debugFiles);
        $this->debugFiles[] = $file;
        $this->debugFileIds[$file] = $id;
        return $id;
    }

    private function markDebug(object $node): void {
        $ip = count($this->code);
        $fileId = $this->debugFileId($node->file ?? '<stdin>');
        $line = (int)($node->line ?? 1);
        $last = $this->debugLines[count($this->debugLines) - 1] ?? null;
        if ($last !== null && $last['ip'] === $ip && $last['file_id'] === $fileId && $last['line'] === $line) {
            return;
        }
        $this->debugLines[] = ['ip' => $ip, 'file_id' => $fileId, 'line' => $line];
    }

    private function compileStmt(Stmt $stmt): void {
        $this->markDebug($stmt);
        if ($stmt instanceof ConstStmt) {
            $this->compileConsts[$stmt->name] = $this->evalConstExpr($stmt->expr);
            return;
        }

        if ($stmt instanceof FunctionStmt) {
            // Function declarations are handled by collectFunctions()/compileFunction().
            return;
        }

        if ($stmt instanceof ReturnStmt) {
            if (!$this->inFunction) {
                throw new PicoCompileError('return outside function');
            }
            if ($stmt->expr === null) {
                $this->emit(Op::OP_NULL);
            } else {
                $this->compileExpr($stmt->expr);
            }
            $this->emit(Op::OP_RET);
            return;
        }

        if ($stmt instanceof AssignStmt) {
            $this->compileExpr($stmt->expr);
            if ($this->inFunction) {
                $this->emit(Op::OP_SET_LOCAL, $this->localSlot($stmt->name));
            } else {
                $this->emit(Op::OP_SET_GLOBAL, $this->globalSlot($stmt->name));
            }
            $this->emit(Op::OP_POP);
            return;
        }

        if ($stmt instanceof ExprStmt) {
            $this->compileExpr($stmt->expr);
            $this->emit(Op::OP_POP);
            return;
        }

        if ($stmt instanceof IfStmt) {
            $this->compileExpr($stmt->cond);
            $this->emit(Op::OP_JMP_IF_FALSE);
            $falseJumpPos = $this->emitI16Placeholder();

            $this->emit(Op::OP_POP); // true path condition
            foreach ($stmt->thenBody as $s) {
                $this->compileStmt($s);
            }

            $this->emit(Op::OP_JMP);
            $endJumpPos = $this->emitI16Placeholder();

            $elseStart = count($this->code);
            $this->patchI16Relative($falseJumpPos, $elseStart);

            $this->emit(Op::OP_POP); // false path condition
            foreach ($stmt->elseBody as $s) {
                $this->compileStmt($s);
            }

            $end = count($this->code);
            $this->patchI16Relative($endJumpPos, $end);
            return;
        }

        if ($stmt instanceof WhileStmt) {
            $loopStart = count($this->code);

            $this->compileExpr($stmt->cond);
            $this->emit(Op::OP_JMP_IF_FALSE);
            $exitJumpPos = $this->emitI16Placeholder();

            $this->emit(Op::OP_POP); // condition on body path
            foreach ($stmt->body as $s) {
                $this->compileStmt($s);
            }

            $this->emit(Op::OP_JMP);
            $backPos = $this->emitI16Placeholder();
            $this->patchI16Relative($backPos, $loopStart);

            $exitPos = count($this->code);
            $this->patchI16Relative($exitJumpPos, $exitPos);
            $this->emit(Op::OP_POP); // condition on exit path
            return;
        }

        throw new PicoCompileError('unsupported statement');
    }

    private function compileExpr(Expr $expr): void {
        $this->markDebug($expr);
        if ($expr instanceof LiteralExpr) {
            if ($expr->value === null) {
                $this->emit(Op::OP_NULL);
            } elseif ($expr->value === true) {
                $this->emit(Op::OP_TRUE);
            } elseif ($expr->value === false) {
                $this->emit(Op::OP_FALSE);
            } else {
                $this->emitConstId($this->addConst($expr->value));
            }
            return;
        }

        if ($expr instanceof VarExpr) {
            if ($this->inFunction) {
                $this->emit(Op::OP_GET_LOCAL, $this->localSlot($expr->name));
            } else {
                $this->emit(Op::OP_GET_GLOBAL, $this->globalSlot($expr->name));
            }
            return;
        }

        if ($expr instanceof NameExpr) {
            if (!array_key_exists($expr->name, $this->compileConsts)) {
                throw new PicoCompileError("unknown constant {$expr->name}");
            }
            $this->compileExpr(new LiteralExpr($this->compileConsts[$expr->name]));
            return;
        }

        if ($expr instanceof UnaryExpr) {
            $this->compileExpr($expr->expr);
            if ($expr->op === '-') {
                $this->emit(Op::OP_NEG);
                return;
            }
            if ($expr->op === '~') {
                $this->emit(Op::OP_BIT_NOT);
                return;
            }
            throw new PicoCompileError("unsupported unary operator {$expr->op}");
        }

        if ($expr instanceof BinaryExpr) {
            $this->compileExpr($expr->left);
            $this->compileExpr($expr->right);

            $op = match ($expr->op) {
                '+' => Op::OP_ADD,
                '-' => Op::OP_SUB,
                '*' => Op::OP_MUL,
                '/' => Op::OP_DIV,
                '%' => Op::OP_MOD,
                '==' => Op::OP_EQ,
                '!=' => Op::OP_NE,
                '<' => Op::OP_LT,
                '<=' => Op::OP_LE,
                '>' => Op::OP_GT,
                '>=' => Op::OP_GE,
                '.' => Op::OP_CONCAT,
                '&' => Op::OP_BIT_AND,
                '|' => Op::OP_BIT_OR,
                '~' => Op::OP_BIT_NOT,
                '^' => Op::OP_BIT_XOR,
                '<<' => Op::OP_SHL,
                '>>' => Op::OP_SHR,
                default => throw new PicoCompileError("unsupported binary operator {$expr->op}"),
            };
            $this->emit($op);
            return;
        }

        if ($expr instanceof IndexExpr) {
            $this->compileExpr($expr->target);
            $this->compileExpr($expr->index);
            $this->emit(Op::OP_STR_INDEX);
            return;
        }

        if ($expr instanceof CallExpr) {
            if ($expr->name === 'strlen') {
                if (count($expr->args) !== 1) {
                    throw new PicoCompileError('strlen expects 1 argument');
                }
                $this->compileExpr($expr->args[0]);
                $this->emit(Op::OP_STRLEN);
                return;
            }

            if (array_key_exists($expr->name, $this->functions)) {
                $fn = $this->functions[$expr->name];
                if (count($expr->args) !== count($fn->params)) {
                    throw new PicoCompileError("function {$expr->name} expects " . count($fn->params) . ' arguments');
                }
                foreach ($expr->args as $arg) {
                    $this->compileExpr($arg);
                }
                $this->emit(Op::OP_CALL, $fn->id, count($expr->args));
                return;
            }

            if (!array_key_exists($expr->name, NATIVE_IDS)) {
                throw new PicoCompileError("unknown function {$expr->name}");
            }
            if (count($expr->args) > 255) {
                throw new PicoCompileError('too many call arguments');
            }
            foreach ($expr->args as $arg) {
                $this->compileExpr($arg);
            }
            $this->emit(Op::OP_CALL_NATIVE, NATIVE_IDS[$expr->name], count($expr->args));
            return;
        }

        throw new PicoCompileError('unsupported expression');
    }

    private function cFloat(float $v): string {
        $s = sprintf('%.9g', $v);
        if (!str_contains($s, '.') && !str_contains($s, 'e') && !str_contains($s, 'E')) {
            $s .= '.0';
        }
        return $s . 'f';
    }


    /** @return list<int> */
    public function getCode(): array {
        return $this->code;
    }

    /** @return list<ConstValue> */
    public function getConsts(): array {
        return $this->consts;
    }

    /** @return array<string,int> */
    public function getGlobals(): array {
        return $this->globals;
    }

    /** @return array<string,FunctionInfo> */
    public function getFunctions(): array {
        return $this->functions;
    }

    public function dumpOpcodes(): string {
        return disassemble_code($this->code, $this->consts, $this->globals, $this->functions);
    }

    private function cEscapeBytes(string $bytes): string {
        $out = [];
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $out[] = sprintf('0x%02x', ord($bytes[$i]));
        }
        return implode(', ', $out);
    }

    private function cStringLiteral(string $s): string {
        return '"' . addcslashes($s, "\\\"\n\r\t") . '"';
    }

    public function emitCHeader(string $symbolPrefix = 'picophp_program'): string {
        $lines = [];
        $lines[] = '// Generated by picophp_compile.php';
        $lines[] = '#include <stdint.h>';
        $lines[] = '';
        $lines[] = '// Constant layout expected by picophp_vm_mvp.c:';
        $lines[] = '//   VAL_NULL=0, VAL_BOOL=1, VAL_INT=2, VAL_FLOAT=3, VAL_STRING=4';
        $lines[] = '//   STR_FLAG_FLASH=0x0001';
        $lines[] = '';

        foreach ($this->consts as $i => $c) {
            if ($c->type === 'string') {
                $lines[] = "static const uint8_t {$symbolPrefix}_str_{$i}[] = { " . $this->cEscapeBytes($c->value) . " };";
            }
        }
        if (array_any($this->consts, fn(ConstValue $c): bool => $c->type === 'string')) {
            $lines[] = '';
        }

        $lines[] = "static const Value {$symbolPrefix}_consts[] = {";
        foreach ($this->consts as $i => $c) {
            switch ($c->type) {
                case 'null':
                    $lines[] = "    /* {$i} */ { .type = VAL_NULL, .as.i = 0 },";
                    break;
                case 'bool':
                    $lines[] = "    /* {$i} */ { .type = VAL_BOOL, .as.b = " . ($c->value ? 'true' : 'false') . " },";
                    break;
                case 'int':
                    $lines[] = "    /* {$i} */ { .type = VAL_INT, .as.i = {$c->value} },";
                    break;
                case 'float':
                    $lines[] = "    /* {$i} */ { .type = VAL_FLOAT, .as.f = " . $this->cFloat((float)$c->value) . " },";
                    break;
                case 'string':
                    $len = strlen($c->value);
                    $lines[] = "    /* {$i} */ { .type = VAL_STRING, .as.s = { .len = {$len}, .flags = STR_FLAG_FLASH, .data = {$symbolPrefix}_str_{$i} } },";
                    break;
            }
        }
        $lines[] = '};';
        $lines[] = "static const unsigned {$symbolPrefix}_const_count = " . count($this->consts) . ';';
        $lines[] = '';

        $lines[] = "static const uint8_t {$symbolPrefix}_code[] = {";
        for ($i = 0, $n = count($this->code); $i < $n; $i += 12) {
            $chunk = array_slice($this->code, $i, 12);
            $lines[] = '    ' . implode(', ', array_map(fn(int $b): string => sprintf('0x%02x', $b), $chunk)) . ',';
        }
        $lines[] = '};';
        $lines[] = "static const unsigned {$symbolPrefix}_code_len = " . count($this->code) . ';';
        $lines[] = '';

        $lines[] = "static const FunctionInfo {$symbolPrefix}_funcs[] = {";
        foreach ($this->functions as $fn) {
            $lines[] = "    /* {$fn->id}: {$fn->name} */ { .entry = {$fn->entry}, .arity = " . count($fn->params) . ", .local_count = {$fn->localCount} },";
        }
        $lines[] = '};';
        $lines[] = "static const unsigned {$symbolPrefix}_func_count = " . count($this->functions) . ';';
        $lines[] = '';

        $lines[] = '// Global slots:';
        asort($this->globals);
        foreach ($this->globals as $name => $slot) {
            $lines[] = "//   \${$name}: {$slot}";
        }
        $lines[] = '';

        $lines[] = 'typedef struct {';
        $lines[] = '    uint16_t ip;';
        $lines[] = '    uint16_t file_id;';
        $lines[] = '    uint16_t line;';
        $lines[] = '} PicoPhpDebugLine;';
        $lines[] = '';
        $lines[] = '#define PICOPHP_PROGRAM_HAS_DEBUG_LINES 1';
        $lines[] = "static const char *{$symbolPrefix}_debug_files[] = {";
        foreach ($this->debugFiles as $file) {
            $lines[] = '    ' . $this->cStringLiteral($file) . ',';
        }
        $lines[] = '};';
        $lines[] = "static const unsigned {$symbolPrefix}_debug_file_count = " . count($this->debugFiles) . ';';
        $lines[] = '';
        $lines[] = "static const PicoPhpDebugLine {$symbolPrefix}_debug_lines[] = {";
        foreach ($this->debugLines as $d) {
            $lines[] = "    { {$d['ip']}, {$d['file_id']}, {$d['line']} },";
        }
        $lines[] = '};';
        $lines[] = "static const unsigned {$symbolPrefix}_debug_line_count = " . count($this->debugLines) . ';';
        $lines[] = '';
        return implode("\n", $lines);
    }
}

if (!function_exists('array_any')) {
    /**
     * @template T
     * @param array<T> $array
     * @param callable(T):bool $callback
     */
    function array_any(array $array, callable $callback): bool {
        foreach ($array as $v) {
            if ($callback($v)) {
                return true;
            }
        }
        return false;
    }
}


function read_u8_from_code(array $code, int &$ip): int {
    if ($ip >= count($code)) {
        throw new CompileError('disassembler reached end of code');
    }
    return $code[$ip++] & 0xff;
}

function read_u16_from_code(array $code, int &$ip): int {
    $lo = read_u8_from_code($code, $ip);
    $hi = read_u8_from_code($code, $ip);
    $v = $lo | ($hi << 8);
    return $lo | ($hi << 8);
}

function read_i16_from_code(array $code, int &$ip): int {
    $v = read_u16_from_code($code, $ip);
    return ($v & 0x8000) ? ($v - 0x10000) : $v;
}

function format_bytes_for_dump(string $s): string {
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $b = ord($s[$i]);
        if ($b === 0x0a) {
            $out .= '\\n';
        } elseif ($b === 0x0d) {
            $out .= '\\r';
        } elseif ($b === 0x09) {
            $out .= '\\t';
        } elseif ($b >= 0x20 && $b <= 0x7e && $b !== 0x22 && $b !== 0x5c) {
            $out .= chr($b);
        } else {
            $out .= sprintf('\\x%02x', $b);
        }
    }
    return $out;
}

function format_const_for_dump(ConstValue $c): string {
    return match ($c->type) {
        'null' => 'null',
        'bool' => $c->value ? 'true' : 'false',
        'int' => (string)$c->value,
        'float' => sprintf('%.9g', (float)$c->value),
        'string' => 'string[' . strlen($c->value) . '] "' . format_bytes_for_dump($c->value) . '"',
        default => '<unknown const>',
    };
}

/** @param list<int> $code @param list<ConstValue> $consts @param array<string,int> $globals */
function disassemble_code(array $code, array $consts, array $globals, array $functions = []): string {
    $globalNames = [];
    foreach ($globals as $name => $slot) {
        $globalNames[$slot] = '$' . $name;
    }

    $functionNames = [];
    foreach ($functions as $name => $fn) {
        $functionNames[$fn->id] = $name;
    }

    $lines = [];
    $lines[] = '== PicoPHP opcode dump ==';
    $lines[] = '';

    if ($consts !== []) {
        $lines[] = 'Constants:';
        foreach ($consts as $i => $c) {
            $lines[] = sprintf('  #%d = %s', $i, format_const_for_dump($c));
        }
        $lines[] = '';
    }

    if ($globals !== []) {
        $lines[] = 'Globals:';
        asort($globals);
        foreach ($globals as $name => $slot) {
            $lines[] = sprintf('  slot %d = $%s', $slot, $name);
        }
        $lines[] = '';
    }

    if ($functions !== []) {
        $lines[] = 'Functions:';
        $orderedFunctions = array_values($functions);
        usort($orderedFunctions, fn(FunctionInfo $a, FunctionInfo $b): int => $a->id <=> $b->id);
        foreach ($orderedFunctions as $fn) {
            $params = implode(', ', array_map(fn(string $p): string => '$' . $p, $fn->params));
            $lines[] = sprintf(
                '  #%d = %s(%s), entry=%04d, locals=%d',
                $fn->id,
                $fn->name,
                $params,
                $fn->entry,
                $fn->localCount
            );
        }
        $lines[] = '';
    }

    $lines[] = 'Code:';
    $ip = 0;
    $n = count($code);

    while ($ip < $n) {
        $addr = $ip;
        $op = read_u8_from_code($code, $ip);
        $name = OP_NAMES[$op] ?? ('OP_' . $op);
        $arg = '';

        switch ($op) {
            case Op::OP_HALT: case Op::OP_NULL: case Op::OP_TRUE: case Op::OP_FALSE:
            case Op::OP_POP: case Op::OP_DUP:
            case Op::OP_ADD: case Op::OP_SUB: case Op::OP_MUL: case Op::OP_DIV:
            case Op::OP_MOD: case Op::OP_NEG:
            case Op::OP_EQ: case Op::OP_NE: case Op::OP_LT: case Op::OP_LE:
            case Op::OP_GT: case Op::OP_GE:
            case Op::OP_STRLEN: case Op::OP_STR_INDEX: case Op::OP_CONCAT:
            case Op::OP_BIT_AND: case Op::OP_BIT_OR: case Op::OP_BIT_XOR:
            case Op::OP_BIT_NOT: case Op::OP_SHL: case Op::OP_SHR:
            case 30:
                break;

            case Op::OP_CONST:
                $id = read_u8_from_code($code, $ip);
                $desc = isset($consts[$id]) ? format_const_for_dump($consts[$id]) : '<bad const>';
                $arg = sprintf('#%d ; %s', $id, $desc);
                break;

            case Op::OP_CONST16:
                $id = read_u16_from_code($code, $ip);
                $desc = isset($consts[$id]) ? format_const_for_dump($consts[$id]) : '<bad const>';
                $arg = sprintf('#%d ; %s', $id, $desc);
                break;
 
            case Op::OP_GET_GLOBAL:
            case Op::OP_SET_GLOBAL:
                $slot = read_u8_from_code($code, $ip);
                $arg = sprintf('%d ; %s', $slot, $globalNames[$slot] ?? ('global#' . $slot));
                break;

            case Op::OP_JMP:
            case Op::OP_JMP_IF_FALSE:
                $off = read_i16_from_code($code, $ip);
                $arg = sprintf('%+d -> %04d', $off, $ip + $off);
                break;

            case Op::OP_CALL_NATIVE:
                $id = read_u8_from_code($code, $ip);
                $argc = read_u8_from_code($code, $ip);
                $arg = sprintf('%s, argc=%d', NATIVE_NAMES[$id] ?? ('native#' . $id), $argc);
                break;

            case 27:
            case 28:
                $slot = read_u8_from_code($code, $ip);
                $arg = sprintf('%d', $slot);
                break;

            case 29:
                $fn = read_u8_from_code($code, $ip);
                $argc = read_u8_from_code($code, $ip);
                $fnName = $functionNames[$fn] ?? ('function#' . $fn);
                $arg = sprintf('%s, argc=%d', $fnName, $argc);
                break;

            default:
                $arg = '<unknown opcode; stopping>';
                $lines[] = sprintf('  %04d: %-16s %s', $addr, $name, $arg);
                break 2;
        }

        $lines[] = sprintf('  %04d: %-16s %s', $addr, $name, $arg);
    }

    $lines[] = '';
    return implode("\n", $lines);
}

function compile_source_debug(string $src, string $symbolPrefix = 'picophp_program'): array {
    $tokens = lex_source($src, $symbolPrefix . '.pphp');
    $program = (new Parser($tokens))->parse();
    $compiler = new Compiler();
    $compiler->compile($program);
    return [
        'header' => $compiler->emitCHeader($symbolPrefix),
        'dump' => $compiler->dumpOpcodes(),
    ];
}


function strip_php_open_tag_for_require(string $src): string {
    $src = preg_replace('/^\xEF\xBB\xBF/', '', $src) ?? $src;
    $ltrim = ltrim($src);
    $removed = strlen($src) - strlen($ltrim);

    if (str_starts_with($ltrim, "<?php")) {
        return str_repeat("\n", substr_count(substr($src, 0, $removed), "\n")) . substr($ltrim, 5);
    }

    if (str_starts_with($ltrim, "<?")) {
        return str_repeat("\n", substr_count(substr($src, 0, $removed), "\n")) . substr($ltrim, 2);
    }

    return $src;
}

function resolve_require_path(string $baseDir, string $required): string {
    if ($required === '') {
        throw new CompileError("empty require path");
    }

    if ($required[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $required)) {
        return $required;
    }

    return $baseDir . DIRECTORY_SEPARATOR . $required;
}

function picophp_file_marker(string $file, int $line = 1): string {
    // The lexer recognizes this marker and treats the following physical line
    // as $line of $file. Keep it as // comment so it is stripped before parsing.
    $file = str_replace('\\', '/', $file);
    if ($line < 1) {
        $line = 1;
    }
    return "\n//__PICOPHP_FILE__:" . base64_encode($file) . ":" . $line . "\n";
}

/**
 * @param array<string,bool> $seen
 */
function preprocess_require_file(string $path, array &$seen, bool $once = true, array $stack = []): string {
    $real = realpath($path);
    if ($real === false) {
        $from = $stack === [] ? '' : ' from ' . end($stack);
        throw new CompileError("require file not found: {$path}{$from}");
    }

    if ($once && isset($seen[$real])) {
        return "\n/* require_once skipped: {$real} */\n";
    }

    if (in_array($real, $stack, true)) {
        $chain = implode(" -> ", array_merge($stack, [$real]));
        throw new CompileError("recursive require detected: {$chain}");
    }

    $seen[$real] = true;
    $dir = dirname($real);

    $src = file_get_contents($real);
    if ($src === false) {
        throw new CompileError("failed to read require file: {$real}");
    }

    $src = strip_php_open_tag_for_require($src);
    $lines = preg_split('/\R/', $src);
    if ($lines === false) {
        throw new CompileError("failed to split source: {$real}");
    }

    $out = picophp_file_marker($real, 1);

    foreach ($lines as $lineNo => $line) {
        if (preg_match('/^\s*require_once\s+([\'"])([^\'"]+)\1\s*;\s*(?:\/\/.*)?$/', $line, $m)) {
            $child = resolve_require_path($dir, $m[2]);
            $out .= preprocess_require_file($child, $seen, true, array_merge($stack, [$real]));
            // After the included file, restore the parent file location.
            // $lineNo is zero-based, and the next source line is $lineNo + 2.
            $out .= picophp_file_marker($real, $lineNo + 2);
            continue;
        }

        if (preg_match('/^\s*require\s+([\'"])([^\'"]+)\1\s*;\s*(?:\/\/.*)?$/', $line, $m)) {
            $child = resolve_require_path($dir, $m[2]);
            $out .= preprocess_require_file($child, $seen, false, array_merge($stack, [$real]));
            // After the included file, restore the parent file location.
            // $lineNo is zero-based, and the next source line is $lineNo + 2.
            $out .= picophp_file_marker($real, $lineNo + 2);
            continue;
        }

        if (preg_match('/^\s*(require|require_once)\b/', $line)) {
            throw new CompileError(
                "unsupported require syntax at {$real}:" . ($lineNo + 1) .
                " ; use require_once \"path.pphp\";"
            );
        }

        $out .= $line . "\n";
    }

    return $out;
}

function compile_file(string $path, string $symbolPrefix = 'picophp_program'): string {
    $real = realpath($path);
    if ($real === false) {
        throw new CompileError("input file not found: {$path}");
    }

    $seen = [];
    $src = preprocess_require_file($real, $seen, false);
    return compile_source($src, $symbolPrefix);
}

function compile_file_debug(string $path, string $symbolPrefix = 'picophp_program'): array {
    $real = realpath($path);
    if ($real === false) {
        throw new CompileError("input file not found: {$path}");
    }

    $seen = [];
    $src = preprocess_require_file($real, $seen, false);
    return compile_source_debug($src, $symbolPrefix);
}

function compile_source(string $src, string $symbolPrefix = 'picophp_program'): string {
    $tokens = lex_source($src, $symbolPrefix . '.pphp');
    $program = (new Parser($tokens))->parse();
    $compiler = new Compiler();
    $compiler->compile($program);
    return $compiler->emitCHeader($symbolPrefix);
}

const DEMO_SOURCE = <<<'PPHP'
<?php
const LED = 25;

$data = "A\x00Z";
print("len=", strlen($data), "\n");
print("byte2=", $data[2], "\n");
print("hex=", bin2hex($data), "\n");

$x = sin(M_PI / 2.0);
print("sin=", $x, "\n");

gpio_mode(LED, OUTPUT);
function blink($pin, $ms) {
    gpio_write($pin, HIGH);
    sleep_ms($ms);
    gpio_write($pin, LOW);
}

blink(LED, 100);
PPHP;

function main(array $argv): int {
    $symbolPrefix = 'picophp_program';
    $input = null;
    $useDemo = false;
    $dumpOpcodes = false;

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--demo') {
            $useDemo = true;
        } elseif ($arg === '--dump-opcodes' || $arg === '--disasm') {
            $dumpOpcodes = true;
        } elseif ($arg === '--symbol-prefix') {
            $i++;
            if ($i >= count($argv)) {
                fwrite(STDERR, "missing value for --symbol-prefix\n");
                return 2;
            }
            $symbolPrefix = $argv[$i];
        } elseif (str_starts_with($arg, '--symbol-prefix=')) {
            $symbolPrefix = substr($arg, strlen('--symbol-prefix='));
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "Usage: php picophp_compile_debug.php [--demo] [--dump-opcodes] [--symbol-prefix NAME] [input.pphp]\n");
            return 0;
        } elseif (str_starts_with($arg, '-')) {
            fwrite(STDERR, "unknown option: {$arg}\n");
            return 2;
        } elseif ($arg === '--usb-keyboard') {
            file_put_contents($outDir . '/tusb_config.h', <<<'C'
#ifndef _TUSB_CONFIG_H_
#define _TUSB_CONFIG_H_

#define CFG_TUSB_MCU OPT_MCU_RP2040
#define CFG_TUSB_OS OPT_OS_PICO

#define CFG_TUSB_RHPORT0_MODE (OPT_MODE_DEVICE)

#define CFG_TUD_ENDPOINT0_SIZE 64

#define CFG_TUD_HID 1
#define CFG_TUD_CDC 0
#define CFG_TUD_MSC 0
#define CFG_TUD_MIDI 0
#define CFG_TUD_VENDOR 0

#define CFG_TUD_HID_EP_BUFSIZE 16

#endif
C);

    file_put_contents($outDir . '/usb_descriptors.c', <<<'C'
#include <string.h>
#include "tusb.h"

enum {
    ITF_NUM_HID,
    ITF_NUM_TOTAL
};

#define EPNUM_HID 0x81

uint8_t const desc_hid_report[] = {
    TUD_HID_REPORT_DESC_KEYBOARD()
};

/* 以下 descriptor 本体 */
C);
        } else {
            $input = $arg;
        }
    }

    try {
        if ($input !== null && !$useDemo) {
            if ($dumpOpcodes) {
                $result = compile_file_debug($input, $symbolPrefix);
                fwrite(STDOUT, $result['dump']);
            } else {
                fwrite(STDOUT, compile_file($input, $symbolPrefix));
            }
            return 0;
        }

        if ($useDemo) {
            $src = DEMO_SOURCE;
        } else {
            $src = stream_get_contents(STDIN);
            if ($src === false) {
                throw new PicoCompileError("failed to read stdin");
            }
        }

        if ($dumpOpcodes) {
            $result = compile_source_debug($src, $symbolPrefix);
            fwrite(STDOUT, $result['dump']);
        } else {
            if ($src === DEMO_SOURCE) {
                throw new PicoCompileError("don't use demo source");
            }
            fwrite(STDOUT, compile_source($src, $symbolPrefix));
        }
        return 0;
    } catch (PicoCompileError $e) {
        fwrite(STDERR, "compile error: {$e->getMessage()}\n");
        return 1;
    }
}

exit(main($argv));
