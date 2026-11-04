<?php

declare(strict_types=1);

/**
 * The API surface of src/: every class-like and its public and protected members, with signatures, in scripts/api-surface.txt.
 *
 * The file is committed, so a symbol cannot be added, renamed or removed without a visible line in the diff. A second method
 * that does what the first one does (`from` next to `fromKey`) shows up there instead of being found months later.
 *
 *   php scripts/api-surface.php           compare src/ with the file; exit 1 when they differ
 *   php scripts/api-surface.php --write   regenerate the file (then read `git diff scripts/api-surface.txt`)
 *
 * Besides the comparison it prints warnings for the symbols that are new relative to the file: a name that extends the name
 * of a sibling, and a signature equal to a sibling's. They are hints for the reviewer, never failures.
 */

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const SURFACE_FILE = 'scripts/api-surface.txt';

/** @return string The type as written, without resolving names */
function typeText(?Node $type): string
{
    return match (true) {
        $type === null => '',
        $type instanceof Node\Identifier, $type instanceof Node\Name => $type->toString(),
        $type instanceof Node\NullableType => '?' . typeText($type->type),
        $type instanceof Node\UnionType => implode('|', array_map(typeText(...), $type->types)),
        $type instanceof Node\IntersectionType => implode('&', array_map(typeText(...), $type->types)),
        default => '?',
    };
}

/**
 * @param array<int, Node\Param> $params
 */
function paramsText(array $params, Standard $printer): string
{
    $parts = [];
    foreach ($params as $param) {
        $name = ($param->byRef ? '&' : '') . ($param->variadic ? '...' : '') . '$' . (is_string($param->var->name) ? $param->var->name : '?');
        $type = typeText($param->type);
        $parts[] = ($type === '' ? '' : $type . ' ') . $name . ($param->default === null ? '' : ' = ' . $printer->prettyPrintExpr($param->default));
    }

    return implode(', ', $parts);
}

/** @return list<string> One line per class-like and per non-private member of every file under src/ */
function surfaceOf(string $root): array
{
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $printer = new Standard();
    $finder = new NodeFinder();
    $lines = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        $statements = $code === false ? null : $parser->parse($code);
        if ($statements === null) {
            fwrite(STDERR, 'Cannot parse ' . $file->getPathname() . "\n");
            exit(2);
        }
        foreach ($finder->findInstanceOf($statements, Stmt\ClassLike::class) as $class) {
            if ($class->name === null) {
                continue;
            }
            $namespace = '';
            foreach ($statements as $statement) {
                if ($statement instanceof Stmt\Namespace_ && $statement->name !== null) {
                    $namespace = $statement->name->toString() . '\\';
                }
            }
            $kind = match (true) {
                $class instanceof Stmt\Interface_ => 'interface',
                $class instanceof Stmt\Trait_ => 'trait',
                $class instanceof Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $modifiers = '';
            if ($class instanceof Stmt\Class_) {
                $modifiers = ($class->isAbstract() ? 'abstract ' : '') . ($class->isFinal() ? 'final ' : '') . ($class->isReadonly() ? 'readonly ' : '');
            }
            $relations = '';
            if ($class instanceof Stmt\Class_ && $class->extends !== null) {
                $relations .= ' extends ' . $class->extends->toString();
            }
            $implements = $class instanceof Stmt\Class_ ? $class->implements : ($class instanceof Stmt\Interface_ ? $class->extends : ($class instanceof Stmt\Enum_ ? $class->implements : []));
            if ($implements !== []) {
                $relations .= ($class instanceof Stmt\Interface_ ? ' extends ' : ' implements ') . implode(', ', array_map(static fn (Node\Name $n): string => $n->toString(), $implements));
            }
            $head = $namespace . $class->name->toString();
            $lines[] = "{$head} ({$modifiers}{$kind}{$relations})";

            foreach ($class->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod && !$member->isPrivate()) {
                    $visibility = $member->isProtected() ? 'protected' : 'public';
                    $flags = ($member->isAbstract() ? 'abstract ' : '') . ($member->isFinal() ? 'final ' : '') . ($member->isStatic() ? 'static ' : '');
                    $return = typeText($member->returnType);
                    $lines[] = "{$head}  {$visibility} {$flags}" . $member->name->toString() . '(' . paramsText($member->params, $printer) . ')' . ($return === '' ? '' : ': ' . $return);
                } elseif ($member instanceof Stmt\ClassConst && !$member->isPrivate()) {
                    foreach ($member->consts as $const) {
                        $lines[] = "{$head}  " . ($member->isProtected() ? 'protected' : 'public') . ' const ' . $const->name->toString();
                    }
                } elseif ($member instanceof Stmt\Property && !$member->isPrivate()) {
                    foreach ($member->props as $property) {
                        $type = typeText($member->type);
                        $lines[] = "{$head}  " . ($member->isProtected() ? 'protected' : 'public') . ($member->isStatic() ? ' static' : '') . ' ' . ($type === '' ? '' : $type . ' ') . '$' . $property->name->toString();
                    }
                } elseif ($member instanceof Stmt\EnumCase) {
                    $lines[] = "{$head}  case " . $member->name->toString();
                }
            }
        }
    }
    // the class line first, then its members
    usort($lines, static fn (string $x, string $y): int => strcmp(preg_replace('/^(\S+) \(/', "$1\0(", $x) ?? $x, preg_replace('/^(\S+) \(/', "$1\0(", $y) ?? $y));

    return $lines;
}

