<?php
/**
 * One-time setup for the triage question system.
 * Put this file in the same folder as controls.php, open it while logged in as superadmin,
 * press the button, then delete the file. Running it again is harmless (nothing is overwritten).
 */
require_once '../php/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../php/auth.php';
require_once __DIR__ . '/triage_helpers.php';

$auth = new Auth($conn);
if (!$auth->isSuperAdmin()) {
    header('Location: dashboard.php');
    exit();
}

if (empty($_SESSION['triage_mig_token'])) {
    $_SESSION['triage_mig_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['triage_mig_token'];
session_write_close();

$output = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid token.');
    }
    try {
        $seed = require __DIR__ . '/triage_seed_data.php';
        $output = implode("\n", runTriageMigration($conn, $seed));
    } catch (Throwable $e) {
        $output = 'FAILED: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Triage setup</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 16px; color: #1e293b; }
        button { padding: 12px 22px; border: 0; border-radius: 8px; background: #d32f2f; color: #fff; font-weight: 700; cursor: pointer; }
        pre { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 10px; overflow-x: auto; white-space: pre-wrap; }
    </style>
</head>
<body>
    <h2>Triage question setup</h2>
    <p>Creates the triage tables, copies your existing hazards into <code>emergency_incidents</code>,
       and loads the starter question library. Existing data is never overwritten.</p>
    <form method="post">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <button type="submit">Run setup</button>
    </form>
    <?php if ($output !== null): ?>
        <h3>Result</h3>
        <pre><?php echo htmlspecialchars($output); ?></pre>
        <p>When this looks right, delete <code>migrate_triage.php</code> from the server.</p>
    <?php endif; ?>
</body>
</html>
