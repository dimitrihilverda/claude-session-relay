# Smoke test for the session-relay client against a local relay (server/docker-compose.test.yml, service web).
# Run from the repo root:  powershell -NoProfile -ExecutionPolicy Bypass -File tests/client-smoke.ps1
# ASCII only in this file (Windows PowerShell 5.1).
param([string]$Shell = 'powershell.exe')
# Continue, not Stop: in PS 5.1 any stderr line of a native command (docker, git) throws under Stop.
# Every check below is explicit, so nothing is lost.
$ErrorActionPreference = 'Continue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$OutputEncoding = New-Object Text.UTF8Encoding($false)   # pipe UTF-8 into the hooks, like Claude Code does
$env:SESSION_RELAY_HEARTBEAT_SEC = '0'                        # no heartbeat throttling in tests
$env:SESSION_RELAY_INBOX_SEC = '0'                           # no inbox throttling in tests

$root    = Resolve-Path (Join-Path $PSScriptRoot '..')
$client  = Join-Path $root 'plugin\client\session-relay.ps1'
$compose = @('compose', '-p', 'csr', '-f', (Join-Path $root 'server\docker-compose.test.yml'))
$tmp     = Join-Path ([IO.Path]::GetTempPath()) ("session-relay-smoke-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
$script:fouten = 0

function Expect([string]$Wat, $Ok, [string]$Uit = '') {
    if ($Ok) { Write-Output "ok   $Wat" } else { Write-Output "FAIL $Wat`n$Uit"; $script:fouten++ }
}

function Client([string]$Cfg, [string[]]$Argumenten, [string]$Map, [string]$Stdin = $null) {
    $env:SESSION_RELAY_CONFIG = $Cfg
    $env:SESSION_RELAY_DIR = Join-Path $tmp 'relaymap'
    Remove-Item Env:SESSION_RELAY_NAME -ErrorAction SilentlyContinue
    Push-Location $Map
    try {
        $ErrorActionPreference = 'Continue'
        if ($Stdin) { $uit = $Stdin | & $Shell -NoProfile -ExecutionPolicy Bypass -File $client @Argumenten 2>&1 }
        else { $uit = & $Shell -NoProfile -ExecutionPolicy Bypass -File $client @Argumenten 2>&1 }
        return @{ code = $LASTEXITCODE; uit = ((@($uit) | ForEach-Object { "$_" }) -join "`n") }
    } finally { Pop-Location }
}

function Repo([string]$Map, [string]$Branch) {
    New-Item -ItemType Directory -Force -Path $Map | Out-Null
    & git -C $Map init -q -b $Branch
    & git -C $Map config user.email smoke@example.com
    & git -C $Map config user.name smoke
    New-Item -ItemType Directory -Force -Path (Join-Path $Map 'src\stores') | Out-Null
    Set-Content -Path (Join-Path $Map 'src\stores\userStore.ts') -Value 'a'
    Set-Content -Path (Join-Path $Map 'src\other.ts') -Value 'a'
    & git -C $Map add -A
    & git -C $Map commit -q -m init
}

function Config([string]$Pad, [string]$Url, [string]$Token, [string]$Persoon) {
    $json = ConvertTo-Json -InputObject @{ url = $Url; token = $Token; name = $Persoon }
    [IO.File]::WriteAllText($Pad, $json, (New-Object Text.UTF8Encoding($false)))
}

# --- Setup: relay, two people, three repos ---
& docker @compose up -d --wait db web | Out-Null
& docker @compose exec -T web php bin/relay migreer | Out-Null
$tokA = ((& docker @compose exec -T web php bin/relay persoon:maak smokea 2>$null) | Select-Object -Last 1).Trim()
$tokB = ((& docker @compose exec -T web php bin/relay persoon:maak smokeb 2>$null) | Select-Object -Last 1).Trim()
& docker @compose exec -T db psql -q -U relay -d relay_test -c "DELETE FROM sessie WHERE naam LIKE 'smoke%'" | Out-Null

New-Item -ItemType Directory -Force -Path $tmp | Out-Null
$repoA = Join-Path $tmp 'a\app'; Repo $repoA 'test'
$repoB = Join-Path $tmp 'b\app'; Repo $repoB 'test'
$wtB   = Join-Path $tmp 'b\wt-x-app'
& git -C $repoB worktree add -q -b feature/x $wtB
$cfgA = Join-Path $tmp 'a.json'; Config $cfgA 'http://localhost:8089' $tokA 'smokea'
$cfgB = Join-Path $tmp 'b.json'; Config $cfgB 'http://localhost:8089' $tokB 'smokeb'
$cfgDood = Join-Path $tmp 'dood.json'; Config $cfgDood 'http://localhost:1' $tokA 'smokea'
$cfgFout = Join-Path $tmp 'fout.json'; Config $cfgFout 'http://localhost:8089' 'onzin' 'smokea'

try {
    # --- Register; a claim with exactly one path must survive as a list ---
    $r = Client $cfgA @('register', '--session', 'smokea-0001', '--claim', 'src/stores/userStore.ts') $repoA
    Expect 'register A' ($r.code -eq 0 -and $r.uit -match 'smokea-0001') $r.uit
    $r = Client $cfgB @('register', '--session', 'smokeb-0001') $repoB
    Expect 'register B' ($r.code -eq 0) $r.uit
    $r = Client $cfgB @('register', '--session', 'smokeb-wt01') $wtB
    Expect 'register B in a worktree' ($r.code -eq 0) $r.uit

    $r = Client $cfgB @('board') $repoB
    Expect 'board shows A with its claim' ($r.uit -match 'smokea-0001' -and $r.uit -match 'claim: src/stores/userStore.ts') $r.uit
    Expect 'board shows the worktree branch' ($r.uit -match 'smokeb-wt01.*wt-x-app on feature/x') $r.uit

    # --- check ---
    $r = Client $cfgB @('check') $repoB
    Expect 'check: same branch conflicts (exit 2)' ($r.code -eq 2 -and $r.uit -match 'CONFLICT: smokea') $r.uit
    $r = Client $cfgB @('check', '--paths', 'src/stores/userStore.ts') $wtB
    Expect 'check: claimed path from a worktree conflicts' ($r.code -eq 2 -and $r.uit -match 'claims src/stores/userStore.ts') $r.uit
    $r = Client $cfgB @('check', '--paths', 'src/other.ts') $wtB
    Expect 'check: free path in a worktree is fine' ($r.code -eq 0) $r.uit

    # --- message with non-ASCII text (from a UTF-8 file, so this script stays ASCII) ---
    $tekstPad = Join-Path $tmp 'tekst.txt'
    [IO.File]::WriteAllText($tekstPad, ([string][char]0x00E9 + 'en vraag'), (New-Object Text.UTF8Encoding($false)))
    $een = [IO.File]::ReadAllText($tekstPad, [Text.Encoding]::UTF8)
    $r = Client $cfgB @('send', 'smokea-0001', $een, '--session', 'smokeb-0001') $repoB
    Expect 'send' ($r.code -eq 0 -and $r.uit -match 'sent') $r.uit
    $r = Client $cfgA @('inbox', '--session', 'smokea-0001') $repoA
    Expect 'inbox: non-ASCII intact' ($r.uit.Contains($een)) $r.uit

    # --- question and answer ---
    $r = Client $cfgB @('ask', 'smokea-0001', 'klaar?', '--session', 'smokeb-0001') $repoB
    $vraagId = [regex]::Match($r.uit, '#(\d+)').Groups[1].Value
    Expect 'ask returns an id' ($vraagId -ne '') $r.uit
    $r = Client $cfgA @('inbox', '--session', 'smokea-0001') $repoA
    Expect 'question arrives with a reply hint' ($r.uit -match "\[question #$vraagId\]" -and $r.uit -match "session-relay answer $vraagId") $r.uit
    $r = Client $cfgA @('answer', $vraagId, 'ja', 'hoor', '--session', 'smokea-0001') $repoA
    Expect 'answer' ($r.code -eq 0) $r.uit
    $r = Client $cfgB @('inbox', '--session', 'smokeb-0001') $repoB
    Expect 'answer arrives' ($r.uit -match "answer to #$vraagId" -and $r.uit -match 'ja hoor') $r.uit

    # --- to a person ---
    $r = Client $cfgA @('send', 'smokeb', 'aan allen', '--session', 'smokea-0001') $repoA
    $r = Client $cfgB @('inbox', '--session', 'smokeb-wt01') $wtB
    Expect 'message to a person' ($r.uit -match 'aan allen') $r.uit

    # --- luister wakes up within seconds ---
    Client $cfgB @('inbox', '--session', 'smokeb-0001') $repoB | Out-Null   # drain the person-message first
    $job = Start-Job -ScriptBlock {
        param($Shell, $client, $cfg, $map, $repo)
        $env:SESSION_RELAY_CONFIG = $cfg; $env:SESSION_RELAY_DIR = $map
        Set-Location $repo
        & $Shell -NoProfile -ExecutionPolicy Bypass -File $client listen --session smokeb-0001 --max-minutes 1 2>&1
    } -ArgumentList $Shell, $client, $cfgB, (Join-Path $tmp 'relaymap'), $repoB
    Start-Sleep -Seconds 3
    $start = Get-Date
    Client $cfgA @('send', 'smokeb-0001', 'wakker', '--session', 'smokea-0001') $repoA | Out-Null
    Wait-Job $job -Timeout 20 | Out-Null
    $uit = (Receive-Job $job) -join "`n"; Remove-Job $job -Force
    Expect 'listen wakes up with the message' ($uit -match 'wakker' -and ((Get-Date) - $start).TotalSeconds -lt 10) $uit

    # --- failures never block ---
    $r = Client $cfgDood @('board') $repoA
    Expect 'relay down: exit 0 with a warning' ($r.code -eq 0 -and $r.uit -match 'session-relay') $r.uit
    $r = Client $cfgDood @('check') $repoA
    Expect 'relay down: check allows' ($r.code -eq 0) $r.uit
    $r = Client $cfgFout @('board') $repoA
    Expect 'invalid token: exit 0 with a warning' ($r.code -eq 0 -and $r.uit -match '401') $r.uit
    $r = Client $cfgA @('send', 'niemand', 'x', '--session', 'smokea-0001') $repoA
    Expect 'unknown recipient: exit 1' ($r.code -eq 1 -and $r.uit -match 'niemand') $r.uit
    $r = Client $cfgA @('inbox') $repoA
    Expect 'without a session name: exit 1' ($r.code -eq 1 -and $r.uit -match '--session') $r.uit

    # --- afmelden ---
    Client $cfgA @('unregister', '--session', 'smokea-0001') $repoA | Out-Null
    $r = Client $cfgB @('board') $repoB
    Expect 'unregister removes A from the board' ($r.uit -notmatch 'smokea-0001') $r.uit

    # --- hooks ---
    Client $cfgA @('register', '--session', 'smokea-0001', '--claim', 'src/stores/userStore.ts') $repoA | Out-Null
    function HookIn([string]$Cwd, [string]$Cmd = '', [string]$Sid = 'abcd1234-0000-0000-0000-000000000000') {
        return (ConvertTo-Json -Compress -InputObject @{ session_id = $Sid; cwd = $Cwd; tool_name = 'Bash'; tool_input = @{ command = $Cmd } })
    }
    $repoC = Join-Path $tmp 'c\app-app'; Repo $repoC 'test'
    & git -C $repoC remote add origin git@github.com:example/app.git
    $r = Client $cfgB @('check') $repoC
    Expect 'repo identity from origin: different folder name still conflicts' ($r.code -eq 2) $r.uit

    $envFile = Join-Path $tmp 'claude-env.sh'
    $env:CLAUDE_ENV_FILE = $envFile
    $r = Client $cfgB @('hook', 'start') $repoB (HookIn $repoB)
    Remove-Item Env:CLAUDE_ENV_FILE
    Expect 'hook start: context with session name and board' ($r.code -eq 0 -and $r.uit -match '"hookEventName":"SessionStart"' -and $r.uit -match 'smokeb-app-abcd' -and $r.uit -match 'smokea-0001') $r.uit
    Expect 'hook start: instruction to start listen in the background' ($r.uit -match 'run_in_background' -and $r.uit -match 'listen --session smokeb-app-abcd') $r.uit
    Expect 'hook start: SESSION_RELAY_NAME in the env file' ((Get-Content $envFile -Raw) -match 'export SESSION_RELAY_NAME=smokeb-app-abcd') ''

    Client $cfgA @('send', 'smokeb-app-abcd', 'hallo via hook', '--session', 'smokea-0001') $repoA | Out-Null
    $r = Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB)
    Expect 'hook prompt: new message as context' ($r.uit -match '"hookEventName":"UserPromptSubmit"' -and $r.uit -match 'hallo via hook') $r.uit
    $r = Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB)
    Expect 'hook prompt: nothing new = no output' ($r.code -eq 0 -and $r.uit.Trim() -eq '') $r.uit

    Client $cfgA @('send', 'smokeb-app-abcd', 'tussendoor', '--session', 'smokea-0001') $repoA | Out-Null
    $env:SESSION_RELAY_INBOX_SEC = '3600'
    Client $cfgB @('hook', 'posttool') $repoB (HookIn $repoB) | Out-Null
    $r = Client $cfgB @('hook', 'posttool') $repoB (HookIn $repoB)
    $env:SESSION_RELAY_INBOX_SEC = '0'
    Expect 'hook posttool: no inbox check within the interval' ($r.uit.Trim() -eq '') $r.uit
    Client $cfgA @('send', 'smokeb-app-abcd', 'nog een', '--session', 'smokea-0001') $repoA | Out-Null
    $r = Client $cfgB @('hook', 'posttool') $repoB (HookIn $repoB)
    Expect 'hook posttool: message between tool calls' ($r.uit -match '"hookEventName":"PostToolUse"' -and $r.uit -match 'nog een') $r.uit

    & docker @compose exec -T db psql -q -U relay -d relay_test -c "DELETE FROM sessie WHERE naam = 'smokeb-app-abcd'" | Out-Null
    $r = Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB)
    $bord = Client $cfgA @('board') $repoA
    Expect 'hook prompt: cleaned-up session registers again' ($bord.uit -match 'smokeb-app-abcd') $bord.uit

    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git push origin test')
    Expect 'hook pretool: push on someone else''s branch -> deny' ($r.uit -match '"permissionDecision":"deny"' -and $r.uit -match 'smokea') $r.uit
    Set-Content -Path (Join-Path $repoB 'src\other.ts') -Value 'b'
    & git -C $repoB add src/other.ts
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git commit -m x')
    Expect 'hook pretool: commit on the same branch, free path -> allowed (only push blocks on branch)' ($r.uit.Trim() -eq '') $r.uit
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git commit -m x && git push')
    Expect 'hook pretool: commit && push in one command -> deny on the push' ($r.uit -match '"permissionDecision":"deny"' -and $r.uit -match 'is also on branch test') $r.uit
    & git -C $repoB reset -q
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git push origin test # session-relay:override')
    Expect 'hook pretool: override allows' ($r.uit.Trim() -eq '') $r.uit
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'git status')
    Expect 'hook pretool: other git command = nothing' ($r.uit.Trim() -eq '') $r.uit

    Set-Content -Path (Join-Path $wtB 'src\other.ts') -Value 'b'
    & git -C $wtB add src/other.ts
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "git -C `"$wtB`" commit -m x")
    Expect 'hook pretool: -C into a worktree, free path -> allowed' ($r.uit.Trim() -eq '') $r.uit
    Set-Content -Path (Join-Path $wtB 'src\stores\userStore.ts') -Value 'b'
    & git -C $wtB add src/stores/userStore.ts
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "git -C `"$wtB`" commit -m x")
    Expect 'hook pretool: -C into a worktree, claimed path -> deny' ($r.uit -match '"permissionDecision":"deny"' -and $r.uit -match 'claims src/stores/userStore.ts') $r.uit
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "cd `"$wtB`" && git commit -m x")
    Expect 'hook pretool: cd into a worktree && commit -> deny' ($r.uit -match '"permissionDecision":"deny"') $r.uit

    $r = Client $cfgDood @('hook', 'pretool') $repoA (HookIn $repoA 'git push')
    Expect 'hook pretool: relay down = nothing, exit 0' ($r.code -eq 0 -and $r.uit.Trim() -eq '') $r.uit

    & git -C $wtB reset -q
    Set-Content -Path (Join-Path $wtB 'src\stores\userStore.ts') -Value 'c'
    $r = Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB "cd `"$wtB`" && git add src/stores/userStore.ts && git commit -m x")
    Expect 'hook pretool: git add <claimed> && commit in one command -> deny' ($r.uit -match '"permissionDecision":"deny"') $r.uit

    & git -C $repoB switch -q -c feature/y
    Client $cfgB @('hook', 'pretool') $repoB (HookIn $repoB 'ls -la') | Out-Null
    $bord = Client $cfgA @('board') $repoA
    Expect 'heartbeat on every tool call updates the branch' ($bord.uit -match 'smokeb-app-abcd.*on feature/y') $bord.uit
    & git -C $repoB switch -q -c feature/z
    Client $cfgB @('hook', 'prompt') $repoB (HookIn $repoB) | Out-Null
    $bord = Client $cfgA @('board') $repoA
    Expect 'heartbeat on a prompt updates the branch' ($bord.uit -match 'smokeb-app-abcd.*on feature/z') $bord.uit
    & git -C $repoB switch -q test

    $geenRepo = Join-Path $tmp 'werkmap'; New-Item -ItemType Directory -Force -Path $geenRepo | Out-Null
    $r = Client $cfgB @('hook', 'start') $geenRepo (HookIn $geenRepo '' 'eeee1111-0000-0000-0000-000000000000')
    Expect 'hook start outside a repo: still registered' ($r.uit -match 'smokeb-werkmap-eeee') $r.uit
    Client $cfgB @('hook', 'end') $geenRepo (HookIn $geenRepo '' 'eeee1111-0000-0000-0000-000000000000') | Out-Null

    $repoE = Join-Path $tmp ('e' + [string][char]0x00E9 + '\app'); Repo $repoE 'main'
    $r = Client $cfgB @('hook', 'start') $repoE (HookIn $repoE '' 'ffff2222-0000-0000-0000-000000000000')
    Expect 'hook start: non-ASCII in the path' ($r.uit -match 'smokeb-app-ffff') $r.uit
    Client $cfgB @('hook', 'end') $repoE (HookIn $repoE '' 'ffff2222-0000-0000-0000-000000000000') | Out-Null

    $gitBash = 'C:\Program Files\Git\bin\bash.exe'
    if (Test-Path $gitBash) {
        $env:SESSION_RELAY_CONFIG = $cfgB
        & $gitBash ((Join-Path $root 'plugin\client\session-relay').Replace('\', '/')) send smokea-0001 '/session done' --session smokeb-0001 2>&1 | Out-Null
        $r = Client $cfgA @('inbox', '--session', 'smokea-0001') $repoA
        Expect 'bash wrapper keeps /-arguments intact' ($r.uit -match '  /session done') $r.uit
    }

    $start = Get-Date
    $r = Client $cfgFout @('listen', '--session', 'smokea-0001', '--max-minutes', '1') $repoA
    Expect 'listen stops at once on an invalid token' (((Get-Date) - $start).TotalSeconds -lt 15 -and $r.code -eq 0) $r.uit

    # --- auto-claim: ticket from the branch name, files changed on the branch + open work ---
    $wtT = Join-Path $tmp 'b\wt-syn77-app'
    & git -C $repoB worktree add -q -b feature/SYN-77-iets $wtT
    Set-Content -Path (Join-Path $wtT 'src\nieuw.ts') -Value 'n'
    & git -C $wtT add src/nieuw.ts
    & git -C $wtT -c user.email=s@e -c user.name=s commit -q -m nieuw
    Set-Content -Path (Join-Path $wtT 'src\open.ts') -Value 'o'
    $sidT = '77770000-0000-0000-0000-000000000000'
    $r = Client $cfgB @('hook', 'start') $wtT (HookIn $wtT '' $sidT)
    $bord = Client $cfgA @('board') $repoA
    Expect 'auto-claim: ticket from the branch name' ($bord.uit -match 'smokeb-wt-syn77-app-7777.*on feature/SYN-77-iets, SYN-77') $bord.uit
    Expect 'auto-claim: committed on the branch + uncommitted work' ($bord.uit -match 'smokeb-wt-syn77-app-7777.*src/nieuw.ts' -and $bord.uit -match 'smokeb-wt-syn77-app-7777.*src/open.ts') $bord.uit
    Expect 'auto-claim: no files from the base' ($bord.uit -notmatch 'smokeb-wt-syn77-app-7777.*userStore') $bord.uit

    Client $cfgB @('claim', 'docs/', '--session', 'smokeb-wt-syn77-app-7777') $wtT | Out-Null
    Client $cfgB @('hook', 'pretool') $wtT (HookIn $wtT 'ls' $sidT) | Out-Null
    $bord = Client $cfgA @('board') $repoA
    Expect 'manual claim stays next to the auto-claim' ($bord.uit -match 'smokeb-wt-syn77-app-7777.*docs/' -and $bord.uit -match 'smokeb-wt-syn77-app-7777.*src/nieuw.ts') $bord.uit

    $r = Client $cfgA @('hook', 'pretool') $repoA (HookIn $repoA 'git commit -m x' 'aaaa0000-0000-0000-0000-000000000000')
    Set-Content -Path (Join-Path $repoA 'src\open.ts') -Value 'a'
    & git -C $repoA add src/open.ts
    $r = Client $cfgA @('hook', 'pretool') $repoA (HookIn $repoA 'git commit -m x' 'aaaa0000-0000-0000-0000-000000000000')
    Expect 'auto-claim protects: committing a file the other has open -> deny' ($r.uit -match '"permissionDecision":"deny"' -and $r.uit -match 'src/open.ts') $r.uit
    & git -C $repoA reset -q
    Client $cfgA @('hook', 'end') $repoA (HookIn $repoA '' 'aaaa0000-0000-0000-0000-000000000000') | Out-Null
    Client $cfgB @('hook', 'end') $wtT (HookIn $wtT '' $sidT) | Out-Null

    $r = Client $cfgB @('hook', 'end') $repoB (HookIn $repoB)
    $bord = Client $cfgA @('board') $repoA
    Expect 'hook end: off the board' ($bord.uit -notmatch 'smokeb-app-abcd') $bord.uit
} finally {
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}

if ($script:fouten -gt 0) { Write-Output "$($script:fouten) FAILURE(S)"; exit 1 }
Write-Output 'ALL OK'
