<?php
// viewer.php — Mini Webhook Viewer (inline preview below list, single page)
// PHP 7.4+ compatible

// ====== Helpers ======
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function starts_with_path($path, $base){
    $path = rtrim(str_replace('\\','/', (string)$path), '/');
    $base = rtrim(str_replace('\\','/', (string)$base), '/');
    return strpos($path, $base) === 0;
}

// ====== Inputs ======
$id = preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id'] ?? '');
// Prevent caching so auto-refresh always sees latest list
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');
@header('Expires: 0');
if ($id === '') { http_response_code(400); echo 'Missing id'; exit; }

$storage = __DIR__ . '/storage/' . $id;
$uploads = $storage . '/uploads';
@mkdir($storage, 0775, true);
@mkdir($uploads, 0775, true);

$files = array_values(array_filter(glob($storage . '/*.json') ?: [], 'is_file'));
rsort($files); // newest first

$base = rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'), '/');
$hookPretty = $base . '/hook/' . $id;
$viewPretty = $base . '/view/' . $id;
$hookQuery  = $base . '/webhook.php?id=' . $id;
$viewQuery  = $base . '/viewer.php?id=' . $id;
// Prefer pretty URLs when request URI already uses them (i.e., real rewrites)
$usingPretty = preg_match('~/(view|hook)/[A-Za-z0-9_-]+~', $_SERVER['REQUEST_URI'] ?? '') === 1;
$hookURL = $usingPretty ? $hookPretty : $hookQuery;
$viewURL = $usingPretty ? $viewPretty : $viewQuery;

$selectedFileBasename = isset($_GET['f']) ? basename($_GET['f']) : null;
$selectedFilePath = null;
if ($selectedFileBasename) {
    $candidate = realpath($storage . '/' . $selectedFileBasename);
    if ($candidate && starts_with_path($candidate, realpath($storage))) {
        $selectedFilePath = $candidate;
    }
}

