<?php
session_start();

// Set your master password here:
$MASTER_PASSWORD = 'yourStrongMasterPasswordHere';

// Handle login form submission
if (isset($_POST['master_password'])) {
    if ($_POST['master_password'] === $MASTER_PASSWORD) {
        $_SESSION['authenticated'] = true;
    } else {
        $login_error = 'Invalid password.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Check authentication; if not authenticated, show login form and exit
if (empty($_SESSION['authenticated'])) {
    // Display login form and stop
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Login - SQL WebShell</title>
        <style>
            body {
                background: #111;
                color: #eee;
                font-family: monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            form {
                background: #1a1a1a;
                padding: 20px;
                border-radius: 10px;
                border: 1px solid #333;
                width: 300px;
                box-sizing: border-box;
            }
            input[type="password"] {
                width: 100%;
                padding: 10px;
                margin-bottom: 10px;
                background: #222;
                color: #0f0;
                border: 1px solid #444;
                font-family: monospace;
                box-sizing: border-box;
            }
            button {
                width: 100%;
                padding: 10px;
                background: #333;
                color: #0f0;
                border: none;
                cursor: pointer;
                font-family: monospace;
            }
            button:hover {
                background: #0f0;
                color: #000;
            }
            .error {
                color: orange;
                margin-bottom: 10px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <form method="POST" autocomplete="off">
            <h2 style="text-align:center;">Enter Master Password</h2>
            <?php if (!empty($login_error)) : ?>
                <div class="error"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <input type="password" name="master_password" placeholder="Master Password" required autofocus />
            <button type="submit">Login</button>
        </form>
    </body>
    </html>
    <?php
    exit;  // Stop here if not authenticated
}

function connect_db($ip, $port, $user, $pass, $db = null)
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli($ip, $user, $pass, $db, $port);
        $conn->set_charset('utf8mb4');
        return ['conn' => $conn];
    } catch (mysqli_sql_exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function get_databases($conn)
{
    try {
        $result = $conn->query('SHOW DATABASES');
        $dbs = [];
        while ($row = $result->fetch_assoc()) {
            $dbs[] = $row['Database'];
        }
        return $dbs;
    } catch (Exception $e) {
        return [];
    }
}

function get_tables($conn, $db)
{
    try {
        $conn->select_db($db);
        $result = $conn->query('SHOW TABLES');
        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        return $tables;
    } catch (Exception $e) {
        return [];
    }
}

function render_query_result($conn, $query)
{
    try {
        $result = $conn->query($query);

        if ($result === TRUE) {
            return 'Query OK';
        }

        if ($result === FALSE) {
            return 'Error: ' . $conn->error;
        }

        // Interactive UI for SELECT results
        $out = "<script>
            function toggleColumn(colIndex) {
                const cells = document.querySelectorAll('.col-' + colIndex);
                const btn = document.getElementById('btn-' + colIndex);
                cells.forEach(cell => {
                    cell.style.display = cell.style.display === 'none' ? '' : 'none';
                });
                btn.textContent = btn.textContent === '-' ? '+' : '-';
            }
            function showAllColumns() {
                const allButtons = document.querySelectorAll('th button[id^=\"btn-\"]');
                allButtons.forEach(btn => btn.textContent = '-');
                const allCells = document.querySelectorAll('td, th');
                allCells.forEach(cell => cell.style.display = '');
            }
        </script>";

        $out .= '<button type="button" class="btn" onclick="showAllColumns()" style="margin-bottom:10px;">Show All Columns</button>';
        $out .= '<table><tr>';
        $i = 0;
        while ($field = $result->fetch_field()) {
            $out .= "<th class='col-{$i}'><button type='button' class='btn' id='btn-{$i}' onclick='toggleColumn({$i})'>-</button> " . htmlspecialchars($field->name) . '</th>';
            $i++;
        }
        $out .= '</tr>';

        while ($row = $result->fetch_assoc()) {
            $out .= '<tr>';
            foreach (array_values($row) as $j => $cell) {
                $out .= "<td class='col-{$j}'>" . htmlspecialchars((string)$cell) . '</td>';
            }
            $out .= '</tr>';
        }

        $out .= '</table>';
        return $out;
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

// STATE
$ip = $_POST['ip'] ?? '127.0.0.1';
$port = intval($_POST['port'] ?? 3306);
$user = $_POST['user'] ?? '';
$pass = $_POST['pass'] ?? '';
$selected_db = $_POST['selected_db'] ?? '';
$selected_table = $_POST['selected_table'] ?? '';
$row_limit = $_POST['row_limit'] ?? '100';
$query = $_POST['query'] ?? '';
$action = $_POST['action'] ?? '';
$connection_error = '';
$databases = $tables = [];
$result_html = '';

$conn_result = connect_db($ip, $port, $user, $pass, $selected_db ?: null);
$conn = $conn_result['conn'] ?? null;

if ($conn) {
    $databases = get_databases($conn);

    if ($selected_db) {
        $tables = get_tables($conn, $selected_db);
    }

    if ($selected_table && $action !== 'run_query') {
        if (!$selected_db) {
            $result_html = '<p style="color:orange;">Please select a database first.</p>';
        } else {
            $conn->select_db($selected_db);
            $query = "SELECT * FROM `$selected_table` LIMIT $row_limit";
            $result_html = render_query_result($conn, $query);
        }
    }

    if ($action === 'run_query' && $query) {
        if (!$selected_db) {
            $result_html = '<p style="color:orange;">Please select a database first.</p>';
        } else {
            $conn->select_db($selected_db);
            $result_html = render_query_result($conn, $query);
        }
        
    }

    $conn->close();
} elseif (isset($conn_result['error'])) {
    $connection_error = 'Connection failed: ' . $conn_result['error'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>SQL WebShell</title>
    <style>
        body {
            margin: 0;
            background: #111;
            color: #eee;
            font-family: monospace;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto auto;
            gap: 10px;
            padding: 20px;
        }
        .card {
            background: #1a1a1a;
            padding: 15px;
            border: 1px solid #333;
            border-radius: 10px;
        }
        input, select, textarea, button {
            width: 100%;
            margin-bottom: 10px;
            background: #222;
            color: #0f0;
            border: 1px solid #444;
            padding: 8px;
            box-sizing: border-box;
        }
        button.btn {
            background: #333;
            color: #0f0;
            cursor: pointer;
        }
        button.btn:hover {
            background: #0f0;
            color: #000;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            border: 1px solid #666;
            padding: 5px;
        }
        .bottom {
            grid-column: span 2;
        }
        .error {
            color: red;
            margin-bottom: 10px;
            font-size: 0.9em;
        }

        #loading-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            color: #eee;
            user-select: none;
        }
        body.loading {
            pointer-events: none;
            user-select: none;
        }
    </style>
</head>
<body>
<div id="loading-overlay">Loading data...</div>
<form method="POST" onsubmit="document.body.classList.add('loading'); document.getElementById('loading-overlay').style.display = 'flex';">
    <div class="grid">
        <!-- Top Left -->
        <div class="card">
            <h3>Connection</h3>
            <?php if ($connection_error): ?>
                <div class="error"><?= htmlspecialchars($connection_error) ?></div>
            <?php endif; ?>
            <label>IP: <input type="text" name="ip" value="<?= htmlspecialchars($ip) ?>"></label>
            <label>Port: <input type="text" name="port" value="<?= htmlspecialchars($port) ?>"></label>
            <label>Username: <input type="text" name="user" value="<?= htmlspecialchars($user) ?>"></label>
            <label>Password: <input type="password" name="pass" value="<?= htmlspecialchars($pass) ?>"></label>
            <button class="btn" name="action" value="connect">Connect</button>
        </div>

        <!-- Top Right -->
        <div class="card">
            <h3>Explorer</h3>
            <?php if (!empty($databases)): ?>
                <label>Database:
                <span style="display: inline-flex; align-items: center;">
                    <select id="db-select" name="selected_db" onchange="submitFormWithLoading(this)" style="height: 35px; font-size: 14px;">
                    <option value="">Select DB</option>
                    <?php foreach ($databases as $db): ?>
                        <option value="<?= htmlspecialchars($db) ?>" <?= $selected_db === $db ? 'selected' : '' ?>>
                        <?= htmlspecialchars($db) ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                    <div style="display: flex; flex-direction: column; margin-left: 4px;">
                    <button type="button" onclick="moveSelectUp('db-select')" style="padding: 0 6px; font-size: 12px; line-height: 1; margin-bottom: 2px;">▲</button>
                    <button type="button" onclick="moveSelectDown('db-select')" style="padding: 0 6px; font-size: 12px; line-height: 1;">▼</button>
                    </div>
                </span>
                </label>
            <?php endif; ?>

            <?php if (!empty($tables)): ?>
                <label>Table:
                <span style="display: inline-flex; align-items: center;">
                    <select id="table-select" name="selected_table" onchange="submitFormWithLoading(this)" style="height: 35px; font-size: 14px;">
                    <option value="">Select Table</option>
                    <?php foreach ($tables as $tbl): ?>
                        <option value="<?= htmlspecialchars($tbl) ?>" <?= $selected_table === $tbl ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tbl) ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                    <div style="display: flex; flex-direction: column; margin-left: 4px;">
                    <button type="button" onclick="moveSelectUp('table-select')" style="padding: 0 6px; font-size: 12px; line-height: 1; margin-bottom: 2px;">▲</button>
                    <button type="button" onclick="moveSelectDown('table-select')" style="padding: 0 6px; font-size: 12px; line-height: 1;">▼</button>
                    </div>
                </span>
                </label>
                <label style="display:inline-block; margin-left: 10px;">
                    Limit rows: 
                    <input type="number" name="row_limit" min="1" max="10000000000000000000" value="<?= htmlspecialchars($row_limit ?? 100) ?>" style="width: 80px; background: #222; color: #0f0; border: 1px solid #444; padding: 5px;">
                </label>
            <?php endif; ?>

            <h4>SQL Query</h4>
            <?php if (!$selected_db): ?>
                <p style="color:#888;">Select a database to run queries.</p>
                <textarea name="query" rows="4" disabled style="opacity: 0.5;"></textarea>
                <button class="btn" name="action" value="run_query" disabled>Run Query</button>
            <?php else: ?>
                <p style="color:#0f0;">Send queries to <strong><?= htmlspecialchars($selected_db) ?></strong></p>
                <textarea name="query" rows="4"><?= htmlspecialchars($query) ?></textarea>
                <button class="btn" name="action" value="run_query">Run Query</button>
            <?php endif; ?>

        </div>

        <!-- Just results -->
        <div class="card bottom">
            <h3>Result</h3>
            <div><?= $result_html ?></div>
        </div>
    </div>
</form>

<script>
    window.addEventListener('load', () => {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.style.display = 'none';
        document.body.classList.remove('loading');
    }
    });

    // Optional: On page start, add loading class to block interaction immediately
    document.body.classList.add('loading');

    function moveSelectUp(selectId) {
        const select = document.getElementById(selectId);
        if (!select) return;
        if (select.selectedIndex > 0) {
            select.selectedIndex -= 1;
            select.dispatchEvent(new Event('change')); // optional: trigger onchange if you want auto-submit
        }
    }

    function moveSelectDown(selectId) {
        const select = document.getElementById(selectId);
        if (!select) return;
        if (select.selectedIndex < select.options.length - 1) {
            select.selectedIndex += 1;
            select.dispatchEvent(new Event('change')); // optional: trigger onchange if you want auto-submit
        }
    }

    function submitFormWithLoading(selectElement) {
        document.body.classList.add('loading');
        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.style.display = 'flex';
        // submit the closest form (assuming selects are inside the form)
        selectElement.form.submit();
    }
</script>
</body>
</html>
