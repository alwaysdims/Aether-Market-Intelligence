<?php
$database = 'project_bot_recovered_20261115_022935';
$role = 'aether_backup';
$host = '127.0.0.1';
$port = '5432';
$localAppData = str_replace('\\', '/', (string) getenv('LOCALAPPDATA'));
$credentialFile = $localAppData.'/Aether-Market-Intelligence/recovery/credentials/aether_backup.dpapi.txt';
$decryptor = 'E:/Aether-Market-Intelligence/scripts/decrypt_ingestion_secret.ps1';
$backupDir = 'E:/Aether-Market-Intelligence/storage/backups/candidate_20261115';
$incidentDir = 'E:/Aether-Market-Intelligence/storage/incidents/20261114_test_runner_wipe';
$timestamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Ymd_His');
$dump = $backupDir.'/project_bot_recovered_20261115_022935_pre_eod_news_'.$timestamp.'.dump';
$proofPath = $incidentDir.'/candidate-backup-proof-'.$timestamp.'.json';
if (file_exists($dump)) { fwrite(STDERR, "refusing overwrite of existing backup\n"); exit(2); }
if (!is_file($credentialFile) || !is_file($decryptor)) { fwrite(STDERR, "credential store unavailable\n"); exit(3); }
$proc = proc_open(['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-NonInteractive','-File',$decryptor,'-Path',$credentialFile],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
if (!is_resource($proc)) { fwrite(STDERR, "credential decrypt process unavailable\n"); exit(4); }
fclose($pipes[0]); $password=trim(stream_get_contents($pipes[1])); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($proc);
if ($code !== 0 || $password === '') { fwrite(STDERR, "credential decrypt failed\n"); exit(5); }
$pdo=new PDO("pgsql:host=$host;port=$port;dbname=$database",$role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$identity=$pdo->query("SELECT current_database() AS database,current_user AS user_name,current_schema() AS schema_name")->fetch(PDO::FETCH_ASSOC);
if ($identity['database'] !== $database || $identity['user_name'] !== $role || $identity['schema_name'] !== 'public') { fwrite(STDERR, "backup SQL identity mismatch\n"); exit(6); }
if (!is_dir($backupDir)) { mkdir($backupDir,0700,true); }
if (!is_dir($incidentDir)) { mkdir($incidentDir,0700,true); }
$env=getenv(); $env['PGPASSWORD']=$password;
$pgDump='C:/Program Files/PostgreSQL/16/bin/pg_dump.exe';
$dumpProc=proc_open([$pgDump,'--host',$host,'--port',$port,'--username',$role,'--dbname',$database,'--format=custom','--no-owner','--no-privileges','--file',$dump],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$dumpPipes,__DIR__,$env);
if (!is_resource($dumpProc)) { fwrite(STDERR, "pg_dump process unavailable\n"); exit(7); }
fclose($dumpPipes[0]); stream_get_contents($dumpPipes[1]); stream_get_contents($dumpPipes[2]); fclose($dumpPipes[1]); fclose($dumpPipes[2]); $dumpCode=proc_close($dumpProc);
unset($password,$env);
if ($dumpCode !== 0 || !is_file($dump) || filesize($dump) <= 0) { @unlink($dump); fwrite(STDERR, "pg_dump failed or produced empty archive\n"); exit(8); }
$restore='C:/Program Files/PostgreSQL/16/bin/pg_restore.exe';
$list=proc_open([$restore,'--list',$dump],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$listPipes,__DIR__);
if (!is_resource($list)) { fwrite(STDERR, "pg_restore process unavailable\n"); exit(9); }
fclose($listPipes[0]); $toc=stream_get_contents($listPipes[1]); stream_get_contents($listPipes[2]); fclose($listPipes[1]); fclose($listPipes[2]); $listCode=proc_close($list);
if ($listCode !== 0 || trim($toc) === '') { @unlink($dump); fwrite(STDERR, "pg_restore validation failed\n"); exit(10); }
$proof=['status'=>'VERIFIED','database'=>$database,'role'=>$role,'schema'=>'public','path'=>$dump,'bytes'=>filesize($dump),'sha256'=>hash_file('sha256',$dump),'pg_restore_list'=>'PASS','secret_printed'=>false];
file_put_contents($proofPath,json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
