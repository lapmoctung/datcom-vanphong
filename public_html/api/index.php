<?php
/* API cho Đặt Cơm IDEA — kho tài liệu dạng collection/doc trên MySQL.
   GET  ?c=orders[&since=ms]     -> các doc của collection (since: chỉ doc đổi từ mốc đó)
   GET  ?p=meta/settings         -> 1 doc
   PUT  ?p=orders/ID  {op:set|update|create, data:{...}}
   POST ?a=login {phone,hash}    -> {token}  (chỉ admin)               */
declare(strict_types=1);
$cfgFile = __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($code, $arr){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail($code, $msg, $c='error'){ out($code, ['error'=>$c,'message'=>$msg]); }
if (!file_exists($cfgFile)) fail(500, 'Chưa có api/config.php');
$cfg = require $cfgFile;
date_default_timezone_set($cfg['timezone'] ?? 'Asia/Ho_Chi_Minh');
if (!empty($cfg['allow_origin'])) {
  header('Access-Control-Allow-Origin: '.$cfg['allow_origin']);
  header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
  header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
const COLS = ['users','dishes','days','orders','invoices','meta'];
const MAX_DOC = 600000;     // ~600KB mỗi doc (ảnh món đã nén)
const MAX_BODY = 800000;

try {
  if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
    $pdo = new PDO('sqlite:'.($cfg['sqlite_path'] ?? __DIR__.'/data.sqlite'));
  } else {
    $pdo = new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['dbname'].';charset=utf8mb4', $cfg['user'], $cfg['pass']);
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { fail(500, 'Không kết nối được database'); }
$isMy = ($cfg['driver'] ?? 'mysql') !== 'sqlite';
$pdo->exec($isMy
  ? "CREATE TABLE IF NOT EXISTS docs (coll VARCHAR(20) NOT NULL, id VARCHAR(120) NOT NULL, data MEDIUMTEXT NOT NULL, u BIGINT NOT NULL, PRIMARY KEY (coll,id), KEY k_u (coll,u)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  : "CREATE TABLE IF NOT EXISTS docs (coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, u INTEGER NOT NULL, PRIMARY KEY (coll,id))");
$pdo->exec($isMy
  ? "CREATE TABLE IF NOT EXISTS attempts (ip VARCHAR(64) NOT NULL, t BIGINT NOT NULL, KEY k_ip (ip,t)) ENGINE=InnoDB"
  : "CREATE TABLE IF NOT EXISTS attempts (ip TEXT NOT NULL, t INTEGER NOT NULL)");

function nowMs(): int { return (int)floor(microtime(true)*1000); }
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mkToken(array $cfg): string { $exp = time()+12*3600; $p = (string)$exp; return $p.'.'.b64u(hash_hmac('sha256', $p, $cfg['secret'], true)); }
function isAdmin(array $cfg): bool {
  $t = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
  if (!$t || strpos($t, '.') === false) return false;
  [$exp, $sig] = explode('.', $t, 2);
  if (!ctype_digit($exp) || (int)$exp < time()) return false;
  return hash_equals(b64u(hash_hmac('sha256', $exp, $cfg['secret'], true)), $sig);
}
function getDoc(PDO $pdo, string $c, string $id, bool $lock=false, bool $isMy=true) {
  $st = $pdo->prepare("SELECT data,u FROM docs WHERE coll=? AND id=?".($lock && $isMy ? " FOR UPDATE" : ""));
  $st->execute([$c,$id]); $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ? ['data'=>json_decode($r['data']), 'u'=>(int)$r['u']] : null;
}
function merge($a, $b) { // gộp sâu như update của Firestore: object gộp, mảng/giá trị thay thế
  foreach ($b as $k=>$v) {
    if (is_object($v) && isset($a->$k) && is_object($a->$k)) $a->$k = merge($a->$k, $v); else $a->$k = $v;
  }
  return $a;
}
function pubDoc($c, $id, $data) { // không bao giờ gửi pinHash ra ngoài
  if ($c==='meta' && $id==='settings' && is_object($data)) { $d = clone $data; $d->hasPin = !empty($data->pinHash); unset($d->pinHash); return $d; }
  return $data;
}
function cutoffClosed(PDO $pdo, bool $isMy, string $date): bool {
  $s = getDoc($pdo,'meta','settings',false,$isMy); $cut = ($s && !empty($s['data']->cutoff)) ? $s['data']->cutoff : '10:00';
  $today = date('Y-m-d'); if ($date < $today) return true;
  if ($date === $today) return date('H:i') >= $cut;
  return false;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$admin = isAdmin($cfg);

/* ---- login admin ---- */
if (($_GET['a'] ?? '') === 'login' && $method === 'POST') {
  $ip = substr($_SERVER['REMOTE_ADDR'] ?? '?', 0, 64);
  $pdo->prepare("DELETE FROM attempts WHERE t < ?")->execute([nowMs()-3600000]);
  $st = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE ip=? AND t > ?"); $st->execute([$ip, nowMs()-600000]);
  if ((int)$st->fetchColumn() >= 10) fail(429, 'Nhập sai quá nhiều lần, thử lại sau 10 phút.', 'too_many');
  $b = json_decode(file_get_contents('php://input')); $s = getDoc($pdo,'meta','settings',false,$isMy);
  $ok = $s && $b && isset($b->phone,$b->hash) && !empty($s['data']->pinHash)
        && hash_equals((string)$s['data']->adminPhone, (string)$b->phone) && hash_equals((string)$s['data']->pinHash, (string)$b->hash);
  if (!$ok) { $pdo->prepare("INSERT INTO attempts (ip,t) VALUES (?,?)")->execute([$ip, nowMs()]); out(200, ['ok'=>false]); }
  out(200, ['ok'=>true, 'token'=>mkToken($cfg)]);
}

/* ---- đọc ---- */
if ($method === 'GET') {
  if (isset($_GET['c'])) {
    $c = (string)$_GET['c']; if (!in_array($c, COLS, true)) fail(400, 'Collection không hợp lệ');
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $st = $pdo->prepare("SELECT id,data,u FROM docs WHERE coll=? AND u>=? ORDER BY u, id"); $st->execute([$c,$since]);
    $docs = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $docs[] = ['id'=>$r['id'], 'data'=>pubDoc($c,$r['id'],json_decode($r['data'])), 'u'=>(int)$r['u']];
    out(200, ['docs'=>$docs, 'now'=>nowMs()]);
  }
  if (isset($_GET['p'])) {
    $p = explode('/', (string)$_GET['p'], 2); if (count($p)!==2 || !in_array($p[0], COLS, true)) fail(400, 'Đường dẫn không hợp lệ');
    $d = getDoc($pdo,$p[0],$p[1],false,$isMy);
    out(200, $d ? ['exists'=>true,'data'=>pubDoc($p[0],$p[1],$d['data']),'u'=>$d['u']] : ['exists'=>false]);
  }
  fail(400, 'Yêu cầu không hợp lệ');
}

/* ---- ghi ---- */
if ($method === 'PUT') {
  $raw = file_get_contents('php://input'); if (strlen($raw) > MAX_BODY) fail(413, 'Dữ liệu quá lớn');
  $b = json_decode($raw); $p = explode('/', (string)($_GET['p'] ?? ''), 2);
  if (!$b || !isset($b->op) || !isset($b->data) || !is_object($b->data) || count($p)!==2 || !in_array($p[0], COLS, true)
      || !preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $p[1])) fail(400, 'Yêu cầu không hợp lệ');
  [$c,$id] = $p; $op = $b->op; $data = $b->data;
  if (!in_array($op, ['set','update','create'], true)) fail(400, 'Thao tác không hợp lệ');
  $bootstrap = false;
  $pdo->beginTransaction();
  try {
    $cur = getDoc($pdo,$c,$id,true,$isMy);
    /* quyền */
    if (!$admin) {
      if ($c === 'meta' && $id === 'settings' && !$cur && $op === 'set') { $bootstrap = true; }          // tạo admin lần đầu
      elseif ($c === 'users') {
        if ($op === 'set' && $cur) throw new RuntimeException('Không có quyền.');
        if ($op === 'update') { foreach ($data as $k=>$_) if (!in_array($k, ['autoDefault','autoDone','seq','code'], true)) throw new RuntimeException('Không có quyền.'); }
      }
      elseif ($c === 'orders') {
        if (isset($data->date) && cutoffClosed($pdo,$isMy,(string)$data->date) && $op !== 'update') throw new RuntimeException('Đã quá giờ chốt.');
        if ($cur && cutoffClosed($pdo,$isMy,(string)($cur['data']->date ?? '1970-01-01'))) throw new RuntimeException('Đã quá giờ chốt.');
        if ($op === 'update') { foreach ($data as $k=>$_) if (!in_array($k, ['status','canceledAt','note'], true)) throw new RuntimeException('Không có quyền.'); }
        if ($op === 'set' && $cur) throw new RuntimeException('Không có quyền.');
      } else throw new RuntimeException('Cần đăng nhập admin.');
    }
    if ($op === 'create' && $cur) throw new RuntimeException('Dữ liệu đã tồn tại.');
    if ($op === 'update') { if (!$cur) throw new RuntimeException('Không tìm thấy dữ liệu'); $new = merge($cur['data'], $data); }
    else $new = $data;
    $json = json_encode($new, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (strlen($json) > MAX_DOC) throw new RuntimeException('Dữ liệu quá lớn (ảnh nên dưới 100KB).');
    $u = nowMs();
    if ($cur && $u <= $cur['u']) $u = $cur['u'] + 1;
    if ($cur) $pdo->prepare("UPDATE docs SET data=?, u=? WHERE coll=? AND id=?")->execute([$json,$u,$c,$id]);
    else $pdo->prepare("INSERT INTO docs (coll,id,data,u) VALUES (?,?,?,?)")->execute([$c,$id,$json,$u]);
    $pdo->commit();
  } catch (RuntimeException $e) { $pdo->rollBack(); fail(403, $e->getMessage(), 'denied'); }
    catch (Throwable $e) { $pdo->rollBack(); fail(500, 'Lỗi máy chủ'); }
  $res = ['ok'=>true, 'u'=>$u, 'data'=>pubDoc($c,$id,json_decode($json))];
  if ($bootstrap) $res['token'] = mkToken($cfg);
  out(200, $res);
}
fail(405, 'Phương thức không hỗ trợ');
