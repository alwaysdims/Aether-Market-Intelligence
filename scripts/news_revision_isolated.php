<?php
$dbPath = getenv('TMPDIR') ?: 'E:/Aether-Market-Intelligence/storage/runtime';
$dbPath .= '/news-revision-isolated.sqlite';
@unlink($dbPath);
$rawDir = dirname($dbPath).'/news-revision-raw';
if (is_dir($rawDir)) { foreach (glob($rawDir.'/*') ?: [] as $file) @unlink($file); } else { mkdir($rawDir, 0700, true); }
$db = new PDO('sqlite:'.$dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE news_items (id INTEGER PRIMARY KEY, url TEXT UNIQUE, raw_payload_path TEXT, raw_payload_hash TEXT, first_seen_at TEXT, available_at TEXT, fetched_at TEXT)');
$db->exec('CREATE TABLE news_revisions (id INTEGER PRIMARY KEY, news_item_id INTEGER, previous_hash TEXT, previous_path TEXT, previous_available_at TEXT, new_hash TEXT, new_path TEXT)');
$store = static function (PDO $db, string $url, string $content, string $fetchedAt) use ($rawDir): void {
    $hash = hash('sha256', $content); $path = $rawDir.'/'.$hash.'.txt'; file_put_contents($path, $content);
    $q = $db->prepare('SELECT * FROM news_items WHERE url = ?'); $q->execute([$url]); $old = $q->fetch(PDO::FETCH_ASSOC);
    if (!$old) { $s=$db->prepare('INSERT INTO news_items(url,raw_payload_path,raw_payload_hash,first_seen_at,available_at,fetched_at) VALUES(?,?,?,?,?,?)'); $s->execute([$url,$path,$hash,$fetchedAt,$fetchedAt,$fetchedAt]); return; }
    if ($old['raw_payload_hash'] !== $hash) { $s=$db->prepare('INSERT INTO news_revisions(news_item_id,previous_hash,previous_path,previous_available_at,new_hash,new_path) VALUES(?,?,?,?,?,?)'); $s->execute([$old['id'],$old['raw_payload_hash'],$old['raw_payload_path'],$old['available_at'],$hash,$path]); $u=$db->prepare('UPDATE news_items SET raw_payload_path=?,raw_payload_hash=?,available_at=?,fetched_at=? WHERE id=?'); $u->execute([$path,$hash,$fetchedAt,$fetchedAt,$old['id']]); }
    else { $u=$db->prepare('UPDATE news_items SET fetched_at=? WHERE id=?'); $u->execute([$fetchedAt,$old['id']]); }
};
$url='https://example.invalid/news/1'; $store($db,$url,'same-content','2026-11-15T01:00:00Z'); $first=$db->query('SELECT * FROM news_items')->fetch(PDO::FETCH_ASSOC); $store($db,$url,'same-content','2026-11-15T02:00:00Z'); $same=$db->query('SELECT * FROM news_items')->fetch(PDO::FETCH_ASSOC); if ($same['available_at']!==$first['available_at'] || $same['first_seen_at']!==$first['first_seen_at'] || $same['fetched_at']===$first['fetched_at']) throw new RuntimeException('same-version semantics failed'); $store($db,$url,'changed-content','2026-11-15T03:00:00Z'); $changed=$db->query('SELECT * FROM news_items')->fetch(PDO::FETCH_ASSOC); $rev=$db->query('SELECT * FROM news_revisions')->fetch(PDO::FETCH_ASSOC); if ($rev['previous_hash']!==$first['raw_payload_hash'] || $rev['previous_path']!==$first['raw_payload_path'] || $changed['raw_payload_hash']===$first['raw_payload_hash']) throw new RuntimeException('revision semantics failed'); echo json_encode(['status'=>'VERIFIED','same_version'=>true,'changed_version'=>true,'first_seen_preserved'=>true,'available_same_preserved'=>true,'fetched_at_updated'=>true,'old_raw_referenced'=>is_file($rev['previous_path']),'revision_count'=>(int)$db->query('SELECT count(*) FROM news_revisions')->fetchColumn(),'synthetic_data'=>'isolated_only']).PHP_EOL;
