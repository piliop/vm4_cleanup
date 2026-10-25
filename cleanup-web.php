<?php
declare(strict_types=1);

const CLI_SCRIPT = __DIR__ . DIRECTORY_SEPARATOR . 'cleanup-cli.php';
const PRIVATE_CONFIG_PATH = ''; // Set to an absolute PHP config path outside the web root.

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function webPasswordMatches(string $stored, string $provided): bool
{
    if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $stored) === 1) {
        return password_verify($provided, $stored);
    }
    return hash_equals($stored, $provided);
}

function isLoopbackAddress(string $address): bool
{
    $packed = @inet_pton($address);
    return $packed !== false && (
        $address === '127.0.0.1'
        || $address === '::1'
        || $packed === inet_pton('127.0.0.1')
        || $packed === inet_pton('::1')
    );
}

function normalizedPath(string $path): string
{
    $real = realpath($path);
    if ($real !== false) {
        return $real;
    }
    $parent = realpath(dirname($path));
    if ($parent === false) {
        throw new RuntimeException("Cannot resolve directory for path: {$path}");
    }
    return $parent . DIRECTORY_SEPARATOR . basename($path);
}

function pathIsInside(string $path, string $directory): bool
{
    $path = rtrim(normalizedPath($path), DIRECTORY_SEPARATOR);
    $directory = rtrim(normalizedPath($directory), DIRECTORY_SEPARATOR);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $directory = strtolower($directory);
    }
    return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
}

function requirePrivatePath(string $path, string $label): string
{
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($documentRoot !== '' && pathIsInside($path, $documentRoot)) {
        throw new RuntimeException("Keep {$label} outside the web document root.");
    }
    return $path;
}

function loadAppConfig(): array
{
    $environmentPath = getenv('VM4_CLEANUP_CONFIG');
    $configPath = $environmentPath !== false && $environmentPath !== ''
        ? $environmentPath
        : PRIVATE_CONFIG_PATH;
    if ($configPath === '' || !is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('Set PRIVATE_CONFIG_PATH near the top of cleanup-web.php to a readable private PHP config file outside the web root.');
    }
    requirePrivatePath($configPath, 'the combined config file');
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('The combined config file must return an array.');
    }
    $password = (string)($config['web_password'] ?? '');
    if (strlen($password) < 16) {
        throw new RuntimeException('Set web_password in the private combined config to a unique password of at least 16 characters.');
    }
    $sites = $config['web_sites'] ?? null;
    if (!is_array($sites) || $sites === []) {
        throw new RuntimeException('The combined config must contain a non-empty web_sites map of labels to private site config paths.');
    }
    foreach ($sites as $label => $configPath) {
        if (!is_string($label) || trim($label) === '' || !is_string($configPath) || !is_file($configPath) || !is_readable($configPath)) {
            throw new RuntimeException('Every site map entry must have a non-empty label and a readable PHP config path.');
        }
        requirePrivatePath($configPath, 'each site config');
    }
    return $config;
}

function loadSiteConfig(array $sites, string $siteKey): array
{
    if (!array_key_exists($siteKey, $sites)) {
        throw new InvalidArgumentException('Select a valid configured site.');
    }
    $config = require $sites[$siteKey];
    if (!is_array($config)) {
        throw new RuntimeException("The selected site's config must return an array.");
    }
    return $config;
}

function auditPath(array $config): string
{
    $path = (string)($config['audit_db'] ?? '');
    if ($path === '' || !preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path)) {
        throw new RuntimeException('Set audit_db to an absolute SQLite file path outside the web root.');
    }
    $path = normalizedPath($path);
    requirePrivatePath($path, 'the SQLite audit database');
    return $path;
}

function positiveInteger(string $value, string $name, ?int $default = null): ?int
{
    if ($value === '') {
        return $default;
    }
    if (!ctype_digit($value) || (int)$value < 1) {
        throw new InvalidArgumentException("{$name} must be a positive integer.");
    }
    return (int)$value;
}

function validCutoff(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Enter a valid cutoff date.');
    }
    return $value;
}

