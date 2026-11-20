<?php

$root = 'E:/Aether-Market-Intelligence';
$path = $root.'/scripts/launch_app_writer.php';
$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "missing launcher\n");
    exit(1);
}

$required = [
    "\$role = 'aether_ingestion';",
    "aether_ingestion.dpapi.txt",
    "ingestion-writer-runtime/app-writer",
    "'DB_USERNAME' => \$role",
    "APP_WRITER_ABORT identity mismatch",
    "'--narrative'",
    "'AI_LIVE_INFERENCE_ON_DEMAND_ENABLED' => in_array(\$mode, ['--telegram-chat', '--narrative', '--consultation-queue', '--requeue-consultation', '--recovery-consultation', '--research-request'], true) ? 'true' : 'false'",
    "Artisan::call('idx:process-narratives'",
    "'CACHE_STORE' => 'file'",
    "'DB_CACHE_CONNECTION' => ''",
];

foreach ($required as $needle) {
    if (! str_contains($source, $needle)) {
        fwrite(STDERR, "missing launcher contract: {$needle}\n");
        exit(1);
    }
}

foreach (["aether_migrator", 'aether_migrator.dpapi.txt'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fwrite(STDERR, "forbidden routine writer role: {$forbidden}\n");
        exit(1);
    }
}

echo "launch_app_writer_contract=PASS\n";
