# Offline tests for the session-relay client: no relay server needed. Two tiny fake relays (TcpListener
# in background jobs) record the requests, so team/relay selection and request bodies can be checked.
# Run from the repo root:
#   powershell -NoProfile -ExecutionPolicy Bypass -File tests/client-offline.ps1
#   pwsh -NoProfile -File tests/client-offline.ps1 -Shell pwsh
# Never touches the real ~/.claude: everything lives in a temp folder. ASCII only in this file.
param([string]$Shell = 'powershell.exe')
$ErrorActionPreference = 'Continue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$OutputEncoding = New-Object Text.UTF8Encoding($false)

$root   = Resolve-Path (Join-Path $PSScriptRoot '..')
$client = Join-Path $root 'plugin\client\session-relay.ps1'
$tmp    = Join-Path ([IO.Path]::GetTempPath()) ("session-relay-offline-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
$OnWindows = ($PSVersionTable.PSVersion.Major -lt 6) -or $IsWindows
$script:failures = 0
$script:passed = 0

function Expect([string]$What, $Ok, [string]$Out = '') {
    if ($Ok) { Write-Output "ok   $What"; $script:passed++ } else { Write-Output "FAIL $What`n$Out"; $script:failures++ }
}

$claudeDir = Join-Path $tmp 'home\.claude'
$stateDir  = Join-Path $tmp 'state'
$cfgPath   = Join-Path $tmp 'config.json'

function Client([string[]]$Arguments, [string]$Dir, [string]$Stdin = $null, [string]$Config = $cfgPath) {
    $env:SESSION_RELAY_CLAUDE_DIR = $claudeDir
    $env:SESSION_RELAY_DIR = $stateDir
    if ($Config) { $env:SESSION_RELAY_CONFIG = $Config } else { Remove-Item Env:SESSION_RELAY_CONFIG -ErrorAction SilentlyContinue }
    Remove-Item Env:SESSION_RELAY_NAME -ErrorAction SilentlyContinue
    Push-Location $Dir
    try {
        if ($Stdin) { $out = $Stdin | & $Shell -NoProfile -ExecutionPolicy Bypass -File $client @Arguments 2>&1 }
        else { $out = & $Shell -NoProfile -ExecutionPolicy Bypass -File $client @Arguments 2>&1 }
        return @{ code = $LASTEXITCODE; out = ((@($out) | ForEach-Object { "$_" }) -join "`n") }
    } finally { Pop-Location }
}

function HookIn([string]$Cwd, [string]$Sid, [string]$Cmd = '') {
    return (ConvertTo-Json -Compress -InputObject @{ session_id = $Sid; cwd = $Cwd; tool_name = 'Bash'; tool_input = @{ command = $Cmd } })
}

function New-Repo([string]$Dir, [string]$Branch) {
    New-Item -ItemType Directory -Force -Path $Dir | Out-Null
    & git -C $Dir init -q -b $Branch 2>$null
    & git -C $Dir config user.email offline@example.com
    & git -C $Dir config user.name offline
    Set-Content -Path (Join-Path $Dir 'base.txt') -Value 'a'
    & git -C $Dir add -A 2>$null
    & git -C $Dir commit -q -m init 2>$null
}

# ---------------------------------------------------------------- fake relay

$fakeRelay = {
    param($Port, $LogFile, $StopFile, $ReadyFile)
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Port)
    $listener.Start()
    [IO.File]::WriteAllText($ReadyFile, 'ready')
    $deadline = (Get-Date).AddMinutes(15)
    $enc = New-Object Text.UTF8Encoding($false)
    while ((Get-Date) -lt $deadline -and -not (Test-Path $StopFile)) {
        if (-not $listener.Pending()) { Start-Sleep -Milliseconds 15; continue }
        $c = $listener.AcceptTcpClient()
        try {
            $s = $c.GetStream(); $s.ReadTimeout = 5000
            $ms = New-Object IO.MemoryStream
            $buf = New-Object byte[] 8192
            $headerEnd = -1; $length = 0
            while ($true) {
                $n = $s.Read($buf, 0, $buf.Length)
                if ($n -le 0) { break }
                $ms.Write($buf, 0, $n)
                $all = $ms.ToArray()
                if ($headerEnd -lt 0) {
                    $text = [Text.Encoding]::ASCII.GetString($all)
                    $headerEnd = $text.IndexOf("`r`n`r`n")
                    if ($headerEnd -ge 0 -and $text.Substring(0, $headerEnd) -match '(?i)content-length:\s*(\d+)') { $length = [int]$Matches[1] }
                }
                if ($headerEnd -ge 0 -and $all.Length -ge $headerEnd + 4 + $length) { break }
            }
            $all = $ms.ToArray()
            $head = [Text.Encoding]::ASCII.GetString($all, 0, $headerEnd)
            $body = $enc.GetString($all, $headerEnd + 4, $all.Length - $headerEnd - 4)
            $first = ($head -split "`r`n")[0] -split ' '
            $method = $first[0]; $path = $first[1]
            [IO.File]::AppendAllText($LogFile, "$method $path $body`n", $enc)
            $status = 200; $json = '{}'
            if ($path -eq '/me') {
                if ($head -match 'Bearer one-team') { $json = '{"person":"alice","teams":["acme"]}' }
                else { $json = '{"person":"alice","teams":["acme","beta"]}' }
            }
            elseif ($method -eq 'POST' -and $path -eq '/session') {
                $b = $body | ConvertFrom-Json
                $team = if ($b.team) { $b.team } else { 'kept' }
                $json = (ConvertTo-Json -Compress -InputObject @{ session = @{ name = $b.name; team = $team } })
            }
            elseif ($method -eq 'DELETE') { $status = 204; $json = '' }
            elseif ($path -like '/board*') {
                $json = '{"sessions":[{"name":"carol-widget-1","person":"carol","machine":"m1","repo":"widget","repo_base":"widget","branch":"main","ticket":"BETA-4","team":"beta","claim":["src/a.c"]},{"name":"alice-hobby-1","person":"alice","machine":"m2","repo":"hobby","repo_base":"hobby","branch":"dev","ticket":null,"team":"private","claim":[]}]}'
            }
            elseif ($path -eq '/check') {
                if ($body -match 'conflict\.txt') { $json = '{"conflicts":[{"session":"bob-app-1","person":"bob","reason":"claims conflict.txt"}]}' }
                else { $json = '{"conflicts":[]}' }
            }
            elseif ($path -eq '/message') {
                if ($body -match '"to":"nobody"') { $status = 404; $json = '{"error":"No such session or person"}' }
                else { $status = 201; $json = '{"id":7}' }
            }
            elseif ($path -like '/inbox*') {
                if ($path -match 'session=alice-mail') {
                    $json = '{"messages":[{"id":5,"kind":"question","from":"bob-app-1","from_person":"bob","text":"done yet?","reply_to":null,"created_at":"2026-10-06T10:00:00+00:00"}]}'
                } else { $json = '{"messages":[]}' }
            }
            $reasons = @{ 200 = 'OK'; 201 = 'Created'; 204 = 'No Content'; 404 = 'Not Found' }
            $bytes = $enc.GetBytes($json)
            $resp = "HTTP/1.1 $status $($reasons[$status])`r`nContent-Type: application/json`r`nContent-Length: $($bytes.Length)`r`nConnection: close`r`n`r`n"
            $hb = [Text.Encoding]::ASCII.GetBytes($resp)
            $s.Write($hb, 0, $hb.Length)
            if ($bytes.Length -gt 0) { $s.Write($bytes, 0, $bytes.Length) }
            $s.Flush()
        } catch {} finally { $c.Close() }
    }
    $listener.Stop()
}

