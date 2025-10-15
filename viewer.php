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

// ====== Render Preview (server-side snippet for AJAX or first load) ======
function render_preview_block(?string $filePath, string $id, string $viewURL){
    if (!$filePath || !is_file($filePath)) {
        return '<div class="card"><em>No file selected.</em></div>';
    }
    $json = json_decode(file_get_contents($filePath), true) ?: [];
    ob_start(); ?>
<div class="card" id="preview-card">
  <h3 style="margin-top:0">Request Preview <span class="muted">(<?=h(basename($filePath))?>)</span></h3>
  <div><span class="pill"><?=h($json['method'] ?? '-')?></span> &nbsp; <?=h($json['timestamp'] ?? '-')?></div>
  <p class="muted" style="margin:6px 0"><?=h($json['path'] ?? '')?></p>

  <details open>
    <summary><strong>Headers</strong></summary>
    <pre><?=h(json_encode($json['headers'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
  </details>
  <details>
    <summary><strong>Query</strong></summary>
    <pre><?=h(json_encode($json['query'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
  </details>
  <details>
    <summary><strong>Form</strong></summary>
    <pre><?=h(json_encode($json['form'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
  </details>
  <details>
    <summary><strong>JSON (parsed)</strong></summary>
    <pre><?=h(json_encode($json['json'] ?? new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?></pre>
  </details>
  <details>
    <summary><strong>Raw Body</strong></summary>
    <pre><?=h($json['raw'] ?? '')?></pre>
  </details>
  <details>
    <summary><strong>Files</strong></summary>
    <pre><?=h(json_encode($json['files'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
    <p class="muted">Saved under <code>storage/<?=h($id)?>/uploads/</code></p>
  </details>

  <p class="muted">From: <?=h($json['remote_addr'] ?? '-')?> — UA: <?=h($json['ua'] ?? '-')?></p>
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

.gh-link {
  color: #a78bfa;
  text-decoration: none;
}

.gh-link:hover {
  color: #c4b5fd;
  text-decoration: underline;
}
</style>

<h1>Mini Webhook Viewer</h1>
<div class="card">
  <div class="toolbar">
    <div><strong>ID:</strong> <code><?=h($id)?></code></div>
    <div>Receive: <code><?=h($hookURL)?></code></div>
    <div>View: <code><?=h($viewURL)?></code></div>
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
</script>