function runCleanup(array $sites, string $siteKey, array $input): string
{
    $mode = (string)($input['mode'] ?? '');
    if (!in_array($mode, ['orphan-images', 'old-products', 'all'], true)) {
        throw new InvalidArgumentException('Choose a valid cleanup operation.');
    }
    $cutoff = trim((string)($input['cutoff'] ?? ''));
    if (in_array($mode, ['old-products', 'all'], true)) {
        $cutoff = validCutoff($cutoff);
    }
    $limit = positiveInteger(trim((string)($input['limit'] ?? '')), 'Candidate limit');
    $batchSize = positiveInteger(trim((string)($input['batch_size'] ?? '100')), 'Batch size', 100);
    $execute = ($input['run_type'] ?? '') === 'execute';
    if (!$execute && ($input['run_type'] ?? '') !== 'dry-run') {
        throw new InvalidArgumentException('Choose dry-run or execute.');
    }
    if ($execute && !hash_equals('DELETE', trim((string)($input['confirmation'] ?? '')))) {
        throw new InvalidArgumentException('Type DELETE to confirm this permanent operation.');
    }

    $config = loadSiteConfig($sites, $siteKey);
    auditPath($config);
    $options = [
        'mode' => $mode,
        'cutoff' => $cutoff !== '' ? $cutoff : null,
        'execute' => $execute,
        'yes' => true,
        'limit' => $limit,
        'batch-size' => $batchSize,
        'site-label' => $siteKey,
        'audit-db' => null,
        'media-root' => null,
    ];
    if (!defined('VM4_CLEANUP_WEB_CONTEXT')) {
        define('VM4_CLEANUP_WEB_CONTEXT', true);
    }
    if (!defined('VM4_CLEANUP_LIBRARY_ONLY')) {
        define('VM4_CLEANUP_LIBRARY_ONLY', true);
    }
    if (!is_file(CLI_SCRIPT) || !is_readable(CLI_SCRIPT)) {
        throw new RuntimeException('cleanup-cli.php is missing or unreadable.');
    }
    require_once CLI_SCRIPT;
    ob_start();
    try {
        (new Cleanup($config, $options))->run();
        return trim((string)ob_get_clean());
    } catch (Throwable $e) {
        $output = trim((string)ob_get_clean());
        throw new RuntimeException(trim($output . "\n" . $e->getMessage()), 0, $e);
    }
}