function Start-FakeRelay([string]$Name) {
    $port = Get-Random -Minimum 20000 -Maximum 40000
    $log = Join-Path $tmp "$Name.log"; [IO.File]::WriteAllText($log, '')
    $ready = Join-Path $tmp "$Name.ready"
    $job = Start-Job -ScriptBlock $fakeRelay -ArgumentList $port, $log, (Join-Path $tmp 'stop'), $ready
    $until = (Get-Date).AddSeconds(30)
    while (-not (Test-Path $ready) -and (Get-Date) -lt $until) { Start-Sleep -Milliseconds 200 }
    return @{ url = "http://127.0.0.1:$port"; log = $log; job = $job }
}

function Read-Log($Fake) { return [IO.File]::ReadAllText($Fake.log) }
function Clear-Log($Fake) { [IO.File]::WriteAllText($Fake.log, '') }

# ---------------------------------------------------------------- setup

New-Item -ItemType Directory -Force -Path $tmp, $claudeDir, $stateDir | Out-Null
$fakeA = Start-FakeRelay 'a'
$fakeB = Start-FakeRelay 'b'
$base = Join-Path $tmp 'projects'
New-Repo (Join-Path $base 'app') 'main'
New-Repo (Join-Path $base 'widget\fw') 'main'
New-Repo (Join-Path $base 'widget\fw-lab') 'main'      # sibling with a shared prefix: must not match "widget\fw"
New-Item -ItemType Directory -Force -Path (Join-Path $base 'hobby\notes') | Out-Null
$chats = Join-Path $tmp 'chats'; New-Item -ItemType Directory -Force -Path $chats | Out-Null
$envFile = Join-Path $tmp 'claude-env.sh'

