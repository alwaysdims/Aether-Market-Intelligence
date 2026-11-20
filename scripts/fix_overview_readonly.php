<?php

/**
 * Standalone source patch. Does not load Laravel or connect to any database.
 * Place in E:/Aether-Market-Intelligence/scripts and run with PHP 8.3.
 * Default is inspection only. --apply creates a backup and applies a guarded patch.
 */

function finishPatch(string $status, array $details = [], int $code = 0): never
{
    echo json_encode(['status' => $status] + $details, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
    exit($code);
}

$apply = false;
$projectRoot = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--root=')) {
        $projectRoot = substr($arg, 7);
    } else {
        finishPatch('ABORT_UNKNOWN_ARGUMENT', [], 2);
    }
}

$target = rtrim($projectRoot, '/\\').'/dashboard/app/Services/UserPortfolioService.php';
if (! is_file($target) || ! is_readable($target)) {
    finishPatch('ABORT_TARGET_NOT_READABLE', ['target' => $target], 3);
}
$original = file_get_contents($target);
if ($original === false) {
    finishPatch('ABORT_READ_FAILED', [], 3);
}
$originalHash = hash('sha256', $original);
$lineEnding = str_contains($original, "\r\n") ? "\r\n" : "\n";
$source = str_replace("\r\n", "\n", $original);

// Only patch the metrics reader, never the explicit portfolio write methods.
$methodPattern = '/public function calculatePortfolioMetrics\s*\(\s*string \$userId\s*\)\s*:\s*array\s*\{/';
if (preg_match_all($methodPattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
    finishPatch('ABORT_METRICS_METHOD_NOT_UNIQUE_OR_SIGNATURE_CHANGED', [], 4);
}
$start = $matches[0][0][1];
$nextMethod = strpos($source, "\n    public function ", $start + strlen($matches[0][0][0]));
if ($nextMethod === false) {
    finishPatch('ABORT_CANNOT_BOUND_METRICS_METHOD', [], 4);
}
$body = substr($source, $start, $nextMethod - $start);
$marker = '// AETHER_READONLY_METRICS_V1: defaults remain in memory; never save here.';
if (str_contains($body, $marker)) {
    finishPatch('ALREADY_PATCHED', ['target' => $target, 'database_accessed' => false]);
}
if (! str_contains($source, 'use App\\Models\\UserPortfolio;')) {
    finishPatch('ABORT_MODEL_IMPORT_CHANGED', [], 4);
}

$oldPortfolio = '$portfolio = $this->getOrCreatePortfolio($userId);';
$oldItems = '$items = $portfolio->items()->get();';
if (substr_count($body, $oldPortfolio) !== 1 || substr_count($body, $oldItems) !== 1) {
    finishPatch('ABORT_SOURCE_DIFFERS_SEND_CURRENT_SERVICE_FOR_REVIEW', ['target' => $target], 4);
}

$newPortfolio = <<<'PHP'
// AETHER_READONLY_METRICS_V1: defaults remain in memory; never save here.
        $portfolio = UserPortfolio::where('user_id', $userId)->first();
        if ($portfolio === null) {
            $portfolio = new UserPortfolio([
                'user_id' => $userId,
                'chat_id' => $userId,
                'cash_balance' => 0.00,
                'target_trading_pct' => 50.00,
                'target_investment_pct' => 50.00,
                'risk_profile' => 'MODERATE',
            ]);
        }
PHP;
$newBody = str_replace($oldPortfolio, $newPortfolio, $body);
$newBody = str_replace($oldItems, '$items = $portfolio->exists ? $portfolio->items()->get() : collect();', $newBody);
if (str_contains($newBody, '$this->getOrCreatePortfolio(')) {
    finishPatch('ABORT_UNEXPECTED_PORTFOLIO_WRITE_CALL_REMAINS', [], 4);
}
$updated = substr($source, 0, $start).$newBody.substr($source, $nextMethod);
if ($lineEnding === "\r\n") {
    $updated = str_replace("\n", "\r\n", $updated);
}

if (! $apply) {
    finishPatch('PATCH_READY_NOT_APPLIED', [
        'target' => $target,
        'before_sha256' => $originalHash,
        'after_sha256' => hash('sha256', $updated),
        'changes' => ['SELECT existing portfolio', 'unsaved defaults if absent', 'skip items query for unsaved portfolio'],
        'explicit_write_methods_changed' => false,
        'database_accessed' => false,
        'next' => 'Run the same command with --apply',
    ]);
}

if (! is_writable($target) || ! is_writable(dirname($target))) {
    finishPatch('ABORT_TARGET_OR_DIRECTORY_NOT_WRITABLE', [], 5);
}
$temporary = tempnam(dirname($target), 'readonly_metrics_');
if ($temporary === false) {
    finishPatch('ABORT_TEMPFILE_FAILED', [], 5);
}
if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) {
    @unlink($temporary);
    finishPatch('ABORT_TEMPFILE_WRITE_FAILED', [], 5);
}

// php -l only parses source. It does not execute the service or load Laravel.
$process = proc_open([PHP_BINARY, '-l', $temporary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (! is_resource($process)) {
    @unlink($temporary);
    finishPatch('ABORT_LINT_PROCESS_FAILED_SOURCE_UNCHANGED', [], 6);
}
fclose($pipes[0]);
$lintOutput = stream_get_contents($pipes[1]);
$lintError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$lintExit = proc_close($process);
if ($lintExit !== 0) {
    @unlink($temporary);
    finishPatch('ABORT_LINT_FAILED_SOURCE_UNCHANGED', ['lint_exit' => $lintExit], 6);
}
if (hash_file('sha256', $target) !== $originalHash) {
    @unlink($temporary);
    finishPatch('ABORT_SOURCE_CHANGED_DURING_PATCH', [], 7);
}

$backup = $target.'.before_readonly_fix_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.bak';
$backupHandle = fopen($backup, 'x+b');
if ($backupHandle === false) {
    @unlink($temporary);
    finishPatch('ABORT_BACKUP_CREATE_FAILED', [], 7);
}
$backupBytes = fwrite($backupHandle, $original);
fclose($backupHandle);
if ($backupBytes !== strlen($original) || hash_file('sha256', $backup) !== $originalHash) {
    @unlink($temporary);
    finishPatch('ABORT_BACKUP_VERIFICATION_FAILED_SOURCE_UNCHANGED', ['backup' => $backup], 7);
}
if (hash_file('sha256', $target) !== $originalHash) {
    @unlink($temporary);
    finishPatch('ABORT_SOURCE_CHANGED_AFTER_BACKUP', ['backup' => $backup], 7);
}
$permissions = fileperms($target);
if ($permissions !== false) {
    @chmod($temporary, $permissions & 0777);
}
if (! rename($temporary, $target)) {
    @unlink($temporary);
    finishPatch('ABORT_REPLACE_FAILED', ['backup' => $backup], 8);
}
finishPatch('PATCH_APPLIED', [
    'target' => $target,
    'backup' => $backup,
    'before_sha256' => $originalHash,
    'after_sha256' => hash_file('sha256', $target),
    'php_lint' => 'PASS',
    'database_accessed' => false,
    'explicit_write_methods_changed' => false,
]);