/**
 * Hints about members that are in $now and not in $before.
 *
 * @param list<string> $before
 * @param list<string> $now
 *
 * @return list<string>
 */
function hints(array $before, array $now): array
{
    $members = [];
    foreach ($now as $line) {
        if (preg_match('/^(\S+)  (?:public|protected) (?:abstract |final |static )*(\w+)\((.*)\)(?:: (.+))?$/', $line, $m) === 1) {
            $members[$m[1]][] = ['line' => $line, 'name' => $m[2], 'signature' => $m[3] . '|' . ($m[4] ?? '')];
        }
    }
    if ($before === []) {
        return []; // no recorded surface to compare with: everything would be new
    }
    $isNew = array_flip(array_diff($now, $before));
    $hints = [];
    foreach ($members as $class => $list) {
        foreach ($list as $member) {
            if (!isset($isNew[$member['line']])) {
                continue;
            }
            foreach ($list as $sibling) {
                if ($sibling['line'] === $member['line']) {
                    continue;
                }
                $a = $member['name'];
                $b = $sibling['name'];
                if (strlen($b) >= 3 && strlen($a) > strlen($b) && strncasecmp($a, $b, strlen($b)) === 0 && preg_match('/^[A-Z_0-9]/', substr($a, strlen($b))) === 1) {
                    $hints[] = "{$class}::{$a}() extends the name of {$b}() - one of them may be redundant";
                }
                $similarName = strlen($b) >= 3 && (stripos($a, $b) !== false || stripos($b, $a) !== false || levenshtein(strtolower($a), strtolower($b)) <= 2);
                if ($similarName && $member['signature'] === $sibling['signature'] && !str_starts_with($member['signature'], '|')) {
                    $hints[] = "{$class}::{$a}() has the same signature as {$b}() and a similar name";
                }
            }
        }
    }

    return array_values(array_unique($hints));
}

$current = surfaceOf($root);
$path = $root . '/' . SURFACE_FILE;
$recorded = is_file($path) ? array_values(array_filter(explode("\n", (string)file_get_contents($path)), static fn (string $l): bool => $l !== '')) : [];

if (in_array('--write', $argv, true)) {
    file_put_contents($path, implode("\n", $current) . "\n");
    foreach (hints($recorded, $current) as $hint) {
        echo "hint: {$hint}\n";
    }
    echo count($current) . ' lines written to ' . SURFACE_FILE . ". Read the diff: every added line is a new symbol.\n";
    exit(0);
}

$added = array_values(array_diff($current, $recorded));
$removed = array_values(array_diff($recorded, $current));
foreach (hints($recorded, $current) as $hint) {
    echo "hint: {$hint}\n";
}
if ($added === [] && $removed === []) {
    echo 'API surface matches ' . SURFACE_FILE . ' (' . count($current) . " lines).\n";
    exit(0);
}
foreach ($removed as $line) {
    echo "- {$line}\n";
}
foreach ($added as $line) {
    echo "+ {$line}\n";
}
echo "\nThe API surface of src/ differs from " . SURFACE_FILE . ".\nRun: php scripts/api-surface.php --write, then review the diff of that file: each line is a symbol that was added, renamed or removed.\n";
exit(1);