// ====== Relay (proxy) to external URL to avoid CORS ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relay') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['ok' => false];
    try {
        $target = trim((string)($_POST['url'] ?? ''));
        $fileBn = basename((string)($_POST['f'] ?? ''));

        if ($target === '') { throw new RuntimeException('URL is required'); }
        $parts = @parse_url($target);
        if (!$parts || !isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http','https'], true)) {
            throw new RuntimeException('Invalid URL scheme');
        }

        $candidate = realpath($storage . '/' . $fileBn);
        if (!$candidate || !is_file($candidate) || !starts_with_path($candidate, realpath($storage))) {
            throw new RuntimeException('Invalid or missing file reference');
        }

        $record = json_decode((string)file_get_contents($candidate), true) ?: [];

        // Merge querystring: target URL existing query + recorded query
        $recQuery = (array)($record['query'] ?? []);
        $targetParts = parse_url($target);
        $baseUrl = (isset($targetParts['scheme']) ? $targetParts['scheme'] . '://' : '')
                 . ($targetParts['host'] ?? '')
                 . (isset($targetParts['port']) ? ':' . $targetParts['port'] : '')
                 . ($targetParts['path'] ?? '');
        $targetQs = [];
        if (!empty($targetParts['query'])) { parse_str($targetParts['query'], $targetQs); }
        // recorded query should be added without overwriting explicit target params
        $finalQs = $targetQs + $recQuery;
        $targetFinal = $baseUrl . (empty($finalQs) ? '' : ('?' . http_build_query($finalQs)));

        // Determine body/method/content-type based on recorded data
        $hasFiles = !empty($record['files']);
        $hasForm  = !empty($record['form']);
        $hasJson  = is_array($record['json'] ?? null);
        $rawBody  = (string)($record['raw'] ?? '');

        $method = 'POST'; // default to POST for callbacks
        $body = null;
        $ctype = null;
        $postFields = null; // for multipart

        // Build outgoing body
        if ($hasFiles) {
            // multipart/form-data with form fields and files
            $postFields = [];
            // flatten form fields with bracket notation
            $flatten = function($value, string $prefix = '') use (&$flatten, &$postFields) {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $key = $prefix === '' ? (string)$k : ($prefix . '[' . $k . ']');
                        $flatten($v, $key);
                    }
                } else {
                    if ($prefix !== '') $postFields[$prefix] = (string)$value;
                }
            };
            $flatten($record['form'] ?? []);

            // attach files from storage/id/uploads
            $grouped = [];
            foreach ((array)$record['files'] as $f) {
                $fld = (string)($f['field'] ?? 'file');
                $grouped[$fld][] = $f;
            }
            $uploadsDir = $uploads;
            foreach ($grouped as $field => $list) {
                foreach (array_values($list) as $i => $f) {
                    $name = $f['name'] ?? 'file';
                    $type = $f['type'] ?? '';
                    $rel  = basename((string)($f['path'] ?? ''));
                    $path = realpath($uploadsDir . '/' . $rel);
                    if (!$path || !starts_with_path($path, realpath($uploadsDir)) || !is_file($path)) {
                        continue;
                    }
                    $key = (count($list) > 1) ? ($field . '[' . $i . ']') : $field;
                    $postFields[$key] = new CURLFile($path, $type ?: null, $name ?: null);
                }
            }
            // let cURL set multipart content-type
            $ctype = null;
            $method = 'POST';
        } elseif ($hasForm) {
            // application/x-www-form-urlencoded
            $body = http_build_query($record['form']);
            $ctype = 'application/x-www-form-urlencoded';
            $method = 'POST';
        } elseif ($hasJson) {
            // JSON body
            $body = json_encode($record['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ctype = 'application/json';
            $method = 'POST';
        } else {
            // Raw body
            $body = $rawBody;
            $ctype = (string)($record['content_type'] ?? 'text/plain');
            $method = 'POST';
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available on this PHP runtime');
        }

        $ch = curl_init($targetFinal);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        }

        // Build headers: forward original (filtered) + UA and Content-Type (unless multipart)
        $origHeaders = (array)($record['headers'] ?? []);
        $skip = [
            'host','content-length','transfer-encoding','connection','keep-alive','upgrade',
            'accept-encoding','content-encoding','expect','origin','referer'
        ];
        $hdrs = ['User-Agent: Mini-Webhook-Viewer/1.0'];
        foreach ($origHeaders as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $skip, true)) continue;
            if ($postFields !== null && $lk === 'content-type') continue; // let cURL set multipart boundary
            if ($ctype !== null && $lk === 'content-type') continue; // we will set our own below
            $hdrs[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : $v);
        }
        if ($postFields === null && $ctype !== null) {
            $hdrs[] = 'Content-Type: ' . $ctype;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $start = microtime(true);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tookMs = (int)round((microtime(true) - $start) * 1000);
        curl_close($ch);

        if ($out === false) {
            throw new RuntimeException('cURL error: ' . $err);
        }

        $resp['ok'] = true;
        $resp['status'] = $code;
        $resp['bytes'] = strlen((string)$out);
        $resp['time_ms'] = $tookMs;
        $resp['sent'] = [
            'url' => $targetFinal,
            'method' => $method,
            'content_type' => ($postFields !== null ? 'multipart/form-data' : ($ctype ?: null)),
            'forwarded_headers' => count($hdrs)
        ];
        // Limit response echo to avoid huge payloads in UI
        $resp['response_preview'] = substr((string)$out, 0, 4000);
    } catch (Throwable $e) {
        $resp['error'] = $e->getMessage();
    }
    echo json_encode($resp, JSON_UNESCAPED_SLASHES);
    exit;
}

