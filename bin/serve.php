<?php
/**
 * RobinFlow - Local Development Server Launcher
 * 
 * Automatically verifies prerequisites, starts MySQL if needed,
 * checks Hermes AI agent, opens browser, and runs the PHP built-in web server.
 * 
 * Usage:
 *   php bin/serve.php
 *   php bin/serve.php --no-browser
 *   php bin/serve.php --port=8080
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run via CLI.\n";
    exit(1);
}

// Set terminal encoding for clean UTF-8 output
if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}

$rootDir = dirname(__DIR__);
chdir($rootDir);

require_once $rootDir . '/config.php';
require_once $rootDir . '/inc/hermes.php';

$options = getopt('', ['no-browser', 'port::', 'help']);
if (isset($options['help'])) {
    echo "RobinFlow Development Server Launcher\n";
    echo "Usage: php bin/serve.php [--port=8000] [--no-browser]\n";
    exit(0);
}

$port = isset($options['port']) ? (int)$options['port'] : 8000;
if ($port <= 0 || $port > 65535) {
    $port = 8000;
}
$autoOpenBrowser = !isset($options['no-browser']);

echo "\n";
echo "====================================================================\n";
echo "          ⚡ RobinFlow (رابین‌فلو) - Server Launcher ⚡          \n";
echo "   Intelligent Business Process Management & Continuous Improvement \n";
echo "====================================================================\n\n";

// 1. Check PHP Version & Extensions
echo "[1/4] Checking PHP environment...\n";
echo "      PHP Version: " . PHP_VERSION . " (" . (PHP_INT_SIZE * 8) . "-bit)\n";
$reqExts = ['mysqli', 'curl', 'mbstring', 'json'];
$missingExts = [];
foreach ($reqExts as $ext) {
    if (!extension_loaded($ext)) {
        $missingExts[] = $ext;
    }
}
if (!empty($missingExts)) {
    echo "      [ERROR] Missing required PHP extensions: " . implode(', ', $missingExts) . "\n";
    echo "      Please enable them in your php.ini.\n";
    exit(1);
}
echo "      All required PHP extensions loaded.\n\n";

// 2. Check & Start MySQL / MariaDB
echo "[2/4] Checking MySQL / MariaDB connection...\n";
$dbOk = false;
try {
    $db = app_db();
    if ($db->ping()) {
        $dbOk = true;
    }
} catch (Throwable $e) {
    $dbOk = false;
}

if (!$dbOk) {
    echo "      MySQL is not currently running. Attempting to start MySQL...\n";
    $mysqldPath = 'C:\\xampp\\mysql\\bin\\mysqld.exe';
    $myIniPath = 'C:\\xampp\\mysql\\bin\\my.ini';

    if (file_exists($mysqldPath)) {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $cmd = sprintf('start /B "" "%s" --defaults-file="%s" --standalone', $mysqldPath, $myIniPath);
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        }

        // Wait up to 6 seconds for MySQL to accept connections
        $attempts = 0;
        while ($attempts < 12) {
            usleep(500000); // 500ms
            $attempts++;
            try {
                // Reconnect with new instance
                $testConn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if ($testConn && !$testConn->connect_error) {
                    $testConn->close();
                    $dbOk = true;
                    break;
                }
            } catch (Throwable $ignore) {}
        }
    }

    if ($dbOk) {
        echo "      [SUCCESS] MySQL daemon started and connected.\n";
    } else {
        echo "      [WARNING] Could not automatically start MySQL.\n";
        echo "      Please ensure MySQL / XAMPP is started manually (Host: " . DB_HOST . ", Port: 3306).\n";
    }
} else {
    echo "      [SUCCESS] MySQL connection established (" . DB_HOST . ":" . DB_NAME . ").\n";
}
echo "\n";

// 3. Check Hermes AI Agent Status
echo "[3/4] Checking Hermes AI Agent (LLM Referee)...\n";
$hermesAvailable = hermes_is_available();
if ($hermesAvailable) {
    echo "      [SUCCESS] Hermes AI Agent is ONLINE at: " . HERMES_URL . "\n";
    echo "      Model: " . (defined('HERMES_MODEL') ? HERMES_MODEL : 'hermes-agent') . "\n";
} else {
    echo "      [NOTICE] Hermes AI Agent is currently OFFLINE at: " . HERMES_URL . "\n";
    echo "      The system will run normally; AI evaluations will be queued in the background.\n";
}
echo "\n";

// 4. Verify Port & Launch Server
echo "[4/4] Starting RobinFlow Web Application...\n";

// Check if port is already active
$fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
$alreadyRunning = false;
if ($fp) {
    fclose($fp);
    $alreadyRunning = true;
}

$hostUrl = "http://localhost:{$port}";
$userUrl = "{$hostUrl}/user/";
$teamUrl = "{$hostUrl}/team/";

echo "====================================================================\n";
echo "                    RobinFlow Testing Portals                       \n";
echo "====================================================================\n";
echo "  🌐 Main Landing:      {$hostUrl}/\n\n";
echo "  👤 User / Employee:   {$userUrl}\n";
echo "     - Personnel Code:  P-1001   (Maryam Rezaei)\n";
echo "     - Password:        123456\n\n";
echo "  👥 Team Console:      {$teamUrl}\n";
echo "     - Team Code:       TP-1000  (Administrative Process Team)\n";
echo "     - Password:        team123\n\n";
echo "  💬 Hermes AI Chat:    {$hostUrl}/ask.php\n";
echo "====================================================================\n";
echo "  Press Ctrl+C in this console at any time to stop the server.\n";
echo "====================================================================\n\n";

// Automatically open browser
if ($autoOpenBrowser) {
    echo "Opening RobinFlow in your default browser...\n";
    if (stripos(PHP_OS, 'WIN') === 0) {
        @pclose(@popen("start {$hostUrl}/", 'r'));
    } elseif (stripos(PHP_OS, 'DAR') === 0) {
        @exec("open {$hostUrl}/");
    } else {
        @exec("xdg-open {$hostUrl}/");
    }
}

if ($alreadyRunning) {
    echo "\n[INFO] RobinFlow is already running and serving requests on port {$port}.\n";
    echo "Keeping session alive. Press Ctrl+C to exit.\n";
    while (true) {
        sleep(2);
    }
    exit(0);
}

// Locate PHP binary for built-in web server
$phpBin = PHP_BINARY;
if (!$phpBin || !file_exists($phpBin)) {
    $phpBin = defined('PHP_BIN') && file_exists(PHP_BIN) ? PHP_BIN : 'php';
}

echo "Starting PHP built-in web server on 0.0.0.0:{$port} ...\n\n";
$cmd = sprintf('"%s" -S 0.0.0.0:%d -t "%s"', $phpBin, $port, $rootDir);
passthru($cmd);
