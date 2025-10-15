<?php
// home.php — Landing page (moved from old index.php)

function new_id(int $len = 16): string {
    return bin2hex(random_bytes($len/2));
}

$id = new_id();
$base = rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'), '/');

$hookPretty = $base . '/hook/' . $id;
$viewPretty = $base . '/view/' . $id;

$hookQuery  = $base . '/webhook.php?id=' . $id; // fallback
$viewQuery  = $base . '/viewer.php?id=' . $id;  // fallback
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<title>Mini Webhook — New ID</title>
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
kbd,
pre {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace
}

pre {
  background: #0b0f14;
  color: #d1d5db;
  border: 1px solid #1f2937;
  border-radius: 8px;
  padding: 10px;
  overflow: auto;
}

.card {
  background: #161a20;
  border: 1px solid #2a2f36;
  border-radius: 12px;
  padding: 16px;
  margin: 16px 0
}

.row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap
}

.btn {
  display: inline-block;
  padding: 8px 12px;
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

<h1>Mini Webhook</h1>
<p>Use this unique ID to receive and view requests.</p>

<div class="card">
  <h3>Endpoints</h3>
  <ul>
    <li>Receive (pretty): <code><?=$hookPretty?></code></li>
    <li>View (pretty): <code><?=$viewPretty?></code></li>
    <li>Receive (fallback): <code><?=$hookQuery?></code></li>
    <li>View (fallback): <code><?=$viewQuery?></code></li>
  </ul>
  <div class="row">
    <a class="btn" href="<?=$viewPretty?>">Open Viewer</a>
    <a class="btn" href="<?=$viewQuery?>">Open Viewer (fallback)</a>
  </div>
  <p class="muted">Repository: <a class="gh-link" href="https://github.com/akhmadnuzula/webhook" target="_blank" rel="noopener">github.com/akhmadnuzula/webhook</a></p>
  <p style="margin-top:8px" class="muted">Tip: Simpan URL <strong>View</strong> agar kamu bisa memantau request yang masuk.</p>
  <div class="card" style="margin-top:12px">
    <strong>Quick test (cURL)</strong>
    <pre>curl -X POST '<?=$hookPretty?>' \
  -H 'Content-Type: application/json' \
  -d '{"event":"ping","data":{"hello":"world"}}'</pre>
  </div>
</div>