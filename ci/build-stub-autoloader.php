<?php
// Preserve the complete generated source for static scanning. Bootstrap its
// declarations lazily so cross-namespace inheritance is not file-order dependent.
require __DIR__ . '/../vendor/autoload.php';
$source = $argv[1];
$out = $argv[2];
$ast = (new PhpParser\ParserFactory())->createForHostVersion()->parse(file_get_contents($source));
$printer = new PhpParser\PrettyPrinter\Standard();
if (!is_dir($out)) { mkdir($out, 0700, true); }
$map = [];
$functionNamespaces = [];
foreach ($ast as $namespace) {
    if (!$namespace instanceof PhpParser\Node\Stmt\Namespace_) {
        throw new RuntimeException('Expected fully namespaced generated stub');
    }
    $uses = array_values(array_filter($namespace->stmts, static fn ($n) => $n instanceof PhpParser\Node\Stmt\Use_ || $n instanceof PhpParser\Node\Stmt\GroupUse));
    $other = [];
    foreach ($namespace->stmts as $node) {
        if ($node instanceof PhpParser\Node\Stmt\ClassLike && $node->name !== null) {
            $name = ($namespace->name === null ? '' : $namespace->name->toString() . '\\') . $node->name->toString();
            $key = strtolower($name);
            if (isset($map[$key])) { throw new RuntimeException('Duplicate generated declaration: ' . $name); }
            $file = $out . '/' . hash('sha256', $key) . '.php';
            file_put_contents($file, $printer->prettyPrintFile([new PhpParser\Node\Stmt\Namespace_($namespace->name, array_merge($uses, [$node]))]));
            $map[$key] = realpath($file);
        } elseif (!$node instanceof PhpParser\Node\Stmt\Use_ && !$node instanceof PhpParser\Node\Stmt\GroupUse) {
            $other[] = $node;
        }
    }
    $functionNamespaces[] = new PhpParser\Node\Stmt\Namespace_($namespace->name, array_merge($uses, $other));
}
file_put_contents($out . '/functions.php', $printer->prettyPrintFile($functionNamespaces));
$loader = '<?php' . PHP_EOL . '$zbm_ci_stub_map = ' . var_export($map, true) . ';' . PHP_EOL
    . 'spl_autoload_register(static function ($name) use ($zbm_ci_stub_map) { $key = strtolower($name); if (isset($zbm_ci_stub_map[$key])) { require $zbm_ci_stub_map[$key]; } });' . PHP_EOL
    . 'require ' . var_export(realpath($out . '/functions.php'), true) . ';' . PHP_EOL;
file_put_contents($out . '/loader.php', $loader);
// Runtime bootstrap is a lazy loader; the raw exact-version file remains scanFiles.
file_put_contents(__DIR__ . '/../vendor/php-stubs/wordpress-stubs/wordpress-stubs.php', '<?php require ' . var_export(realpath($out . '/loader.php'), true) . ';');
echo 'Generated stub bootstrap declarations mapped: ' . count($map) . PHP_EOL;