function readAudit(string $path, ?int $runId): array
{
    if (!is_file($path)) {
        return ['runs' => [], 'events' => [], 'selected_run' => null, 'exists' => false];
    }
    if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('The PDO SQLite extension is required to view the audit database.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $runs = $db->query('SELECT run_id, started_at, finished_at, mode, cutoff_date, dry_run, site_label, summary
                        FROM cleanup_runs ORDER BY run_id DESC LIMIT 30')->fetchAll();
    $events = [];
    if ($runId !== null) {
        $stmt = $db->prepare('SELECT event_id, event_type, entity_table, entity_id, status, payload_json, recorded_at
                              FROM cleanup_events WHERE run_id = ? ORDER BY event_id LIMIT 100');
        $stmt->execute([$runId]);
        $events = $stmt->fetchAll();
        $exists = false;
        foreach ($runs as $run) {
            if ((int)$run['run_id'] === $runId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            throw new InvalidArgumentException('The selected audit run was not found among the latest 30 runs.');
        }
    }
    return ['runs' => $runs, 'events' => $events, 'selected_run' => $runId, 'exists' => true];
}

function statusBadge(string $status): string
{
    $class = match ($status) {
        'deleted' => 'good',
        'pending' => 'warn',
        'dry-run' => 'neutral',
        default => 'neutral',
    };
    return '<span class="badge ' . $class . '">' . h($status) . '</span>';
}

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
$isLoopback = isLoopbackAddress((string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!$isHttps && !$isLoopback) {
    http_response_code(403);
    exit('HTTPS is required to use the cleanup page.');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

$combinedConfig = [];
$startupError = null;
$sites = [];
try {
    $combinedConfig = loadAppConfig();
    $sites = $combinedConfig['web_sites'];
} catch (Throwable $e) {
    $startupError = $e->getMessage();
}
$password = (string)($combinedConfig['web_password'] ?? '');
$allowHttpLocalhost = ($combinedConfig['allow_http_localhost'] ?? false) === true;
if (!$isHttps && (!$isLoopback || !$allowHttpLocalhost)) {
    http_response_code(403);
    exit('HTTPS is required. For local development only, enable allow_http_localhost in the private config.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', '1800');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$loginError = null;
$authenticated = ($_SESSION['authenticated'] ?? false) === true;
if ($authenticated && time() - (int)($_SESSION['last_activity'] ?? 0) > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $authenticated = false;
    $loginError = 'Your session expired. Sign in again.';
} elseif ($authenticated) {
    $_SESSION['last_activity'] = time();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $startupError === null) {
    $providedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $providedToken)) {
        http_response_code(400);
        $flash = ['type' => 'error', 'text' => 'The form expired or its security token was invalid. Reload and try again.'];
    } elseif (($_POST['action'] ?? '') === 'login') {
        $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            $loginError = 'Too many failed attempts. Wait before trying again.';
        } elseif (webPasswordMatches($password, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['last_activity'] = time();
            $_SESSION['login_failures'] = 0;
            unset($_SESSION['login_locked_until']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            $_SESSION['login_failures'] = (int)($_SESSION['login_failures'] ?? 0) + 1;
            if ($_SESSION['login_failures'] >= 5) {
                $_SESSION['login_locked_until'] = time() + 900;
                $_SESSION['login_failures'] = 0;
            }
            $loginError = 'Password was not accepted.';
        }
    } elseif (!$authenticated) {
        http_response_code(401);
        $loginError = 'Sign in before using the cleanup page.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'));
        exit;
    } elseif (($_POST['action'] ?? '') === 'run') {
        try {
            $siteKey = (string)($_POST['site'] ?? '');
            $output = runCleanup($sites, $siteKey, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'text' => $output];
            header('Location: ?site=' . rawurlencode($siteKey));
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => $e->getMessage()];
        }
    } else {
        http_response_code(400);
        $flash = ['type' => 'error', 'text' => 'Unknown form action.'];
    }
}

$selectedSite = (string)($_GET['site'] ?? array_key_first($sites) ?? '');
$view = (string)($_GET['view'] ?? 'cleanup');
$audit = null;
$auditError = null;
if ($authenticated && $startupError === null && isset($sites[$selectedSite]) && $view === 'audit') {
    try {
        $siteConfig = loadSiteConfig($sites, $selectedSite);
        $path = auditPath($siteConfig);
        $runId = isset($_GET['run']) && ctype_digit((string)$_GET['run']) ? (int)$_GET['run'] : null;
        $audit = ['path' => $path] + readAudit($path, $runId);
    } catch (Throwable $e) {
        $auditError = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VirtueMart cleanup</title>
<style>
:root{color-scheme:light;--ink:#17202a;--muted:#566573;--line:#d5dce2;--blue:#145a86;--bg:#f3f6f8}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,Segoe UI,Arial,sans-serif}
main{max-width:1000px;margin:32px auto;padding:0 18px}h1{font-size:1.8rem;margin:0 0 8px}h2{font-size:1.25rem;margin:0 0 14px}
.subtle{color:var(--muted)}.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:22px;margin:18px 0;box-shadow:0 2px 8px #00000008}
label{display:block;font-weight:650;margin:14px 0 5px}input,select{font:inherit;padding:9px 10px;border:1px solid #9aa8b1;border-radius:6px;max-width:100%}
input[type=text],input[type=date],input[type=number],input[type=password],select{width:min(100%,560px)}
.row{display:flex;gap:18px;flex-wrap:wrap}.row>div{flex:1;min-width:220px}
button,.button{display:inline-block;border:0;border-radius:6px;background:var(--blue);color:white;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
button.secondary,.button.secondary{background:#536675}.danger{background:#9b2c2c}button:focus,input:focus,select:focus{outline:3px solid #8ec7e8;outline-offset:1px}
.notice{padding:12px 14px;border-radius:7px;margin:14px 0;white-space:pre-wrap;overflow-wrap:anywhere}.error{background:#fdeaea;border:1px solid #d98c8c}.success{background:#e9f5ed;border:1px solid #80b795}.info{background:#e8f3fa;border:1px solid #8db8d2}
.nav{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}.nav a{padding:8px 12px;border-radius:6px;background:#e2eaf0;color:#173b51;text-decoration:none}.nav a.active{background:var(--blue);color:#fff}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.85rem;font-weight:700}.good{background:#d9f0df;color:#205b31}.warn{background:#fff0c2;color:#664b00}.neutral{background:#e5e8eb;color:#37424b}
table{border-collapse:collapse;width:100%;font-size:.94rem}th,td{text-align:left;vertical-align:top;padding:9px;border-bottom:1px solid var(--line)}th{background:#f7f9fa}
code,pre{font-family:Consolas,monospace}code{overflow-wrap:anywhere}details pre{max-height:360px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#f5f7f8;padding:12px}
.right{margin-left:auto}.inline{display:inline}.small{font-size:.9rem}.check{display:flex;align-items:center;gap:8px}.check label{margin:0}
</style>
</head>
<body>
<main>
<h1>VirtueMart 4 cleanup</h1>
<p class="subtle">Protected administration page. Deletion is permanent; preview first and keep backups.</p>

<?php if ($startupError !== null): ?>
<div class="notice error"><?= h($startupError) ?></div>
<div class="notice info">Set the private config path near the top of this PHP file. Keep the config, site configs, and audit databases outside the web root. Use this page only over HTTPS.</div>
<?php elseif (!$authenticated): ?>
<?php if ($loginError !== null): ?><div class="notice error"><?= h($loginError) ?></div><?php endif; ?>
<section class="card">
<h2>Sign in</h2>
<form method="post" autocomplete="on">
<input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="action" value="login">
<label for="password">Cleanup password</label>
<input id="password" type="password" name="password" autocomplete="current-password" required autofocus>
<p><button type="submit">Sign in</button></p>
</form>
</section>
<?php else: ?>
<?php if (is_array($flash)): ?>
<div class="notice <?= h($flash['type'] === 'success' ? 'success' : 'error') ?>"><?= h((string)$flash['text']) ?></div>
<?php endif; ?>
<div class="nav">
<a class="<?= $view === 'cleanup' ? 'active' : '' ?>" href="?site=<?= rawurlencode($selectedSite) ?>">Choose cleanup</a>
<a class="<?= $view === 'audit' ? 'active' : '' ?>" href="?site=<?= rawurlencode($selectedSite) ?>&amp;view=audit">SQLite audit</a>
<form class="right" method="post">
<input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="action" value="logout">
<button class="secondary" type="submit">Sign out</button>
</form>
</div>

<?php if ($view === 'audit'): ?>
<section class="card">
<h2>SQLite audit database</h2>
<?php if ($auditError !== null): ?>
<div class="notice error"><?= h($auditError) ?></div>
<?php elseif (is_array($audit)): ?>
<p>Database file: <code><?= h($audit['path']) ?></code></p>
<?php if (!$audit['exists']): ?>
<div class="notice info">The SQLite file does not exist yet. Run a cleanup preview; the audit database is created automatically.</div>
<?php elseif ($audit['runs'] === []): ?>
<p>No cleanup runs are recorded in this database yet.</p>
<?php else: ?>
<h3>Recent runs</h3>
<div style="overflow-x:auto"><table>
<thead><tr><th>Run</th><th>Started (UTC)</th><th>Operation</th><th>Cutoff</th><th>Type</th><th>Site</th><th>Summary</th></tr></thead>
<tbody>
<?php foreach ($audit['runs'] as $run): ?>
<tr>
<td><a href="?site=<?= rawurlencode($selectedSite) ?>&amp;view=audit&amp;run=<?= (int)$run['run_id'] ?>">#<?= (int)$run['run_id'] ?></a></td>
<td><?= h((string)$run['started_at']) ?></td><td><?= h((string)$run['mode']) ?></td>
<td><?= h((string)($run['cutoff_date'] ?? '—')) ?></td>
<td><?= (int)$run['dry_run'] === 1 ? 'Dry run' : 'Executed' ?></td>
<td><?= h((string)$run['site_label']) ?></td>
<td><details><summary>View summary</summary><pre><?= h((string)($run['summary'] ?? '')) ?></pre></details></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($audit['selected_run'] !== null): ?>
<h3>Events for run #<?= (int)$audit['selected_run'] ?></h3>
<?php if ($audit['events'] === []): ?><p>This run has no event snapshots.</p><?php else: ?>
<div style="overflow-x:auto"><table>
<thead><tr><th>Type</th><th>Entity</th><th>Status</th><th>Snapshot</th></tr></thead>
<tbody>
<?php foreach ($audit['events'] as $event):
    $json = (string)$event['payload_json'];
    $truncated = strlen($json) > 10000;
    if ($truncated) {
        $json = substr($json, 0, 10000) . "\n… truncated for display";
    }
?>
<tr>
<td><?= h((string)$event['event_type']) ?></td>
<td><?= h((string)$event['entity_table']) ?> / <?= h((string)$event['entity_id']) ?></td>
<td><?= statusBadge((string)$event['status']) ?></td>
<td><details><summary>Show snapshot<?= $truncated ? ' (truncated)' : '' ?></summary><pre><?= h($json) ?></pre></details></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</section>

<?php else: ?>
<section class="card">
<h2>Select cleanup operation</h2>
<div class="notice info">Dry-run is selected by default. It records a preview in SQLite but does not delete anything. To execute, select Execute and type <strong>DELETE</strong>.</div>
<form method="post" id="cleanup-form">
<input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="action" value="run">
<div class="row">
<div>
<label for="site">Website</label>
<select id="site" name="site" required>
<?php foreach ($sites as $siteLabel => $_path): ?>
<option value="<?= h((string)$siteLabel) ?>" <?= (string)$siteLabel === $selectedSite ? 'selected' : '' ?>><?= h((string)$siteLabel) ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label for="mode">What to clean</label>
<select id="mode" name="mode" required>
<option value="orphan-images">Unreferenced product images</option>
<option value="old-products">Old products and their orphaned images</option>
<option value="all">Old products and all orphaned product images</option>
</select>
</div>
</div>
<div class="row">
<div>
<label for="cutoff">Delete products created before</label>
<input id="cutoff" name="cutoff" type="date">
<p class="subtle small">Required for the two old-product options. The cutoff date itself is not included.</p>
</div>
<div>
<label for="limit">Product candidate limit (optional)</label>
<input id="limit" name="limit" type="number" min="1" step="1" placeholder="No limit">
</div>
<div>
<label for="batch-size">Products per database batch</label>
<input id="batch-size" name="batch_size" type="number" min="1" step="1" value="100" required>
</div>
</div>
<label>Run type</label>
<div class="check"><input id="dry-run" type="radio" name="run_type" value="dry-run" checked><label for="dry-run">Preview only (recommended)</label></div>
<div class="check"><input id="execute" type="radio" name="run_type" value="execute"><label for="execute">Execute permanent deletions</label></div>
<div id="confirmation-wrap" hidden>
<label for="confirmation">Type DELETE to confirm</label>
<input id="confirmation" name="confirmation" type="text" autocomplete="off">
</div>
<p><button class="danger" type="submit">Run cleanup</button></p>
</form>
</section>
<section class="card">
<h2>What is protected</h2>
<ul>
<li>Any product referenced by an order item is retained.</li>
<li>A parent is retained if any child product would remain.</li>
<li>Order and user records, manufacturer definitions, and global custom-field definitions are not deleted.</li>
<li>Only product custom-field values and product-to-manufacturer links are removed with deleted products.</li>
</ul>
</section>
<?php endif; ?>
<?php endif; ?>
</main>
<script>
const executeOption = document.getElementById('execute');
const confirmationWrap = document.getElementById('confirmation-wrap');
const confirmation = document.getElementById('confirmation');
const mode = document.getElementById('mode');
const cutoff = document.getElementById('cutoff');
function updateFormRequirements() {
    if (confirmationWrap) {
        confirmationWrap.hidden = !executeOption.checked;
        confirmation.required = executeOption.checked;
    }
    if (mode && cutoff) {
        cutoff.required = mode.value !== 'orphan-images';
    }
}
if (executeOption) {
    executeOption.addEventListener('change', updateFormRequirements);
    document.getElementById('dry-run').addEventListener('change', updateFormRequirements);
}
if (mode) {
    mode.addEventListener('change', updateFormRequirements);
}
updateFormRequirements();
</script>
</body>
</html>
