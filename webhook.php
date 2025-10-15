<?php
// webhook.php — receive & store ANY HTTP request for a given {id}
header('Content-Type: application/json; charset=utf-8');

$id = preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing id']);
    exit;
}

// prepare storage dir
$storage = __DIR__ . '/storage/' . $id;
$uploads = $storage . '/uploads';
if (!is_dir($storage)) { @mkdir($storage, 0775, true); }
if (!is_dir($uploads)) { @mkdir($uploads, 0775, true); }

// helpers
function get_headers_fallback(): array {
    if (function_exists('getallheaders')) return getallheaders();
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $out[$name] = $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) $out['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    if (isset($_SERVER['CONTENT_LENGTH'])) $out['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
    return $out;
}

$headers = get_headers_fallback();
$raw = file_get_contents('php://input') ?? '';
$parsedJson = null;
if (isset($headers['Content-Type']) && stripos($headers['Content-Type'], 'application/json') !== false) {
    $parsedJson = json_decode($raw, true);
}

// save uploaded files (if any)
$savedFiles = [];
if (!empty($_FILES)) {
    $flatten = function(array $files) {
        $out = [];
        foreach ($files as $field => $info) {
            if (is_array($info['name'])) {
                foreach ($info['name'] as $i => $name) {
                    $out[] = [
                        'field' => $field,
                        'name'  => $name,
                        'type'  => $info['type'][$i] ?? '',
                        'tmp'   => $info['tmp_name'][$i] ?? '',
                        'err'   => $info['error'][$i] ?? 0,
                        'size'  => $info['size'][$i] ?? 0,
                    ];
                }
            } else {
                $out[] = [
                    'field' => $field,
                    'name'  => $info['name'] ?? '',
                    'type'  => $info['type'] ?? '',
                    'tmp'   => $info['tmp_name'] ?? '',
                    'err'   => $info['error'] ?? 0,
                    'size'  => $info['size'] ?? 0,
                ];
            }
        }
        return $out;
    };

    foreach ($flatten($_FILES) as $f) {
        $safeName = preg_replace('~[^\w.\-]+~', '_', $f['name'] ?: 'file');
        $dest = $uploads . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;
        if (!$f['err'] && is_uploaded_file($f['tmp'])) {
            @move_uploaded_file($f['tmp'], $dest);
            $savedFiles[] = ['field'=>$f['field'], 'name'=>$safeName, 'path'=>basename($dest), 'type'=>$f['type'], 'size'=>$f['size']];
        } else {
            $savedFiles[] = ['field'=>$f['field'], 'name'=>$safeName, 'error'=>$f['err']];
        }
    }
}

// build record
$record = [
    'timestamp'    => date('c'),
    'remote_addr'  => $_SERVER['REMOTE_ADDR'] ?? null,
    'method'       => $_SERVER['REQUEST_METHOD'] ?? null,
    'path'         => $_SERVER['REQUEST_URI'] ?? null,
    'id'           => $id,
    'query'        => $_GET,
    'headers'      => $headers,
    'content_type' => $headers['Content-Type'] ?? null,
    'raw'          => $raw,
    'json'         => $parsedJson,
    'form'         => $_POST,
    'files'        => $savedFiles,
    'ua'           => $_SERVER['HTTP_USER_AGENT'] ?? null,
];

// save as JSON
$fname = $storage . '/' . date('Ymd_His') . '_' . substr((string)microtime(true), -6) . '_' . bin2hex(random_bytes(2)) . '.json';
file_put_contents($fname, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode(['ok'=>true,'message'=>'stored','request_file'=>basename($fname),'view'=>basename(dirname(__FILE__)) . '/view/' . $id], JSON_UNESCAPED_SLASHES);