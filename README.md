# Esila WebShell v2.5

**Single-file PHP web terminal for penetration testing and CTF competitions.**

Zero dependencies. Zero internet required. One `.php` file — drop it on any PHP server and go.

> **Warning:** Use only on systems you own or have explicit written permission to test. Unauthorized access is illegal.

---

## Features

### Web Terminal
- Interactive shell with command history (up/down arrows)
- Tab completion for files and directories
- Keyboard shortcuts: Ctrl+C (cancel), Ctrl+L (clear), Ctrl+D (exit)
- OS-aware quick-command buttons (Linux: `id`, `uname -a`, ports / Windows: `whoami`, `systeminfo`, `netstat`)
- ASCII art banner on connect

### User Switch (Privilege Escalation)
- **Linux:** sudo, su, sshpass — persistent session context across commands
- **Windows:** RunasCs with embedded binary — no upload needed
  - Auto-deploys RunasCs.exe from embedded base64
  - Falls back to in-memory .NET assembly loading via PowerShell if Defender deletes the exe
  - Uses `CreateProcessWithLogonW` API — works where `runas` and PowerShell credentials fail
  - `.bat` file fallback for commands exceeding 1024 char limit
- Prompt updates to show current user context
- `cd` validation runs as the switched user (not the web server user)

### Reverse Shell Generator
**6 categories, 60+ payloads:**

| Category | Payloads |
|----------|----------|
| **Reverse Shells** | Bash, sh, nc (multiple variants), ncat, PHP, Python, Python3, Perl, Ruby, socat, awk, lua, Golang, C, PowerShell (multiple), OpenSSL, Java, xterm |
| **Bind Shells** | nc, ncat, socat, Python, Perl, PHP, PowerShell |
| **MSFVenom** | windows/meterpreter (exe, dll, ps1, hta, asp, aspx, vbs), linux/meterpreter (elf, py), php/meterpreter, java/jsp, cmd/unix reverse |
| **Listeners** | nc, ncat, socat, rlwrap nc, msfconsole, pwncat |
| **TTY Upgrade** | python pty, script, socat, stty, expect, perl, ruby |
| **File Transfer** | python http.server, php -S, nc, curl, wget, scp, base64, certutil, PowerShell download, bitsadmin, Invoke-WebRequest, SMB share, impacket-smbserver |

### GTFOBins (Linux)
60+ binaries with exploit commands for:
- SUID abuse
- sudo bypass
- Capabilities exploitation
- Reverse shells
- File read / file write

Includes: aria2c, ash, awk, base64, bash, busybox, cat, chmod, cp, cpulimit, curl, cut, dash, dd, diff, docker, ed, emacs, env, expect, file, find, flock, gdb, git, grep, head, ionice, ip, jq, less, logsave, lua, make, man, mawk, more, mv, mysql, nano, nawk, nice, nmap, node, openssl, perl, php, python, ruby, rsync, rvim, scp, sed, socat, ssh, stdbuf, strace, tail, tar, taskset, tclsh, tee, time, timeout, unshare, vi, vim, watch, wget, wish, xargs, xxd, zip, zsh

### LOLBAS (Windows)
20+ Windows binaries with techniques for:
- Download & execute
- UAC bypass
- Privilege escalation
- Encode/decode
- File read / file write

Includes: bitsadmin, certutil, cmd, cmstp, csc, cscript, curl, desktopimgdownldr, esentutl, expand, extrac32, findstr, forfiles, hh, ieexec, makecab, mshta, msiexec, powershell, reg, regasm, regsvr32, replace, rundll32, wmic, wscript, xcopy

### File Manager
- Browse directories with file size, permissions, and modification dates
- Upload files (multipart form)
- Download files
- Create, rename, delete files and directories
- Read and edit files in a modal editor
- chmod support (Linux)

### Network Tools
- **Ping** — ICMP ping with count
- **Port Scan** — 23 common ports via fsockopen (21, 22, 23, 25, 53, 80, 110, 111, 135, 139, 143, 443, 445, 993, 995, 1723, 3306, 3389, 5900, 8080, 8443, 8888, 9090)
- **Traceroute** — trace route to host
- **DNS Lookup** — resolve hostnames
- **ARP Table** — display ARP cache
- **cURL Headers** — fetch HTTP headers from a URL

### System Information
- OS, kernel, hostname, architecture
- Current user, UID, groups
- IP addresses, network interfaces
- Uptime, disk usage
- Running processes
- Environment variables
- PHP version, disabled functions
- `/etc/passwd` dump (Linux) / scheduled tasks (Windows)
- Crontab entries (Linux)

### Privilege Escalation Helpers
**Linux:**
- Find SUID binaries
- Find binaries with capabilities
- Find writable cron jobs
- Detect Docker group membership
- Find SSH keys and interesting files (config files, history, databases)
- Find world-writable directories

**Windows:**
- Check current privileges (`whoami /priv`)
- Find unquoted service paths
- List scheduled tasks
- Dump saved credentials
- Extract Wi-Fi passwords
- Find writable directories in PATH

### Self-Destruct
- Timer-based auto-deletion (1–1440 minutes)
- Background process kills the PHP file after countdown
- Clean exit — no traces left

---

## Installation

1. Upload `Esila-WebShell.php` to any PHP-enabled web server
2. Open it in your browser
3. Login with default credentials:
   - **Username:** `shell`
   - **Password:** `shell`

**Requirements:** PHP 5.6+ with at least one exec function enabled (`shell_exec`, `exec`, `system`, `passthru`, `proc_open`, or `popen`)

---

## Technical Details

- **Single file** — everything is inline: HTML, CSS, JavaScript, PHP, embedded binaries
- **No external dependencies** — no CDN, no npm, no composer, no xterm.js
- **6 execution methods** — automatically finds a working exec function even when some are disabled
- **Session-based auth** — with cookie fallback for servers with broken session handling
- **Windows IIS compatible** — auto-fixes session save path issues
- **PHP 5.6+ compatible** — includes polyfills for `str_contains`, `str_starts_with`, `array_key_first`, `random_int`
- **`.htaccess` auto-fix** — endpoint to fix Apache module/CGI handler conflicts
- **Mobile responsive** — sidebar with hamburger menu, works on phone screens
- **Dark theme** — full dark UI with CSS custom properties

---

## Panels

| # | Panel | Description |
|---|-------|-------------|
| 1 | Terminal | Interactive shell with history, autocomplete, quick commands |
| 2 | Reverse Shell | 60+ payloads across 6 categories with IP/port autofill |
| 3 | GTFOBins / LOLBAS | Linux and Windows living-off-the-land binary reference |
| 4 | File Manager | Browse, upload, download, edit, delete, chmod |
| 5 | System Info | OS details, users, processes, network, PHP config |
| 6 | Network Tools | Ping, port scan, traceroute, DNS, ARP, curl |
| 7 | Settings | Self-destruct timer, user switch, credential management |

---

## Disclaimer

This tool is designed for authorized penetration testing, CTF competitions, and security research only. The author is not responsible for any misuse. Always obtain proper authorization before testing systems you do not own.