try {
    # --- help and basics ---
    $r = Client @('help') $tmp
    Expect 'help: exit 0 and lists the commands' ($r.code -eq 0 -and $r.out -match 'relay add <name> <url> <token> <person>' -and $r.out -match 'folder <path> <relay> <team\|private>' -and $r.out -match 'migrate-old' -and $r.out -match 'register \[--team T\] \[--relay R\]' -and $r.out -match 'hook <start\|prompt\|pretool\|posttool\|end>') $r.out
    $r = Client @() $tmp
    Expect 'no arguments = help' ($r.code -eq 0 -and $r.out -match 'Setup') $r.out
    $r = Client @('board') $tmp
    Expect 'no config: board exits 1 with a hint' ($r.code -eq 1 -and $r.out -match 'relay add') $r.out
    $r = Client @('frobnicate') $tmp
    Expect 'unknown command: exit 1' ($r.code -eq 1) $r.out
    $r = Client @('relays') $tmp
    Expect 'relays without config: hint' ($r.code -eq 0 -and $r.out -match 'no relays') $r.out

    # --- relays ---
    $r = Client @('relay', 'add', 'work', $fakeA.url, 'secret-token-a', 'Alice') $tmp
    Expect 'relay add: saved and reachable, teams from /me' ($r.code -eq 0 -and $r.out -match 'relay work saved' -and $r.out -match 'teams: acme, beta') $r.out
    Expect 'relay add: never prints the token' ($r.out -notmatch 'secret-token-a') $r.out
    $r = Client @('relay', 'add', 'lab', $fakeB.url, 'secret-token-b', 'alice') $tmp
    $r = Client @('relays') $tmp
    Expect 'relays: both listed, person lowercased, no tokens' ($r.out -match 'work\s+http://127\.0\.0\.1:\d+\s+as alice' -and $r.out -match 'lab' -and $r.out -notmatch 'secret-token') $r.out
    $r = Client @('relay', 'add', 'bad', 'ftp://x', 't', 'p') $tmp
    Expect 'relay add: invalid url -> exit 1' ($r.code -eq 1) $r.out
    $r = Client @('relay', 'add', 'x') $tmp
    Expect 'relay add: missing arguments -> usage' ($r.code -eq 1 -and $r.out -match 'usage') $r.out
    $cfg = [IO.File]::ReadAllText($cfgPath) | ConvertFrom-Json
    Expect 'config format: relays.<name>.{url,token,person}' ($cfg.relays.work.token -eq 'secret-token-a' -and $cfg.relays.work.person -eq 'alice' -and $cfg.relays.lab.url -eq $fakeB.url) ([IO.File]::ReadAllText($cfgPath))

    # --- folders ---
    $r = Client @('folder', $base, 'work', 'acme') $tmp
    Expect 'folder: map a folder' ($r.code -eq 0 -and $r.out -match 'relay work, team acme') $r.out
    Client @('folder', (Join-Path $base 'widget\fw'), 'work', 'beta') $tmp | Out-Null
    Client @('folder', (Join-Path $base 'hobby'), 'work', 'private') $tmp | Out-Null
    $r = Client @('folder', (Join-Path $base 'x'), 'work', 'Not A Team') $tmp
    Expect 'folder: invalid team -> exit 1' ($r.code -eq 1 -and $r.out -match 'invalid team') $r.out
    $r = Client @('folder', (Join-Path $base 'x'), 'nope', 'beta') $tmp
    Expect 'folder: unknown relay -> exit 1' ($r.code -eq 1 -and $r.out -match 'unknown relay') $r.out
    $r = Client @('folder', 'relative-sub', 'work', 'beta') $base
    Expect 'folder: relative path is made absolute from the current folder' ($r.out -match [regex]::Escape(((Join-Path $base 'relative-sub') -replace '\\', '/'))) $r.out
    Client @('folder', 'remove', (Join-Path $base 'relative-sub')) $tmp | Out-Null
    $r = Client @('folders') $tmp
    Expect 'folders: three mappings, forward slashes, plus the note' (($r.out -split "`n" | Where-Object { $_ -match '->' }).Count -eq 3 -and $r.out -notmatch '\\' -and $r.out -match 'stay off every relay') $r.out
    $r = Client @('folder', 'remove', (Join-Path $tmp 'nowhere')) $tmp
    Expect 'folder remove: unknown folder -> exit 1' ($r.code -eq 1) $r.out
    if ($OnWindows) {
        $up = (Join-Path $base 'UPPER').ToUpper()
        $bash = '/' + $up.Substring(0, 1).ToLower() + ($up.Substring(2) -replace '\\', '/')
        $r = Client @('folder', $bash, 'lab', 'private') $tmp
        Expect 'folder: Git Bash /c/... path is stored as a drive path' ($r.out -match '[a-zA-Z]:/.*UPPER\s+->\s+relay lab, team private') $r.out
        $r = Client @('folder', 'remove', (Join-Path $base 'upper')) $tmp
        Expect 'folder remove: matches case-insensitively and across slash styles' ($r.out -notmatch 'UPPER') $r.out
    }

    # --- folder resolution in the hooks ---
    $sid1 = '11110000-0000-0000-0000-000000000000'
    Clear-Log $fakeA; Clear-Log $fakeB
    $env:CLAUDE_ENV_FILE = $envFile
    $r = Client @('hook', 'start') $tmp (HookIn (Join-Path $base 'widget\fw') $sid1)
    Remove-Item Env:CLAUDE_ENV_FILE
    $log = Read-Log $fakeA
    Expect 'hook start: longest prefix wins (widget/fw -> beta)' ($log -match 'POST /session .*"team":"beta"' -and $log -match '"name":"alice-fw-1111"') $log
    Expect 'hook start: context names the session, team and relay' ($r.out -match '"hookEventName":"SessionStart"' -and $r.out -match 'alice-fw-1111, team beta on relay work') $r.out
    Expect 'hook start: board shows only the session''s team' ($r.out -match '\[beta\] carol-widget-1' -and $r.out -notmatch 'alice-hobby-1') $r.out
    Expect 'hook start: listen instruction' ($r.out -match 'run_in_background' -and $r.out -match 'listen --session alice-fw-1111') $r.out
    Expect 'hook start: SESSION_RELAY_NAME in the env file' ((Get-Content $envFile -Raw) -match 'export SESSION_RELAY_NAME=alice-fw-1111') ''
    Expect 'hook start: no request to the other relay' ((Read-Log $fakeB).Trim() -eq '') (Read-Log $fakeB)

    Clear-Log $fakeA
    $r = Client @('hook', 'start') $tmp (HookIn (Join-Path $base 'widget\fw-lab') '22220000-0000-0000-0000-000000000000')
    Expect 'hook start: shared name prefix is not a folder match (fw-lab -> acme)' ((Read-Log $fakeA) -match '"team":"acme"') (Read-Log $fakeA)

    Clear-Log $fakeA
    $r = Client @('hook', 'start') $tmp (HookIn (Join-Path $base 'hobby\notes') '33330000-0000-0000-0000-000000000000')
    Expect 'hook start: private folder -> team private, also outside a repo' ((Read-Log $fakeA) -match '"team":"private"' -and (Read-Log $fakeA) -match '"repo_base":"-"' -and $r.out -match 'private \(only your own sessions see it\)') ($r.out + "`n" + (Read-Log $fakeA))

    if ($OnWindows) {
        Clear-Log $fakeA
        $odd = (Join-Path $base 'APP').ToUpper()
        Client @('hook', 'start') $tmp (HookIn $odd '44440000-0000-0000-0000-000000000000') | Out-Null
        Expect 'hook start: cwd matched case-insensitively' ((Read-Log $fakeA) -match '"team":"acme"') (Read-Log $fakeA)
        Clear-Log $fakeA
        $gb = '/' + $base.Substring(0, 1).ToLower() + ($base.Substring(2) -replace '\\', '/') + '/widget/fw'
        Client @('hook', 'start') $tmp (HookIn $gb '45450000-0000-0000-0000-000000000000') | Out-Null
        Expect 'hook start: Git Bash cwd /c/... matched' ((Read-Log $fakeA) -match '"team":"beta"') (Read-Log $fakeA)
    }

    # --- unmapped folder: silent, local name only ---
    $sidChat = '55550000-0000-0000-0000-000000000000'
    Clear-Log $fakeA; Clear-Log $fakeB
    $env:CLAUDE_ENV_FILE = $envFile
    $r = Client @('hook', 'start') $tmp (HookIn $chats $sidChat)
    Remove-Item Env:CLAUDE_ENV_FILE
    $nameFile = Join-Path $stateDir "sessions\$sidChat.txt"
    Expect 'unmapped folder: start hook is silent' ($r.code -eq 0 -and $r.out.Trim() -eq '') $r.out
    Expect 'unmapped folder: no request to any relay' ((Read-Log $fakeA).Trim() -eq '' -and (Read-Log $fakeB).Trim() -eq '') ((Read-Log $fakeA) + (Read-Log $fakeB))
    $chatName = if (Test-Path $nameFile) { ([IO.File]::ReadAllText($nameFile)).Trim() } else { '' }
    Expect 'unmapped folder: a local session name is stored' ($chatName -eq 'alice-chats-5555') $chatName
    Expect 'unmapped folder: name exported for Bash' ((Get-Content $envFile -Raw) -match 'SESSION_RELAY_NAME=alice-chats-5555') ''
    Client @('hook', 'prompt') $tmp (HookIn $chats $sidChat) | Out-Null
    Client @('hook', 'pretool') $tmp (HookIn $chats $sidChat 'git push') | Out-Null
    Expect 'unmapped folder: prompt and pretool hooks stay off the relay' ((Read-Log $fakeA).Trim() -eq '' -and (Read-Log $fakeB).Trim() -eq '') (Read-Log $fakeA)

    # --- register outside the mapped folders ---
    $r = Client @('register', '--session', $chatName) $chats
    Expect 'register: several relays and no folder -> --relay required' ($r.code -eq 1 -and $r.out -match '--relay') $r.out
    $r = Client @('register', '--session', $chatName, '--relay', 'lab') $chats
    Expect 'register: default team private' ($r.code -eq 0 -and $r.out -match 'alice-chats-5555 \[private\] on relay lab' -and (Read-Log $fakeB) -match '"team":"private"') ($r.out + (Read-Log $fakeB))
    Clear-Log $fakeB
    $env:SESSION_RELAY_HEARTBEAT_SEC = '0'
    $r = Client @('hook', 'prompt') $tmp (HookIn $chats $sidChat)
    Remove-Item Env:SESSION_RELAY_HEARTBEAT_SEC
    Expect 'register is remembered: the hooks keep the session alive on that relay' ((Read-Log $fakeB) -match 'POST /session .*"name":"alice-chats-5555".*' -and (Read-Log $fakeB) -match '"team":"private"' -and (Read-Log $fakeA).Trim() -eq '') (Read-Log $fakeB)
    $r = Client @('register', '--session', $chatName, '--relay', 'lab', '--team', 'Bad Team') $chats
    Expect 'register: invalid team -> exit 1' ($r.code -eq 1) $r.out
    Clear-Log $fakeB
    $r = Client @('register', '--session', $chatName, '--relay', 'lab', '--team', 'beta') $chats
    Expect 'register --team: explicit team is sent' ((Read-Log $fakeB) -match '"team":"beta"') (Read-Log $fakeB)

    Clear-Log $fakeB
    $r = Client @('unregister', '--session', $chatName) $chats
    Expect 'unregister: DELETE on the bound relay' ($r.code -eq 0 -and (Read-Log $fakeB) -match "DELETE /session/$chatName") ($r.out + (Read-Log $fakeB))
    Clear-Log $fakeA; Clear-Log $fakeB
    Client @('hook', 'prompt') $tmp (HookIn $chats $sidChat) | Out-Null
    Expect 'unregister sticks: hooks do not register the session again' ((Read-Log $fakeA).Trim() -eq '' -and (Read-Log $fakeB).Trim() -eq '') (Read-Log $fakeB)

    # --- register inside a mapped folder; ticket and auto-claim ---
    $repoT = Join-Path $base 'app'
    & git -C $repoT switch -q -c feature/ABC-12-thing 2>$null
    Set-Content -Path (Join-Path $repoT 'committed.txt') -Value 'c'
    & git -C $repoT add committed.txt 2>$null
    & git -C $repoT commit -q -m c 2>$null
    Set-Content -Path (Join-Path $repoT 'open.txt') -Value 'o'
    Clear-Log $fakeA
    $r = Client @('register', '--session', 'alice-app-x1', '--claim', 'docs/') $repoT
    $log = Read-Log $fakeA
    Expect 'register in a mapped folder: relay + team from the folder' ($r.code -eq 0 -and $log -match '"team":"acme"') ($r.out + $log)
    Expect 'register: ticket from the branch name' ($log -match '"ticket":"ABC-12"' -and $log -match '"branch":"feature/ABC-12-thing"') $log
    Expect 'register: claim = manual + committed on the branch + open work, not the base' ($log -match 'docs/' -and $log -match 'committed\.txt' -and $log -match 'open\.txt' -and $log -notmatch 'base\.txt') $log
    Clear-Log $fakeA
    Client @('claim', 'more/', '--session', 'alice-app-x1') $repoT | Out-Null
    $log = Read-Log $fakeA
    Expect 'claim: replaces the manual claim, keeps the team' ($log -match 'more/' -and $log -notmatch 'docs/' -and $log -match '"team":"acme"') $log
    $r = Client @('claim', 'x') $repoT
    Expect 'claim without a session -> exit 1' ($r.code -eq 1 -and $r.out -match '--session') $r.out

    # --- scope: a session never publishes another folder's repo state into its team ---
    # $sid1 started in widget\fw (team beta). Claude now works in the acme repo $repoT.
    $env:SESSION_RELAY_HEARTBEAT_SEC = '0'
    Clear-Log $fakeA
    Client @('hook', 'pretool') $tmp (HookIn $repoT $sid1 'ls') | Out-Null
    $log = Read-Log $fakeA
    Expect 'scope: heartbeat from another team''s folder resends the last state' ($log -match 'POST /session .*"name":"alice-fw-1111"' -and $log -match '"team":"beta"' -and $log -match '"repo":"fw"' -and $log -notmatch '"repo":"app"' -and $log -notmatch 'ABC-12' -and $log -notmatch 'committed\.txt' -and $log -notmatch 'open\.txt') $log
    Clear-Log $fakeA
    Client @('hook', 'pretool') $tmp (HookIn $chats $sid1 'ls') | Out-Null
    $log = Read-Log $fakeA
    Expect 'scope: heartbeat from an unmapped folder resends the last state' ($log -match '"repo":"fw"' -and $log -notmatch '"repo":"chats"') $log
    Clear-Log $fakeA
    $r = Client @('hook', 'pretool') $tmp (HookIn (Join-Path $base 'widget\fw') $sid1 "git -C `"$repoT`" add conflict.txt && git -C `"$repoT`" commit -m x")
    Expect 'scope: git -C into another team''s repo is not checked' ($r.out.Trim() -eq '' -and (Read-Log $fakeA) -notmatch 'POST /check') ($r.out + (Read-Log $fakeA))
    Clear-Log $fakeA
    Client @('hook', 'pretool') $tmp (HookIn $repoT $sid1 "git -C `"$(Join-Path $base 'widget\fw')`" push") | Out-Null
    Expect 'scope: git -C back into the own folder is checked' ((Read-Log $fakeA) -match 'POST /check .*"session":"alice-fw-1111"') (Read-Log $fakeA)
    Clear-Log $fakeA
    Client @('hook', 'pretool') $tmp (HookIn (Join-Path $base 'widget\fw') $sid1 'ls') | Out-Null
    Expect 'scope: back in the own folder the repo state comes from the cwd again' ((Read-Log $fakeA) -match '"repo":"fw"' -and (Read-Log $fakeA) -match '"branch":"main"') (Read-Log $fakeA)
    Remove-Item Env:SESSION_RELAY_HEARTBEAT_SEC

    # --- board ---
    $r = Client @('board') $tmp
    Expect 'board outside a session: all relays' ($r.out -match 'relay work:' -and $r.out -match 'relay lab:') $r.out
    Expect 'board marks the team' ($r.out -match '- \[beta\] carol-widget-1 \(carol, m1\): widget on main, BETA-4, claim: src/a\.c' -and $r.out -match '\[private\] alice-hobby-1') $r.out
    Clear-Log $fakeA; Clear-Log $fakeB
    $r = Client @('board', '--session', 'alice-app-x1', '--team', 'beta') $repoT
    Expect 'board in a session: only its relay, team filter passed on' ($r.out -notmatch 'relay lab:' -and (Read-Log $fakeA) -match 'GET /board\?team=beta' -and (Read-Log $fakeB).Trim() -eq '') ($r.out + (Read-Log $fakeA))
    $r = Client @('me') $tmp
    Expect 'me: person and teams per relay' ($r.out -match 'work: alice, teams: acme, beta' -and $r.out -match 'lab: alice') $r.out

    # --- messages ---
    Clear-Log $fakeA
    $r = Client @('send', 'bob', 'hello', 'there', '--session', 'alice-app-x1') $repoT
    Expect 'send: note with from/to/kind/text' ($r.out -match 'sent \(#7\)' -and (Read-Log $fakeA) -match 'POST /message' -and (Read-Log $fakeA) -match '"kind":"note"' -and (Read-Log $fakeA) -match '"from":"alice-app-x1"' -and (Read-Log $fakeA) -match '"to":"bob"' -and (Read-Log $fakeA) -match '"text":"hello there"') ($r.out + (Read-Log $fakeA))
    Clear-Log $fakeA
    Client @('ask', 'bob', 'ok?', '--session', 'alice-app-x1') $repoT | Out-Null
    Expect 'ask: kind question' ((Read-Log $fakeA) -match '"kind":"question"') (Read-Log $fakeA)
    Clear-Log $fakeA
    Client @('answer', '5', 'yes', '--session', 'alice-app-x1') $repoT | Out-Null
    Expect 'answer: kind answer with reply_to' ((Read-Log $fakeA) -match '"kind":"answer"' -and (Read-Log $fakeA) -match '"reply_to":5') (Read-Log $fakeA)
    $r = Client @('answer', 'x', 'yes', '--session', 'alice-app-x1') $repoT
    Expect 'answer: non-numeric id -> usage' ($r.code -eq 1) $r.out
    $r = Client @('send', 'nobody', 'x', '--session', 'alice-app-x1') $repoT
    Expect 'send: 404 -> exit 1 with the error text from the body' ($r.code -eq 1 -and $r.out -match 'No such session or person') $r.out
    $r = Client @('inbox', '--session', 'alice-mail') $repoT
    Expect 'inbox: messages formatted, reply hint for a question' ($r.out -match '\[question #5\] bob-app-1 \(bob\)' -and $r.out -match 'done yet\?' -and $r.out -match 'session-relay answer 5') $r.out
    $r = Client @('inbox', '--session', 'alice-app-x1') $repoT
    Expect 'inbox: empty' ($r.out -match 'no new messages') $r.out
    $r = Client @('inbox') $repoT
    Expect 'inbox without a session -> exit 1' ($r.code -eq 1 -and $r.out -match '--session') $r.out

    # --- check and the pretool hook ---
    $r = Client @('check') $repoT
    Expect 'check without a session -> exit 1' ($r.code -eq 1) $r.out
    Clear-Log $fakeA
    $r = Client @('check', '--paths', 'conflict.txt', '--session', 'alice-app-x1') $repoT
    Expect 'check: conflict -> exit 2' ($r.code -eq 2 -and $r.out -match 'CONFLICT: bob \(bob-app-1\) claims conflict\.txt' -and (Read-Log $fakeA) -match '"session":"alice-app-x1"' -and (Read-Log $fakeA) -match '"repo_base":"app"') ($r.out + (Read-Log $fakeA))
    $r = Client @('check', '--paths', 'free.txt', '--session', 'alice-app-x1') $repoT
    Expect 'check: free -> exit 0' ($r.code -eq 0) $r.out

    $sidT = '66660000-0000-0000-0000-000000000000'
    Client @('hook', 'start') $repoT (HookIn $repoT $sidT) | Out-Null
    Set-Content -Path (Join-Path $repoT 'conflict.txt') -Value 'x'
    $r = Client @('hook', 'pretool') $tmp (HookIn $tmp $sidT "git -C `"$repoT`" add conflict.txt && git -C `"$repoT`" commit -m x")
    Expect 'pretool: git -C <dir> add <path> && commit -> deny' ($r.out -match '"permissionDecision":"deny"' -and $r.out -match 'claims conflict\.txt') $r.out
    $r = Client @('hook', 'pretool') $tmp (HookIn $tmp $sidT "cd `"$repoT`" && git add conflict.txt && git commit -m x")
    Expect 'pretool: cd <dir> && git add && commit -> deny' ($r.out -match '"permissionDecision":"deny"') $r.out
    $r = Client @('hook', 'pretool') $tmp (HookIn $tmp $sidT "cd `"$repoT`" && git add conflict.txt && git commit -m x # session-relay:override")
    Expect 'pretool: override allows' ($r.out.Trim() -eq '') $r.out
    $r = Client @('hook', 'pretool') $repoT (HookIn $repoT $sidT 'git status')
    Expect 'pretool: other git command = nothing' ($r.out.Trim() -eq '') $r.out
    Clear-Log $fakeA
    $r = Client @('hook', 'pretool') $repoT (HookIn $repoT $sidT 'git push origin HEAD')
    Expect 'pretool: push sends the branch' ((Read-Log $fakeA) -match 'POST /check .*"branch":"feature/ABC-12-thing"') (Read-Log $fakeA)

    # --- heartbeat and inbox throttling ---
    $env:SESSION_RELAY_HEARTBEAT_SEC = '3600'
    Clear-Log $fakeA
    Client @('hook', 'pretool') $repoT (HookIn $repoT $sidT 'ls') | Out-Null
    Expect 'heartbeat: throttled within the interval' ((Read-Log $fakeA) -notmatch 'POST /session') (Read-Log $fakeA)
    $env:SESSION_RELAY_HEARTBEAT_SEC = '0'
    Client @('hook', 'pretool') $repoT (HookIn $repoT $sidT 'ls') | Out-Null
    Expect 'heartbeat: re-registers after the interval' ((Read-Log $fakeA) -match 'POST /session') (Read-Log $fakeA)
    Remove-Item Env:SESSION_RELAY_HEARTBEAT_SEC
    $env:SESSION_RELAY_INBOX_SEC = '3600'
    Clear-Log $fakeA
    Client @('hook', 'posttool') $repoT (HookIn $repoT $sidT) | Out-Null
    Client @('hook', 'posttool') $repoT (HookIn $repoT $sidT) | Out-Null
    Expect 'posttool: inbox checked once within the interval' (([regex]::Matches((Read-Log $fakeA), 'GET /inbox')).Count -eq 1) (Read-Log $fakeA)
    Remove-Item Env:SESSION_RELAY_INBOX_SEC

    Clear-Log $fakeA
    Client @('hook', 'end') $repoT (HookIn $repoT $sidT) | Out-Null
    Expect 'hook end: DELETE and local state removed' ((Read-Log $fakeA) -match 'DELETE /session/alice-app-6666' -and -not (Test-Path (Join-Path $stateDir "sessions\$sidT.txt")) -and -not (Test-Path (Join-Path $stateDir 'sessions\alice-app-6666.json'))) (Read-Log $fakeA)

    # --- relay down never blocks ---
    $deadCfg = Join-Path $tmp 'dead.json'
    [IO.File]::WriteAllText($deadCfg, '{"relays":{"x":{"url":"http://127.0.0.1:1","token":"t","person":"alice"}},"folders":{}}')
    $r = Client @('board') $tmp $null $deadCfg
    Expect 'relay down: board exit 0 with a warning' ($r.code -eq 0 -and $r.out -match 'unavailable') $r.out
    $r = Client @('hook', 'start') $tmp (HookIn $repoT '77770000-0000-0000-0000-000000000000') $deadCfg
    Expect 'relay down: hook exit 0, no output' ($r.code -eq 0 -and $r.out.Trim() -eq '') $r.out
    $badCfg = Join-Path $tmp 'bad.json'
    [IO.File]::WriteAllText($badCfg, '{"relays":{"x":{"url":"http://127.0.0.1:1"}}}')
    $r = Client @('board') $tmp $null $badCfg
    Expect 'broken config: clear error' ($r.code -eq 1 -and $r.out -match 'missing url, token or person') $r.out

    # --- relay remove unmaps its folders; configure shortcut; v1 config ---
    $r = Client @('relay', 'remove', 'lab') $tmp
    Expect 'relay remove' ($r.code -eq 0 -and $r.out -match 'relay lab removed') $r.out
    $shortCfg = Join-Path $tmp 'short.json'
    $r = Client @('configure', '--url', $fakeA.url, '--token', 'secret-c', '--name', 'alice') $tmp $null $shortCfg
    $c = [IO.File]::ReadAllText($shortCfg) | ConvertFrom-Json
    Expect 'configure = relay add default, nothing else' ($r.code -eq 0 -and $c.relays.default.url -eq $fakeA.url -and @($c.folders.PSObject.Properties).Count -eq 0 -and $r.out -notmatch 'secret-c') ($r.out + [IO.File]::ReadAllText($shortCfg))
    $v1Cfg = Join-Path $tmp 'v1.json'
    [IO.File]::WriteAllText($v1Cfg, '{"url":"http://127.0.0.1:1","token":"t","name":"Alice"}')
    $r = Client @('relays') $tmp $null $v1Cfg
    Expect 'plugin v1 config {url,token,name} reads as relay default' ($r.out -match 'default\s+http://127\.0\.0\.1:1\s+as alice') $r.out

    # --- import of the Dutch client ---
    $oldDir = Join-Path $tmp 'home-old\.claude'
    New-Item -ItemType Directory -Force -Path $oldDir | Out-Null
    $teamPath = (Join-Path $tmp 'oldprojects') -replace '\\', '\\'
    $privPath = (Join-Path $tmp 'oldhobby') -replace '\\', '\\'
    $listPath = (Join-Path $tmp 'oldlist') -replace '\\', '\\'
    [IO.File]::WriteAllText((Join-Path $oldDir 'sessie-relay.json'), "{`"url`":`"$($fakeA.url)`",`"token`":`"old-secret-token`",`"persoon`":`"Alice`",`"mappen`":[`"$listPath`"],`"ruimtes`":{`"$teamPath`":`"team`",`"$privPath`":`"prive`"}}")
    $settings = @'
{
  "model": "opus",
  "hooks": {
    "SessionStart": [
      { "hooks": [ { "type": "command", "command": "powershell.exe -File C:/x/.claude/sessie-relay/sessie.ps1 hook start", "timeout": 10 } ] },
      { "hooks": [ { "type": "command", "command": "echo keep-me" } ] },
      { "hooks": [ { "type": "command", "command": "node C:/tools/sessie-relay-notes/run.js" } ] }
    ],
    "PreToolUse": [
      { "matcher": "Bash|PowerShell", "hooks": [ { "type": "command", "command": "powershell.exe -File C:/x/.claude/sessie-relay/sessie.ps1 hook pretool" } ] }
    ]
  }
}
'@
    [IO.File]::WriteAllText((Join-Path $oldDir 'settings.json'), $settings)
    $saveClaude = $claudeDir; $claudeDir = $oldDir
    $r = Client @('migrate-old') $tmp $null $null
    $claudeDir = $saveClaude
    $newCfg = Join-Path $oldDir 'session-relay.json'
    Expect 'migrate-old: never prints the token' ($r.code -eq 0 -and $r.out -notmatch 'old-secret-token' -and $r.out -match 'imported') $r.out
    $n = if (Test-Path $newCfg) { [IO.File]::ReadAllText($newCfg) | ConvertFrom-Json } else { $null }
    Expect 'migrate-old: relay default from url/token/persoon' ($n -and $n.relays.default.url -eq $fakeA.url -and $n.relays.default.token -eq 'old-secret-token' -and $n.relays.default.person -eq 'alice') $r.out
    $f = @{}; if ($n) { foreach ($p in $n.folders.PSObject.Properties) { $f[(Split-Path -Leaf $p.Name)] = "$($p.Value.relay)/$($p.Value.team)" } }
    Expect 'migrate-old: several teams -> one line with the fix and the teams' ($r.out -match "team 'default' \(your teams: acme, beta\); fix with: session-relay folder <path> default <team>") $r.out
    Expect 'migrate-old: several teams -> team folders get team default, prive -> private' ($f['oldprojects'] -eq 'default/default' -and $f['oldhobby'] -eq 'default/private' -and $f['oldlist'] -eq 'default/default') (($f.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join ', ')
    $s = [IO.File]::ReadAllText((Join-Path $oldDir 'settings.json'))
    Expect 'migrate-old: old hooks removed, other hooks and settings kept' ($s -notmatch 'sessie\.ps1' -and $s -match 'keep-me' -and $s -match 'sessie-relay-notes' -and $s -match '"model"' -and $s -notmatch 'PreToolUse') $s
    $bak = @(Get-ChildItem -LiteralPath $oldDir -Filter 'settings.json.bak-*')
    Expect 'migrate-old: settings.json backed up first' ($bak.Count -eq 1 -and ([IO.File]::ReadAllText($bak[0].FullName)) -match 'sessie-relay') ''
    Expect 'migrate-old: report mentions the removed hooks' ($r.out -match 'removed 2 old sessie-relay hook') $r.out
    $claudeDir = $oldDir
    $r = Client @('migrate-old') $tmp $null $null
    $claudeDir = $saveClaude
    Expect 'migrate-old twice: does not overwrite the new config' ($r.out -match 'already exists') $r.out

    $oneDir = Join-Path $tmp 'home-one\.claude'
    New-Item -ItemType Directory -Force -Path $oneDir | Out-Null
    [IO.File]::WriteAllText((Join-Path $oneDir 'sessie-relay.json'), "{`"url`":`"$($fakeA.url)`",`"token`":`"one-team-secret`",`"persoon`":`"alice`",`"mappen`":[`"$listPath`"],`"ruimtes`":{`"$privPath`":`"prive`"}}")
    $claudeDir = $oneDir
    $r = Client @('migrate-old') $tmp $null $null
    $claudeDir = $saveClaude
    $n = [IO.File]::ReadAllText((Join-Path $oneDir 'session-relay.json')) | ConvertFrom-Json
    $f = @{}; foreach ($p in $n.folders.PSObject.Properties) { $f[(Split-Path -Leaf $p.Name)] = "$($p.Value.relay)/$($p.Value.team)" }
    Expect 'migrate-old: exactly one team -> team folders join it, no fix line' ($f['oldlist'] -eq 'default/acme' -and $f['oldhobby'] -eq 'default/private' -and $r.out -notmatch 'fix with' -and $r.out -notmatch 'one-team-secret') ($r.out + ' ' + (($f.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join ', '))

    $autoDir = Join-Path $tmp 'home-auto\.claude'
    New-Item -ItemType Directory -Force -Path $autoDir | Out-Null
    [IO.File]::WriteAllText((Join-Path $autoDir 'sessie-relay.json'), "{`"url`":`"http://127.0.0.1:1`",`"token`":`"auto-secret`",`"persoon`":`"alice`"}")
    $claudeDir = $autoDir
    $r = Client @('hook', 'prompt') $tmp (HookIn $chats '88880000-0000-0000-0000-000000000000') $null
    $claudeDir = $saveClaude
    Expect 'first hook run imports the old config' ($r.code -eq 0 -and (Test-Path (Join-Path $autoDir 'session-relay.json')) -and $r.out -notmatch 'auto-secret') $r.out
    $autoDir2 = Join-Path $tmp 'home-auto2\.claude'
    New-Item -ItemType Directory -Force -Path $autoDir2 | Out-Null
    [IO.File]::WriteAllText((Join-Path $autoDir2 'sessie-relay.json'), '{"url":"http://127.0.0.1:1","token":"t","persoon":"alice"}')
    $claudeDir = $autoDir2
    Client @('relays') $tmp $null (Join-Path $tmp 'override.json') | Out-Null
    $claudeDir = $saveClaude
    Expect 'no automatic import when SESSION_RELAY_CONFIG is set' (-not (Test-Path (Join-Path $autoDir2 'session-relay.json')) -and -not (Test-Path (Join-Path $tmp 'override.json'))) ''

    # --- bash wrapper keeps /-arguments intact (Git Bash) ---
    $gitBash = 'C:\Program Files\Git\bin\bash.exe'
    if ($OnWindows -and (Test-Path $gitBash)) {
        Clear-Log $fakeA
        $env:SESSION_RELAY_CONFIG = $cfgPath; $env:SESSION_RELAY_DIR = $stateDir; $env:SESSION_RELAY_CLAUDE_DIR = $claudeDir
        & $gitBash ((Join-Path $root 'plugin\client\session-relay').Replace('\', '/')) send bob '/session done' --session alice-app-x1 2>&1 | Out-Null
        Expect 'bash wrapper keeps /-arguments intact' ((Read-Log $fakeA) -match '"text":"/session done"') (Read-Log $fakeA)
    }
} finally {
    New-Item -ItemType File -Force -Path (Join-Path $tmp 'stop') | Out-Null
    foreach ($fake in @($fakeA, $fakeB)) { if ($fake.job) { Wait-Job $fake.job -Timeout 5 | Out-Null; Remove-Job $fake.job -Force } }
    foreach ($v in 'SESSION_RELAY_CONFIG', 'SESSION_RELAY_DIR', 'SESSION_RELAY_CLAUDE_DIR', 'SESSION_RELAY_NAME', 'CLAUDE_ENV_FILE') { Remove-Item "Env:$v" -ErrorAction SilentlyContinue }
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}

Write-Output "$($script:passed) passed, $($script:failures) failed"
if ($script:failures -gt 0) { exit 1 }
Write-Output 'ALL OK'