// ====== Render Preview (server-side snippet for AJAX or first load) ======
function render_preview_block(?string $filePath, string $id, string $viewURL){
    if (!$filePath || !is_file($filePath)) {
        return '<div class="card"><em>No file selected.</em></div>';
    }
    $json = json_decode(file_get_contents($filePath), true) ?: [];
    ob_start(); ?>
<div class="card preview-card" id="preview-card" data-file="<?=h(basename($filePath))?>">
  <h3 style="margin-top:0">Request Preview <span class="muted">(<?=h(basename($filePath))?>)</span></h3>
  <div><span class="pill"><?=h($json['method'] ?? '-')?></span> &nbsp; <?=h($json['timestamp'] ?? '-')?></div>
  <p class="muted" style="margin:6px 0"><?=h($json['path'] ?? '')?></p>

  <details open>
    <summary><strong>Headers</strong> <button type="button" class="btn copy-btn js-copy">Copy</button></summary>
    <pre><?=h(json_encode($json['headers'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
  </details>
  <details>
    <summary><strong>Query</strong> <button type="button" class="btn copy-btn js-copy">Copy</button></summary>
    <pre><?=h(json_encode($json['query'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
  </details>
  <details>
    <summary><strong>Form</strong> <button type="button" class="btn copy-btn js-copy">Copy</button></summary>
    <pre><?=h(json_encode($json['form'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
  </details>
  <details>
    <summary><strong>JSON (parsed)</strong> <button type="button" class="btn copy-btn js-copy">Copy</button></summary>
    <pre><?=h(json_encode($json['json'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?></pre>
  </details>
  <details>
    <summary><strong>Raw Body</strong> <button type="button" class="btn copy-btn js-copy">Copy</button></summary>
    <pre><?=h($json['raw'] ?? '')?></pre>
  </details>
  <details>
    <summary><strong>Files</strong> <button type="button" class="btn copy-btn js-copy">Copy</button></summary>
    <pre><?=h(json_encode($json['files'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
    <p class="muted">Saved under <code>storage/<?=h($id)?>/uploads/</code></p>
  </details>

  <div class="toolbar" style="margin:8px 0 12px 0">
    <input type="url" class="relay-url" placeholder="http://127.0.0.1:8000/api/midtrans-callback" style="flex:1;min-width:320px;padding:6px 8px;border-radius:8px;border:1px solid #3b3f46;background:#0b0f14;color:#e5e7eb" title="Target URL to forward using recorded headers/query/form/files/body">
    <button type="button" class="btn js-send-browser" title="Send directly from your browser (client-side)">Send (Browser)</button>
  </div>
  <span class="muted" style="font-size:12px">Client-side send: useful for localhost targets; ensure CORS is allowed by your app.</span>
  <details open>
    <summary><strong>Send Result</strong></summary>
    <pre class="relay-result muted" style="min-height:32px"></pre>
  </details>
  <script type="application/json" class="record-data">
  <?=json_encode(
        $json,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    )?>
  </script>
</div>
<?php
    return ob_get_clean();
}

// ====== Partial mode (for AJAX) ======
if (isset($_GET['partial']) && $_GET['partial'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo render_preview_block($selectedFilePath, $id, $viewURL);
    exit;
}

// ====== Full Page ======
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<title>Mini Webhook Viewer — <?=h($id)?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
/* Dark theme */
body {
  font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  margin: 24px;
  line-height: 1.45;
  background: #0f1216;
  color: #e5e7eb;
}

code,
pre {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

pre {
  background: #0b0f14;
  color: #d1d5db;
  border: 1px solid #1f2937;
  border-radius: 8px;
  padding: 10px;
  overflow: auto;
}

.row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap
}

.card {
  background: #161a20;
  border: 1px solid #2a2f36;
  border-radius: 12px;
  padding: 16px;
  margin: 16px 0
}

.list tr {
  border-bottom: 1px solid #2a2f36
}

.list th,
.list td {
  padding: 8px;
  text-align: left;
  vertical-align: top
}

.pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  border: 1px solid #4b5563;
  font-size: 12px;
  color: #cbd5e1;
}

.muted {
  color: #9aa3af
}

.file-link {
  color: #60a5fa;
  text-decoration: none
}

.file-link:hover {
  color: #93c5fd;
  text-decoration: underline
}

.active-row {
  background: #18222f
}

details {
  margin: 8px 0
}

.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap
}

.btn {
  display: inline-block;
  padding: 6px 10px;
  border: 1px solid #3b3f46;
  border-radius: 8px;
  text-decoration: none;
  color: #e5e7eb;
  background: transparent;
}

.btn:hover {
  background: #1f2937
}

.copy-btn {
  font-size: 12px;
  padding: 2px 8px;
  margin-left: 8px;
  border-color: #4b5563;
}

.copy-btn.copied {
  color: #10b981;
  border-color: #10b981;
}

.gh-link {
  color: #a78bfa;
  text-decoration: none;
}

.gh-link:hover {
  color: #c4b5fd;
  text-decoration: underline;
}

/* Wrap and constrain relay/send result area */
.relay-result {
  white-space: pre-wrap;
  /* allow wrapping while preserving newlines */
  overflow-wrap: anywhere;
  /* break long tokens */
  word-break: break-word;
  /* older browsers */
  width: 100%;
  /* fill container width */
  max-width: 100%;
  overflow-x: hidden;
  /* avoid horizontal scroll */
  max-height: 320px;
  /* cap height */
  overflow-y: auto;
  /* vertical scroll if long */
}
</style>

<h1>Mini Webhook Viewer</h1>
<div class="card">
  <div class="toolbar">
    <div><strong>ID:</strong> <code><?=h($id)?></code> <button type="button" class="btn copy-btn js-copy" data-copy="<?=h($id)?>">Copy</button></div>
    <div>Receive: <code><?=h($hookURL)?></code> <button type="button" class="btn copy-btn js-copy" data-copy="<?=h($hookURL)?>">Copy</button></div>
    <div>View: <code><?=h($viewURL)?></code> <button type="button" class="btn copy-btn js-copy" data-copy="<?=h($viewURL)?>">Copy</button></div>
    <span style="flex:1"></span>
    <a class="gh-link" href="https://github.com/akhmadnuzula/webhook" target="_blank" rel="noopener">Public Repo</a>
    <label><input type="checkbox" id="auto"> Auto-refresh (2s)</label>
  </div>
  <div class="card" style="margin-top:12px">
    <strong>cURL test</strong>
    <pre>curl -X POST '<?=h($hookURL)?>' \
  -H 'Content-Type: application/json' \
  -d '{"hello":"world"}'</pre>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0">Requests (newest first)</h3>
  <?php if (!$files): ?>
  <p>No requests yet. Send one to <code><?=h($hookURL)?></code></p>
  <?php else: ?>
  <table class="list" width="100%" cellspacing="0" id="files-table">
    <thead>
      <tr>
        <th style="width:220px">Time</th>
        <th style="width:90px">Method</th>
        <th style="width:110px">Size</th>
        <th>File</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($files as $f):
            $sz = filesize($f);
            $b = basename($f);
            $meta = json_decode(@file_get_contents($f), true) ?: [];
            $isSel = ($b === $selectedFileBasename);
        ?>
      <tr class="<?= $isSel ? 'active-row' : '' ?>" data-file="<?=h($b)?>">
        <td><?=h($meta['timestamp'] ?? '-')?></td>
        <td><span class="pill"><?=h($meta['method'] ?? '-')?></span></td>
        <td><?=number_format($sz)?> B</td>
        <td><a class="file-link js-file" href="<?=h($viewURL)?>?f=<?=h($b)?>"><?=h($b)?></a></td>
      </tr>
      <?php if ($isSel): ?>
      <tr class="preview-row" data-for="<?=h($b)?>">
        <td colspan="4"><?php echo render_preview_block($selectedFilePath, $id, $viewURL); ?></td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Inline preview now renders inside the table as a new row under the selected entry -->

<script>
// ===== Client-side helpers =====
const viewURL = <?=json_encode($viewURL)?>;
const id = <?=json_encode($id)?>;
const qs = new URLSearchParams(location.search);
let table = document.getElementById('files-table');

function removePreviewRow() {
  const existing = document.querySelector('#files-table tr.preview-row');
  if (existing) existing.remove();
}

function insertPreviewRow(afterRow, html) {
  if (!afterRow) return;
  removePreviewRow();
  const tr = document.createElement('tr');
  tr.className = 'preview-row';
  tr.setAttribute('data-for', afterRow.getAttribute('data-file') || '');
  const td = document.createElement('td');
  td.colSpan = 4;
  td.innerHTML = html;
  tr.appendChild(td);
  afterRow.insertAdjacentElement('afterend', tr);
  // Initialize relay URL input with saved value
  const urlInput = tr.querySelector('.relay-url');
  if (urlInput) {
    urlInput.value = localStorage.getItem('relayURL') || 'http://127.0.0.1:8000/api/midtrans-callback';
  }
}

// intercept clicks on file links
document.addEventListener('click', async (e) => {
  const a = e.target.closest('a.js-file');
  if (!a) return;
  e.preventDefault();
  const url = new URL(a.href);
  const f = url.searchParams.get('f');
  if (!f) return;

  // load partial preview
  const partialURL = `${viewURL}?partial=1&f=${encodeURIComponent(f)}`;
  const res = await fetch(partialURL, {
    headers: {
      'X-Requested-With': 'fetch'
    }
  });
  const html = await res.text();

  // highlight row
  if (table) {
    table.querySelectorAll('tr').forEach(tr => tr.classList.remove('active-row'));
    const row = table.querySelector(`tr[data-file="${CSS.escape(f)}"]`);
    if (row) {
      row.classList.add('active-row');
      insertPreviewRow(row, html);
    }
  }

  // update URL without reloading
  const newURL = `${viewURL}?f=${encodeURIComponent(f)}`;
  history.pushState({
    f
  }, '', newURL);
});

// copy button on details summary
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('button.js-copy');
  if (!btn) return;
  e.preventDefault();
  let text = btn.getAttribute('data-copy');
  if (!text) {
    const details = btn.closest('details');
    const pre = details ? details.querySelector('pre') : null;
    if (pre) {
      text = pre.innerText || pre.textContent || '';
    }
  }
  if (!text) return;
  let ok = false;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      ok = true;
    } catch (_) {}
  }
  if (!ok) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      ok = true;
    } catch (_) {}
    document.body.removeChild(ta);
  }
  const old = btn.textContent;
  btn.classList.add('copied');
  btn.textContent = ok ? 'Copied!' : 'Failed';
  setTimeout(() => {
    btn.classList.remove('copied');
    btn.textContent = old;
  }, 1000);
});

// handle back/forward (popstate)
window.addEventListener('popstate', async (evt) => {
  const f = (new URL(location.href)).searchParams.get('f');
  if (!f) {
    removePreviewRow();
    if (table) table.querySelectorAll('tr').forEach(tr => tr.classList.remove('active-row'));
    return;
  }
  const partialURL = `${viewURL}?partial=1&f=${encodeURIComponent(f)}`;
  const res = await fetch(partialURL, {
    headers: {
      'X-Requested-With': 'fetch'
    }
  });
  const html = await res.text();
  if (table) {
    table.querySelectorAll('tr').forEach(tr => tr.classList.remove('active-row'));
    const row = table.querySelector(`tr[data-file="${CSS.escape(f)}"]`);
    if (row) {
      row.classList.add('active-row');
      insertPreviewRow(row, html);
    }
  }
});

// auto-refresh (list only, keep preview)
const auto = document.getElementById('auto');
if (localStorage.getItem('auto') === '1') {
  auto.checked = true;
}
auto.addEventListener('change', () => {
  localStorage.setItem('auto', auto.checked ? '1' : '0');
});
async function refreshList() {
  if (!auto.checked || !table) return;
  // Reload current page but only the list section by refetching HTML and extracting the table
  try {
    const res = await fetch((location.href + (location.href.includes('?') ? '&' : '?') + '_ts=' + Date.now()), {
      headers: {
        'X-Requested-With': 'fetch'
      }
    });
    const text = await res.text();
    const tpl = document.createElement('template');
    tpl.innerHTML = text;
    const newTable = tpl.content.querySelector('#files-table');
    if (newTable) {
      table.replaceWith(newTable);
      table = newTable;
      // re-highlight if selected
      const f = (new URL(location.href)).searchParams.get('f');
      if (f) {
        newTable.querySelectorAll('tr').forEach(tr => tr.classList.remove('active-row'));
        const row = newTable.querySelector(`tr[data-file="${CSS.escape(f)}"]`);
        if (row) row.classList.add('active-row');
      }
    }
  } catch (e) {}
}
setInterval(refreshList, 2000);

// Initialize URL input on first render (server-side rendered preview if any)
document.querySelectorAll('.relay-url').forEach(inp => {
  inp.value = localStorage.getItem('relayURL') || 'http://127.0.0.1:8000/api/midtrans-callback';
});

// Removed server-side send button handler; using browser send only

// Send from browser (client-side)
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('button.js-send-browser');
  if (!btn) return;
  e.preventDefault();
  const card = btn.closest('.preview-card');
  if (!card) return;
  const urlInput = card.querySelector('.relay-url');
  const resultPre = card.querySelector('.relay-result');
  const target = urlInput ? (urlInput.value || '') : '';
  if (!target) {
    alert('Please enter a target URL');
    return;
  }
  let record = {};
  try {
    const script = card.querySelector('script.record-data');
    if (script) record = JSON.parse(script.textContent || '{}');
  } catch (_) {}

  // Build final URL with merged query
  try {
    localStorage.setItem('relayURL', target);
  } catch (_) {}
  const u = new URL(target, location.origin);
  const recQuery = record.query || {};
  for (const [k, v] of Object.entries(recQuery)) {
    if (!u.searchParams.has(k)) {
      if (Array.isArray(v)) {
        v.forEach(item => u.searchParams.append(k, item));
      } else if (v != null) {
        u.searchParams.append(k, String(v));
      }
    }
  }

  // Prepare headers (filtered to reduce CORS issues)
  const headers = new Headers();
  const orig = record.headers || {};
  const skip = new Set(['host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade', 'accept-encoding', 'content-encoding', 'expect', 'origin', 'referer', 'cookie', 'cookie2', 'user-agent']);
  for (const [k, v] of Object.entries(orig)) {
    const lk = String(k).toLowerCase();
    if (skip.has(lk)) continue;
    if (lk.startsWith('sec-')) continue;
    // Allow common auth/custom headers; note: may trigger CORS preflight
    try {
      headers.append(k, Array.isArray(v) ? v.join(', ') : String(v));
    } catch (_) {}
  }

  // Build body and content-type
  let method = 'POST';
  let body = undefined;
  let contentType = null;
  const hasFiles = Array.isArray(record.files) && record.files.length > 0;
  const hasForm = record.form && Object.keys(record.form).length > 0;
  const hasJson = record.json && typeof record.json === 'object';
  const raw = record.raw || '';

  function buildUrlEncoded(obj, prefix) {
    const params = new URLSearchParams();
    const append = (o, pfx) => {
      if (o && typeof o === 'object' && !Array.isArray(o)) {
        for (const [k, v] of Object.entries(o)) {
          const key = pfx ? `${pfx}[${k}]` : k;
          append(v, key);
        }
      } else if (Array.isArray(o)) {
        o.forEach((v, i) => append(v, `${pfx}[${i}]`));
      } else if (pfx) {
        params.append(pfx, o == null ? '' : String(o));
      }
    };
    append(obj, prefix || '');
    return params;
  }

  if (hasFiles) {
    // Cannot resend saved files from browser; send form fields only as multipart
    const fd = new FormData();
    const fill = (o, pfx) => {
      if (o && typeof o === 'object' && !Array.isArray(o)) {
        for (const [k, v] of Object.entries(o)) {
          const key = pfx ? `${pfx}[${k}]` : k;
          fill(v, key);
        }
      } else if (Array.isArray(o)) {
        o.forEach((v, i) => fill(v, `${pfx}[${i}]`));
      } else if (pfx) {
        fd.append(pfx, o == null ? '' : String(o));
      }
    };
    fill(record.form || {}, '');
    body = fd;
    // Browser will set multipart/form-data boundary automatically
  } else if (hasForm) {
    const params = buildUrlEncoded(record.form);
    body = params.toString();
    contentType = 'application/x-www-form-urlencoded';
  } else if (hasJson) {
    body = JSON.stringify(record.json);
    contentType = 'application/json';
  } else {
    body = String(raw || '');
    contentType = (record.content_type || 'text/plain');
  }

  if (contentType) headers.set('Content-Type', contentType);

  if (resultPre) resultPre.textContent = 'Sending (browser)...';
  const t0 = performance.now();
  try {
    const res = await fetch(u.toString(), {
      method,
      headers,
      body,
      credentials: 'omit',
      cache: 'no-store',
      redirect: 'follow'
    });
    const dt = Math.round(performance.now() - t0);
    let preview = '';
    try {
      preview = await res.text();
    } catch (_) {
      preview = '';
    }
    const lines = [
      `Status: ${res.status} | Time: ${dt} ms`,
      `Sent: ${method} ${u.toString()}`,
      `Content-Type: ${contentType || '-'}`,
      '--- Response Preview ---',
      (preview || '')
    ];
    if (resultPre) resultPre.textContent = lines.join('\n');
  } catch (err) {
    const msg = String(err || '');
    const hint = 'If targeting localhost: ensure your app allows CORS and avoid mixed-content (https page -> http target).';
    if (resultPre) resultPre.textContent = 'Browser send error: ' + msg + '\n' + hint;
  }
});
</script>
