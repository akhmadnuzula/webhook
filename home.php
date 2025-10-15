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
body {
  font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  margin: 24px;
  line-height: 1.45
}

code,
kbd,
pre {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace
}

.card {
  border: 1px solid #ddd;
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
  border: 1px solid #333;
  border-radius: 8px;
  text-decoration: none
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
  <p style="margin-top:8px" class="muted">Tip: Simpan URL <strong>View</strong> agar kamu bisa memantau request yang masuk.</p>
  <div class="card" style="background:#fafafa;margin-top:12px">
    <strong>Quick test (cURL)</strong>
    <pre>curl -X POST '<?=$hookPretty?>' \
  -H 'Content-Type: application/json' \
  -d '{"event":"ping","data":{"hello":"world"}}'</pre>
  </div>
</div>

