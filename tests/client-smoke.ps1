# Smoke test for the session-relay client against a local relay (server/docker-compose.test.yml, service web).
# Run from the repo root:  powershell -NoProfile -ExecutionPolicy Bypass -File tests/client-smoke.ps1
#                          pwsh -NoProfile -File tests/client-smoke.ps1 -Shell pwsh
# Persons and teams get a fresh suffix per run, so the test can run repeatedly against the same database.
# Never touches the real ~/.claude. ASCII only in this file (Windows PowerShell 5.1).
param([string]$Shell = 'powershell.exe')
# Continue, not Stop: in PS 5.1 any stderr line of a native command (docker, git) throws under Stop.
# Every check below is explicit, so nothing is lost.
$ErrorActionPreference = 'Continue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$OutputEncoding = New-Object Text.UTF8Encoding($false)   # pipe UTF-8 into the hooks, like Claude Code does
$env:SESSION_RELAY_HEARTBEAT_SEC = '0'                    # no heartbeat throttling in tests
$env:SESSION_RELAY_INBOX_SEC = '0'                        # no inbox throttling in tests

$root    = Resolve-Path (Join-Path $PSScriptRoot '..')
$client  = Join-Path $root 'plugin\client\session-relay.ps1'
$composeFile = Join-Path $root 'server\docker-compose.test.yml'
$compose = @('compose', '-p', 'csr', '-f', $composeFile)
$tmp     = Join-Path ([IO.Path]::GetTempPath()) ("session-relay-smoke-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
$script:failures = 0

# Host port of the web service (whatever docker-compose.test.yml publishes for container port 8080).
$port = 8089
$m = [regex]::Match([IO.File]::ReadAllText($composeFile), '"(\d+):8080"')
if ($m.Success) { $port = [int]$m.Groups[1].Value }
$url = "http://localhost:$port"

function Expect([string]$What, $Ok, [string]$Out = '') {
    if ($Ok) { Write-Output "ok   $What" } else { Write-Output "FAIL $What`n$Out"; $script:failures++ }
}

function Client([string]$Cfg, [string[]]$Arguments, [string]$Dir, [string]$Stdin = $null) {
    $env:SESSION_RELAY_CONFIG = $Cfg
    $env:SESSION_RELAY_DIR = Join-Path $tmp 'state'
    $env:SESSION_RELAY_CLAUDE_DIR = Join-Path $tmp 'claude'
    Remove-Item Env:SESSION_RELAY_NAME -ErrorAction SilentlyContinue
    Push-Location $Dir
    try {
        $ErrorActionPreference = 'Continue'
        if ($Stdin) { $out = $Stdin | & $Shell -NoProfile -ExecutionPolicy Bypass -File $client @Arguments 2>&1 }
        else { $out = & $Shell -NoProfile -ExecutionPolicy Bypass -File $client @Arguments 2>&1 }
        return @{ code = $LASTEXITCODE; out = ((@($out) | ForEach-Object { "$_" }) -join "`n") }
    } finally { Pop-Location }
}

function Repo([string]$Dir, [string]$Branch) {
    New-Item -ItemType Directory -Force -Path $Dir | Out-Null
    & git -C $Dir init -q -b $Branch
    & git -C $Dir config user.email smoke@example.com
    & git -C $Dir config user.name smoke
    New-Item -ItemType Directory -Force -Path (Join-Path $Dir 'src\stores') | Out-Null
    Set-Content -Path (Join-Path $Dir 'src\stores\userStore.ts') -Value 'a'
    Set-Content -Path (Join-Path $Dir 'src\other.ts') -Value 'a'
    & git -C $Dir add -A
    & git -C $Dir commit -q -m init
}

# Config with one relay "local" and the given folder -> team mappings.
function Config([string]$Path, [string]$Url, [string]$Token, [string]$Person, [hashtable]$Folders = @{}) {
    $f = [ordered]@{}
    foreach ($k in $Folders.Keys) { $f[($k -replace '\\', '/')] = [ordered]@{ relay = 'local'; team = $Folders[$k] } }
    $json = ConvertTo-Json -Depth 5 -InputObject ([ordered]@{ relays = [ordered]@{ local = [ordered]@{ url = $Url; token = $Token; person = $Person } }; folders = $f })
    [IO.File]::WriteAllText($Path, $json, (New-Object Text.UTF8Encoding($false)))
}

function Relay-Cli([string[]]$CliArgs) { return (& docker @compose exec -T web php bin/relay @CliArgs 2>$null) }

function HookIn([string]$Cwd, [string]$Cmd = '', [string]$Sid = 'abcd1234-0000-0000-0000-000000000000') {
    return (ConvertTo-Json -Compress -InputObject @{ session_id = $Sid; cwd = $Cwd; tool_name = 'Bash'; tool_input = @{ command = $Cmd } })
}

# --- Setup: relay, three persons in two teams, repos ---
# A (teams "ta" and "tg"), B (ta only), C (tg only).
$id = -join ((97..122) | Get-Random -Count 4 | ForEach-Object { [char]$_ })
$pa = "sa$id"; $pb = "sb$id"; $pc = "sc$id"
$ta = "smoke-$id-a"; $tg = "smoke-$id-g"
& docker @compose up -d --wait db web | Out-Null
Relay-Cli @('migrate') | Out-Null
$tokA = ((Relay-Cli @('person:create', $pa)) | Select-Object -Last 1).Trim()
$tokB = ((Relay-Cli @('person:create', $pb)) | Select-Object -Last 1).Trim()
$tokC = ((Relay-Cli @('person:create', $pc)) | Select-Object -Last 1).Trim()
Relay-Cli @('team:create', $ta) | Out-Null
Relay-Cli @('team:create', $tg) | Out-Null
Relay-Cli @('team:add', $ta, $pa) | Out-Null
Relay-Cli @('team:add', $ta, $pb) | Out-Null
Relay-Cli @('team:add', $tg, $pa) | Out-Null
Relay-Cli @('team:add', $tg, $pc) | Out-Null

New-Item -ItemType Directory -Force -Path $tmp | Out-Null
$repoA  = Join-Path $tmp 'a\app'; Repo $repoA 'test'
$repoAg = Join-Path $tmp 'ag\app'; Repo $repoAg 'test'
$repoAp = Join-Path $tmp 'ap\app'; Repo $repoAp 'test'
$repoB  = Join-Path $tmp 'b\app'; Repo $repoB 'test'
$repoC  = Join-Path $tmp 'c\app'; Repo $repoC 'test'
$wtB    = Join-Path $tmp 'b\wt-x-app'
& git -C $repoB worktree add -q -b feature/x $wtB
$cfgA = Join-Path $tmp 'a.json'; Config $cfgA $url $tokA $pa @{ (Join-Path $tmp 'a') = $ta; (Join-Path $tmp 'ag') = $tg; (Join-Path $tmp 'ap') = 'private' }
$cfgB = Join-Path $tmp 'b.json'; Config $cfgB $url $tokB $pb @{ (Join-Path $tmp 'b') = $ta }
$cfgC = Join-Path $tmp 'c.json'; Config $cfgC $url $tokC $pc @{ (Join-Path $tmp 'c') = $tg }
$cfgDead = Join-Path $tmp 'dead.json'; Config $cfgDead 'http://localhost:1' $tokA $pa
$cfgBadToken = Join-Path $tmp 'badtoken.json'; Config $cfgBadToken $url 'nonsense' $pa

try {
    # --- me ---
    $r = Client $cfgA @('me') $repoA
    Expect 'me: own teams only' ($r.out -match "local: $pa, teams: " -and $r.out -match $ta -and $r.out -match $tg) $r.out
    $r = Client $cfgC @('me') $repoC
    Expect 'me: C sees only its own team' ($r.out -match $tg -and $r.out -notmatch $ta) $r.out

    # --- register (folder decides the team); a claim with exactly one path must survive as a list ---
    $r = Client $cfgA @('register', '--session', "$pa-0001", '--claim', 'src/stores/userStore.ts') $repoA
    Expect 'register A (team from the folder)' ($r.code -eq 0 -and $r.out -match "$pa-0001 \[$ta\]") $r.out
    $r = Client $cfgB @('register', '--session', "$pb-0001") $repoB
    Expect 'register B' ($r.code -eq 0 -and $r.out -match "\[$ta\]") $r.out
    $r = Client $cfgB @('register', '--session', "$pb-wt01") $wtB
    Expect 'register B in a worktree' ($r.code -eq 0) $r.out
    $r = Client $cfgC @('register', '--session', "$pc-0001", '--claim', 'src/stores/userStore.ts') $repoC
    Expect 'register C in the other team' ($r.code -eq 0 -and $r.out -match "\[$tg\]") $r.out
    $r = Client $cfgC @('register', '--session', "$pc-0002", '--team', $ta) $repoC
    Expect 'register C into a team it is not in -> exit 1' ($r.code -eq 1) $r.out
    $r = Client $cfgA @('register', '--session', "$pb-0009") $repoA
    Expect 'register a name with someone else''s prefix -> exit 1' ($r.code -eq 1) $r.out

    $r = Client $cfgB @('board') $repoB
    Expect 'board shows A with its claim and team' ($r.out -match "\[$ta\] $pa-0001" -and $r.out -match 'claim: src/stores/userStore.ts') $r.out
    Expect 'board shows the worktree branch' ($r.out -match "$pb-wt01.*wt-x-app on feature/x") $r.out
    Expect 'teams: B does not see C' ($r.out -notmatch $pc) $r.out
    $r = Client $cfgC @('board') $repoC
    Expect 'teams: C does not see A''s or B''s sessions' ($r.out -notmatch "$pa-0001" -and $r.out -notmatch $pb) $r.out

    # --- check ---
    $r = Client $cfgB @('check', '--session', "$pb-0001") $repoB
    Expect 'check: same branch conflicts (exit 2)' ($r.code -eq 2 -and $r.out -match "CONFLICT: $pa") $r.out
    $r = Client $cfgB @('check', '--session', "$pb-wt01", '--paths', 'src/stores/userStore.ts') $wtB
    Expect 'check: claimed path from a worktree conflicts' ($r.code -eq 2 -and $r.out -match 'claims src/stores/userStore.ts') $r.out
    $r = Client $cfgB @('check', '--session', "$pb-wt01", '--paths', 'src/other.ts') $wtB
    Expect 'check: free path in a worktree is fine' ($r.code -eq 0) $r.out
    $r = Client $cfgC @('check', '--session', "$pc-0001", '--paths', 'src/stores/userStore.ts') $repoC
    Expect 'teams: same repo, branch and path in another team -> no conflict' ($r.code -eq 0) $r.out

    # A also works in the other team: now C does conflict, with that session only.
    $r = Client $cfgA @('register', '--session', "$pa-g001") $repoAg
    Expect 'register A in its second team (folder)' ($r.code -eq 0 -and $r.out -match "\[$tg\]") $r.out
    $r = Client $cfgC @('check', '--session', "$pc-0001") $repoC
    Expect 'teams: conflict within the shared team only' ($r.code -eq 2 -and $r.out -match "$pa-g001" -and $r.out -notmatch "$pa-0001") $r.out

    # --- private ---
    $r = Client $cfgA @('register', '--session', "$pa-p001") $repoAp
    Expect 'register A private (folder)' ($r.code -eq 0 -and $r.out -match '\[private\]') $r.out
    $r = Client $cfgA @('board') $repoA
    Expect 'private: visible to its owner, marked [private]' ($r.out -match "\[private\] $pa-p001") $r.out
    $r = Client $cfgB @('board') $repoB
    Expect 'private: invisible to a teammate' ($r.out -notmatch "$pa-p001") $r.out
    $r = Client $cfgA @('check', '--session', "$pa-p001") $repoAp
    Expect 'private: check never conflicts' ($r.code -eq 0) $r.out
    $r = Client $cfgB @('send', "$pa-p001", 'x', '--session', "$pb-0001") $repoB
    Expect 'private: a teammate cannot message it' ($r.code -eq 1) $r.out

    # --- message with non-ASCII text (from a UTF-8 file, so this script stays ASCII) ---
    $textPath = Join-Path $tmp 'text.txt'
    [IO.File]::WriteAllText($textPath, ([string][char]0x00E9 + 'en vraag'), (New-Object Text.UTF8Encoding($false)))
    $one = [IO.File]::ReadAllText($textPath, [Text.Encoding]::UTF8)
    $r = Client $cfgB @('send', "$pa-0001", $one, '--session', "$pb-0001") $repoB
    Expect 'send' ($r.code -eq 0 -and $r.out -match 'sent') $r.out
    $r = Client $cfgA @('inbox', '--session', "$pa-0001") $repoA
    Expect 'inbox: non-ASCII intact' ($r.out.Contains($one)) $r.out

    # --- question and answer ---
    $r = Client $cfgB @('ask', "$pa-0001", 'done?', '--session', "$pb-0001") $repoB
    $questionId = [regex]::Match($r.out, '#(\d+)').Groups[1].Value
    Expect 'ask returns an id' ($questionId -ne '') $r.out
    $r = Client $cfgA @('inbox', '--session', "$pa-0001") $repoA
    Expect 'question arrives with a reply hint' ($r.out -match "\[question #$questionId\]" -and $r.out -match "session-relay answer $questionId") $r.out
    $r = Client $cfgA @('answer', $questionId, 'yes', 'indeed', '--session', "$pa-0001") $repoA
    Expect 'answer' ($r.code -eq 0) $r.out
    $r = Client $cfgB @('inbox', '--session', "$pb-0001") $repoB
    Expect 'answer arrives' ($r.out -match "answer to #$questionId" -and $r.out -match 'yes indeed') $r.out

    # --- to a person ---
    Client $cfgA @('send', $pb, 'to everyone', '--session', "$pa-0001") $repoA | Out-Null
    $r = Client $cfgB @('inbox', '--session', "$pb-wt01") $wtB
    Expect 'message to a person' ($r.out -match 'to everyone') $r.out

    # --- teams: messages across teams do not exist ---
    $r = Client $cfgC @('send', "$pa-0001", 'x', '--session', "$pc-0001") $repoC
    $unknown = Client $cfgC @('send', "$pc-nothere", 'x', '--session', "$pc-0001") $repoC
    Expect 'teams: C -> A''s session in another team = same error as a non-existent name' ($r.code -eq 1 -and (($r.out -replace [regex]::Escape("$pa-0001"), 'N') -eq ($unknown.out -replace [regex]::Escape("$pc-nothere"), 'N'))) "$($r.out)`n$($unknown.out)"
    $r = Client $cfgC @('send', $pb, 'x', '--session', "$pc-0001") $repoC
    Expect 'teams: C -> person B (not a teammate) -> exit 1' ($r.code -eq 1) $r.out
    Client $cfgA @('inbox', '--session', "$pa-0001") $repoA | Out-Null
    Client $cfgA @('inbox', '--session', "$pa-g001") $repoAg | Out-Null
    # A second session of A in the shared team (a person message never returns to the sending session):
    Client $cfgA @('register', '--session', "$pa-g002") $repoAg | Out-Null
    Client $cfgA @('send', $pa, 'note-in-g', '--session', "$pa-g001") $repoAg | Out-Null
    $r = Client $cfgA @('inbox', '--session', "$pa-0001") $repoA
    Expect 'teams: a person message from one team does not reach the other team''s sessions' ($r.out -notmatch 'note-in-g') $r.out
    $r = Client $cfgA @('inbox', '--session', "$pa-g002") $repoAg
    Expect 'teams: ... but it does reach the own team''s sessions' ($r.out -match 'note-in-g') $r.out
    $r = Client $cfgC @('inbox', '--session', "$pa-0001") $repoC
    Expect 'teams: C cannot read A''s inbox' ($r.code -eq 1) $r.out

    # --- listen wakes up within seconds ---
    Client $cfgB @('inbox', '--session', "$pb-0001") $repoB | Out-Null   # drain first
    $job = Start-Job -ScriptBlock {
        param($Shell, $client, $cfg, $state, $claude, $repo, $name)
        $env:SESSION_RELAY_CONFIG = $cfg; $env:SESSION_RELAY_DIR = $state; $env:SESSION_RELAY_CLAUDE_DIR = $claude
        Set-Location $repo
        & $Shell -NoProfile -ExecutionPolicy Bypass -File $client listen --session $name --max-minutes 1 2>&1
    } -ArgumentList $Shell, $client, $cfgB, (Join-Path $tmp 'state'), (Join-Path $tmp 'claude'), $repoB, "$pb-0001"
    Start-Sleep -Seconds 3
    $start = Get-Date
    Client $cfgA @('send', "$pb-0001", 'wake-up', '--session', "$pa-0001") $repoA | Out-Null
    Wait-Job $job -Timeout 20 | Out-Null
    $out = (Receive-Job $job) -join "`n"; Remove-Job $job -Force
    Expect 'listen wakes up with the message' ($out -match 'wake-up' -and ((Get-Date) - $start).TotalSeconds -lt 10) $out

    # --- failures never block ---
    $r = Client $cfgDead @('board') $repoA
    Expect 'relay down: exit 0 with a warning' ($r.code -eq 0 -and $r.out -match 'session-relay') $r.out
    $r = Client $cfgDead @('check', '--session', "$pa-0001") $repoA
    Expect 'relay down: check allows' ($r.code -eq 0) $r.out
    $r = Client $cfgBadToken @('board') $repoA
    Expect 'invalid token: exit 0 with a warning' ($r.code -eq 0 -and $r.out -match '401') $r.out
    $r = Client $cfgA @('send', 'nobody', 'x', '--session', "$pa-0001") $repoA
    Expect 'unknown recipient: exit 1 with the server error' ($r.code -eq 1 -and $r.out -match 'No such session or person') $r.out
    $r = Client $cfgA @('inbox') $repoA
    Expect 'without a session name: exit 1' ($r.code -eq 1 -and $r.out -match '--session') $r.out

    # --- unregister ---
    Client $cfgA @('unregister', '--session', "$pa-0001") $repoA | Out-Null
    $r = Client $cfgB @('board') $repoB
    Expect 'unregister removes A from the board' ($r.out -notmatch "$pa-0001") $r.out

    # --- hooks ---
    Client $cfgA @('register', '--session', "$pa-0001", '--claim', 'src/stores/userStore.ts') $repoA | Out-Null
    $repoO = Join-Path $tmp 'b\app-app'; Repo $repoO 'test'
    & git -C $repoO remote add origin git@github.com:example/app.git
    $r = Client $cfgB @('check', '--session', "$pb-0001") $repoO
    Expect 'repo identity from origin: different folder name still conflicts' ($r.code -eq 2) $r.out

    $envFile = Join-Path $tmp 'claude-env.sh'
    $env:CLAUDE_ENV_FILE = $envFile
    $r = Client $cfgB @('hook', 'start') $repoB (HookIn $repoB)
    Remove-Item Env:CLAUDE_ENV_FILE
    Expect 'hook start: context with session name, team and board' ($r.code -eq 0 -and $r.out -match '"hookEventName":"SessionStart"' -and $r.out -match "$pb-app-abcd" -and $r.out -match "team $ta" -and $r.out -match "$pa-0001") $r.out
    Expect 'hook start: board shows no other team' ($r.out -notmatch $pc) $r.out
    Expect 'hook start: instruction to start listen in the background' ($r.out -match 'run_in_background' -and $r.out -match "listen --session $pb-app-abcd") $r.out
    Expect 'hook start: SESSION_RELAY_NAME in the env file' ((Get-Content $envFile -Raw) -match "export SESSION_RELAY_NAME=$pb-app-abcd") ''

    Client $cfgA @('send', "$pb-app-abcd", 'hello via hook', '--session', "$pa-0001") $repoA | Out-Null
    $r = Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB)
    Expect 'hook prompt: new message as context' ($r.out -match '"hookEventName":"UserPromptSubmit"' -and $r.out -match 'hello via hook') $r.out
    $r = Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB)
    Expect 'hook prompt: nothing new = no output' ($r.code -eq 0 -and $r.out.Trim() -eq '') $r.out

    Client $cfgA @('send', "$pb-app-abcd", 'in between', '--session', "$pa-0001") $repoA | Out-Null
    $env:SESSION_RELAY_INBOX_SEC = '3600'
    Client $cfgB @('hook', 'posttool') $repoB (HookIn $repoB) | Out-Null
    $r = Client $cfgB @('hook', 'posttool') $repoB (HookIn $repoB)
    $env:SESSION_RELAY_INBOX_SEC = '0'
    Expect 'hook posttool: no inbox check within the interval' ($r.out.Trim() -eq '') $r.out
    Client $cfgA @('send', "$pb-app-abcd", 'one more', '--session', "$pa-0001") $repoA | Out-Null
    $r = Client $cfgB @('hook', 'posttool') $repoB (HookIn $repoB)
    Expect 'hook posttool: message between tool calls' ($r.out -match '"hookEventName":"PostToolUse"' -and $r.out -match 'one more') $r.out

    & docker @compose exec -T db psql -q -U relay -d relay_test -c "DELETE FROM session WHERE name = '$pb-app-abcd'" | Out-Null
    $r = Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB)
    $board = Client $cfgA @('board') $repoA
    Expect 'hook prompt: cleaned-up session registers again' ($board.out -match "$pb-app-abcd") $board.out

    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git push origin test')
    Expect 'hook pretool: push on someone else''s branch -> deny' ($r.out -match '"permissionDecision":"deny"' -and $r.out -match $pa) $r.out
    Set-Content -Path (Join-Path $repoB 'src\other.ts') -Value 'b'
    & git -C $repoB add src/other.ts
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git commit -m x')
    Expect 'hook pretool: commit on the same branch, free path -> allowed (only push blocks on branch)' ($r.out.Trim() -eq '') $r.out
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git commit -m x && git push')
    Expect 'hook pretool: commit && push in one command -> deny on the push' ($r.out -match '"permissionDecision":"deny"' -and $r.out -match 'is also on branch test') $r.out
    & git -C $repoB reset -q
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git push origin test # session-relay:override')
    Expect 'hook pretool: override allows' ($r.out.Trim() -eq '') $r.out
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git status')
    Expect 'hook pretool: other git command = nothing' ($r.out.Trim() -eq '') $r.out

    Set-Content -Path (Join-Path $wtB 'src\other.ts') -Value 'b'
    & git -C $wtB add src/other.ts
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "git -C `"$wtB`" commit -m x")
    Expect 'hook pretool: -C into a worktree, free path -> allowed' ($r.out.Trim() -eq '') $r.out
    Set-Content -Path (Join-Path $wtB 'src\stores\userStore.ts') -Value 'b'
    & git -C $wtB add src/stores/userStore.ts
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "git -C `"$wtB`" commit -m x")
    Expect 'hook pretool: -C into a worktree, claimed path -> deny' ($r.out -match '"permissionDecision":"deny"' -and $r.out -match 'claims src/stores/userStore.ts') $r.out
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "cd `"$wtB`" && git commit -m x")
    Expect 'hook pretool: cd into a worktree && commit -> deny' ($r.out -match '"permissionDecision":"deny"') $r.out

    $r = Client $cfgDead @('hook', 'pretool') $repoA (HookIn $repoA 'git push')
    Expect 'hook pretool: relay down = nothing, exit 0' ($r.code -eq 0 -and $r.out.Trim() -eq '') $r.out

    & git -C $wtB reset -q
    Set-Content -Path (Join-Path $wtB 'src\stores\userStore.ts') -Value 'c'
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "cd `"$wtB`" && git add src/stores/userStore.ts && git commit -m x")
    Expect 'hook pretool: git add <claimed> && commit in one command -> deny' ($r.out -match '"permissionDecision":"deny"') $r.out

    # The C session in the other team claims the same file and is on the same branch: never a reason to deny.
    $sidC = 'cccc0000-0000-0000-0000-000000000000'
    Client $cfgC @('hook', 'start') $repoC (HookIn $repoC '' $sidC) | Out-Null
    Set-Content -Path (Join-Path $repoC 'src\stores\userStore.ts') -Value 'c'
    $r = Client $cfgC @('hook', 'pretool') $repoC (HookIn $repoC 'git add src/stores/userStore.ts && git commit -m x && git push' $sidC)
    $denyText = $r.out
    Expect 'teams: pretool never mentions the other team''s sessions' ($denyText -notmatch $pb -and $denyText -notmatch "$pa-0001") $denyText
    Client $cfgC @('hook', 'end') $repoC (HookIn $repoC '' $sidC) | Out-Null

    & git -C $repoB switch -q -c feature/y
    Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'ls -la') | Out-Null
    $board = Client $cfgA @('board') $repoA
    Expect 'heartbeat on every tool call updates the branch' ($board.out -match "$pb-app-abcd.*on feature/y") $board.out
    & git -C $repoB switch -q -c feature/z
    Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB) | Out-Null
    $board = Client $cfgA @('board') $repoA
    Expect 'heartbeat on a prompt updates the branch' ($board.out -match "$pb-app-abcd.*on feature/z") $board.out
    & git -C $repoB switch -q test

    $noRepo = Join-Path $tmp 'b\workdir'; New-Item -ItemType Directory -Force -Path $noRepo | Out-Null
    $r = Client $cfgB @('hook', 'start') $noRepo (HookIn $noRepo '' 'eeee1111-0000-0000-0000-000000000000')
    Expect 'hook start in a mapped folder outside a repo: still registered' ($r.out -match "$pb-workdir-eeee") $r.out
    Client $cfgB @('hook', 'end') $noRepo (HookIn $noRepo '' 'eeee1111-0000-0000-0000-000000000000') | Out-Null

    # --- folder mapping: an unmapped folder stays off the relay until `register` ---
    $chats = Join-Path $tmp 'chats'; New-Item -ItemType Directory -Force -Path $chats | Out-Null
    $sidU = 'dddd0000-0000-0000-0000-000000000000'
    $r = Client $cfgB @('hook', 'start') $chats (HookIn $chats '' $sidU)
    $board = Client $cfgB @('board') $repoB
    Expect 'unmapped folder: start hook silent, not on the board' ($r.out.Trim() -eq '' -and $board.out -notmatch "$pb-chats-dddd") ($r.out + $board.out)
    $r = Client $cfgB @('register', '--session', "$pb-chats-dddd") $chats
    Expect 'unmapped folder: register puts it on the relay as private' ($r.code -eq 0 -and $r.out -match "$pb-chats-dddd \[private\]") $r.out
    $board = Client $cfgA @('board') $repoA
    Expect 'unmapped folder: the private session is invisible to a teammate' ($board.out -notmatch "$pb-chats-dddd") $board.out
    Client $cfgB @('hook', 'end') $chats (HookIn $chats '' $sidU) | Out-Null
    $board = Client $cfgB @('board') $repoB
    Expect 'unmapped folder: hook end takes the registered session off' ($board.out -notmatch "$pb-chats-dddd") $board.out

    $repoE = Join-Path $tmp ('b\e' + [string][char]0x00E9 + '\app'); Repo $repoE 'main'
    $r = Client $cfgB @('hook', 'start') $repoE (HookIn $repoE '' 'ffff2222-0000-0000-0000-000000000000')
    Expect 'hook start: non-ASCII in the path' ($r.out -match "$pb-app-ffff") $r.out
    Client $cfgB @('hook', 'end') $repoE (HookIn $repoE '' 'ffff2222-0000-0000-0000-000000000000') | Out-Null

    $gitBash = 'C:\Program Files\Git\bin\bash.exe'
    if (Test-Path $gitBash) {
        $env:SESSION_RELAY_CONFIG = $cfgB
        $env:SESSION_RELAY_DIR = Join-Path $tmp 'state'
        $env:SESSION_RELAY_CLAUDE_DIR = Join-Path $tmp 'claude'
        & $gitBash ((Join-Path $root 'plugin\client\session-relay').Replace('\', '/')) send "$pa-0001" '/session done' --session "$pb-0001" 2>&1 | Out-Null
        $r = Client $cfgA @('inbox', '--session', "$pa-0001") $repoA
        Expect 'bash wrapper keeps /-arguments intact' ($r.out -match '  /session done') $r.out
    }

    $start = Get-Date
    $r = Client $cfgBadToken @('listen', '--session', "$pa-0001", '--max-minutes', '1') $repoA
    Expect 'listen stops at once on an invalid token' (((Get-Date) - $start).TotalSeconds -lt 15 -and $r.code -eq 0) $r.out

    # --- auto-claim: ticket from the branch name, files changed on the branch + open work ---
    $wtT = Join-Path $tmp 'b\wt-syn77-app'
    & git -C $repoB worktree add -q -b feature/SYN-77-thing $wtT
    Set-Content -Path (Join-Path $wtT 'src\new.ts') -Value 'n'
    & git -C $wtT add src/new.ts
    & git -C $wtT -c user.email=s@e -c user.name=s commit -q -m new
    Set-Content -Path (Join-Path $wtT 'src\open.ts') -Value 'o'
    $sidT = '77770000-0000-0000-0000-000000000000'
    $r = Client $cfgB @('hook', 'start') $wtT (HookIn $wtT '' $sidT)
    $board = Client $cfgA @('board') $repoA
    Expect 'auto-claim: ticket from the branch name' ($board.out -match "$pb-wt-syn77-app-7777.*on feature/SYN-77-thing, SYN-77") $board.out
    Expect 'auto-claim: committed on the branch + uncommitted work' ($board.out -match "$pb-wt-syn77-app-7777.*src/new.ts" -and $board.out -match "$pb-wt-syn77-app-7777.*src/open.ts") $board.out
    Expect 'auto-claim: no files from the base' ($board.out -notmatch "$pb-wt-syn77-app-7777.*userStore") $board.out

    Client $cfgB @('claim', 'docs/', '--session', "$pb-wt-syn77-app-7777") $wtT | Out-Null
    Client $cfgB @('hook', 'pretool') $wtT (HookIn $wtT 'ls' $sidT) | Out-Null
    $board = Client $cfgA @('board') $repoA
    Expect 'manual claim stays next to the auto-claim' ($board.out -match "$pb-wt-syn77-app-7777.*docs/" -and $board.out -match "$pb-wt-syn77-app-7777.*src/new.ts") $board.out

    $sidA = 'aaaa0000-0000-0000-0000-000000000000'
    Client $cfgA @('hook', 'start') $repoA (HookIn $repoA '' $sidA) | Out-Null
    Set-Content -Path (Join-Path $repoA 'src\open.ts') -Value 'a'
    & git -C $repoA add src/open.ts
    $r = Client $cfgA @('hook', 'pretool') $repoA (HookIn $repoA 'git commit -m x' $sidA)
    Expect 'auto-claim protects: committing a file the other has open -> deny' ($r.out -match '"permissionDecision":"deny"' -and $r.out -match 'src/open.ts') $r.out
    & git -C $repoA reset -q
    Client $cfgA @('hook', 'end') $repoA (HookIn $repoA '' $sidA) | Out-Null
    Client $cfgB @('hook', 'end') $wtT (HookIn $wtT '' $sidT) | Out-Null

    $r = Client $cfgB @('hook', 'end') $repoB (HookIn $repoB)
    $board = Client $cfgA @('board') $repoA
    Expect 'hook end: off the board' ($board.out -notmatch "$pb-app-abcd") $board.out
} finally {
    foreach ($n in "$pa-0001", "$pa-g001", "$pa-p001", "$pb-0001", "$pb-wt01", "$pc-0001") {
        foreach ($c in @(@{ n = $pa; c = $cfgA }, @{ n = $pb; c = $cfgB }, @{ n = $pc; c = $cfgC })) {
            if ($n.StartsWith($c.n + '-')) { Client $c.c @('unregister', '--session', $n) $tmp | Out-Null }
        }
    }
    foreach ($v in 'SESSION_RELAY_CONFIG', 'SESSION_RELAY_DIR', 'SESSION_RELAY_CLAUDE_DIR', 'CLAUDE_ENV_FILE') { Remove-Item "Env:$v" -ErrorAction SilentlyContinue }
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}

if ($script:failures -gt 0) { Write-Output "$($script:failures) FAILURE(S)"; exit 1 }
Write-Output 'ALL OK'
