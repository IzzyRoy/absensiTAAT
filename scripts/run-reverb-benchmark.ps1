$ErrorActionPreference = 'Stop'

$projectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$phpPath = (Get-Command php).Source
$nodePath = (Get-Command node).Source
$port = 19080
$tempId = [guid]::NewGuid().ToString('N')
$serverOut = Join-Path $env:TEMP "reverb-benchmark-$tempId.out.log"
$serverErr = Join-Path $env:TEMP "reverb-benchmark-$tempId.err.log"
$phpArgs = @('-d', 'extension=curl', 'artisan', 'reverb:start', "--host=127.0.0.1", "--port=$port")
$server = $null
$completed = $false

$random = New-Object byte[] 32
$generator = [Security.Cryptography.RandomNumberGenerator]::Create()
$generator.GetBytes($random)
$secret = [Convert]::ToBase64String($random)
$generator.Dispose()
$key = 'benchmark-' + [guid]::NewGuid().ToString('N')

$env:APP_ENV = 'local'
$env:APP_KEY = 'base64:' + $secret
$env:APP_URL = 'http://127.0.0.1'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DATABASE_URL = ''
$env:QUEUE_CONNECTION = 'sync'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'
$env:BROADCAST_CONNECTION = 'reverb'
$env:REVERB_APP_ID = 'benchmark'
$env:REVERB_APP_KEY = $key
$env:REVERB_APP_SECRET = $secret
$env:REVERB_HOST = '127.0.0.1'
$env:REVERB_PORT = [string]$port
$env:REVERB_SCHEME = 'http'
$env:REVERB_SERVER_HOST = '127.0.0.1'
$env:REVERB_SERVER_PORT = [string]$port
$env:REVERB_ALLOWED_ORIGINS = '*'
$env:BENCH_REVERB_APP_ID = $env:REVERB_APP_ID
$env:BENCH_REVERB_APP_KEY = $key
$env:BENCH_REVERB_APP_SECRET = $secret
$env:BENCH_REVERB_HOST = '127.0.0.1'
$env:BENCH_REVERB_PORT = [string]$port

try {
    $probe = New-Object System.Net.Sockets.TcpClient
    try {
        $probe.Connect('127.0.0.1', $port)
        throw "Port $port is already in use; benchmark was not started."
    } catch [System.Net.Sockets.SocketException] {
    } finally {
        $probe.Dispose()
    }

    $server = Start-Process -FilePath $phpPath `
        -ArgumentList $phpArgs `
        -WorkingDirectory $projectRoot `
        -RedirectStandardOutput $serverOut `
        -RedirectStandardError $serverErr `
        -PassThru

    $ready = $false
    for ($attempt = 0; $attempt -lt 50; $attempt++) {
        if ($server.HasExited) {
            throw "Reverb exited during startup. See $serverErr"
        }
        $probe = New-Object System.Net.Sockets.TcpClient
        try {
            $probe.Connect('127.0.0.1', $port)
            $ready = $true
            break
        } catch [System.Net.Sockets.SocketException] {
            Start-Sleep -Milliseconds 200
        } finally {
            $probe.Dispose()
        }
    }
    if (-not $ready) {
        throw "Reverb did not become ready. See $serverErr"
    }

    $results = @()
    foreach ($connectionCount in @(50, 100, 200)) {
        $stdout = Join-Path $env:TEMP "reverb-benchmark-$tempId-$connectionCount.json"
        $stderr = Join-Path $env:TEMP "reverb-benchmark-$tempId-$connectionCount.err"
        $node = Start-Process -FilePath $nodePath `
            -ArgumentList @('scripts/benchmark-reverb.mjs', '--connections', [string]$connectionCount) `
            -WorkingDirectory $projectRoot `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr `
            -PassThru

        $serverProcess = Get-Process -Id $server.Id
        $cpuStart = $serverProcess.TotalProcessorTime.TotalSeconds
        $clock = [Diagnostics.Stopwatch]::StartNew()
        $peakMemory = $serverProcess.WorkingSet64
        while (-not $node.HasExited) {
            try {
                $serverProcess = Get-Process -Id $server.Id
                $peakMemory = [Math]::Max($peakMemory, $serverProcess.WorkingSet64)
            } catch {
                throw 'Reverb stopped while the load test was running.'
            }
            Start-Sleep -Milliseconds 100
            $node.Refresh()
        }
        $clock.Stop()
        $node.WaitForExit()

        $result = Get-Content -LiteralPath $stdout -Raw | ConvertFrom-Json
        if (-not $result.stable) {
            Get-Content -LiteralPath $stderr
            throw "Benchmark did not remain stable at $connectionCount connections."
        }

        $serverProcess = Get-Process -Id $server.Id
        $cpuSeconds = $serverProcess.TotalProcessorTime.TotalSeconds - $cpuStart
        $cpuPercent = 100 * $cpuSeconds / ($clock.Elapsed.TotalSeconds * [Environment]::ProcessorCount)
        $result | Add-Member -NotePropertyName server_cpu_percent -NotePropertyValue ([Math]::Round($cpuPercent, 2))
        $result | Add-Member -NotePropertyName server_peak_ram_mb -NotePropertyValue ([Math]::Round($peakMemory / 1MB, 2))
        $results += $result
        Remove-Item -LiteralPath $stdout, $stderr -ErrorAction SilentlyContinue
    }

    $results | ConvertTo-Json -Depth 6
    $completed = $true
} finally {
    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id
        $server.WaitForExit()
    }
    if ($completed) {
        Remove-Item -LiteralPath $serverOut, $serverErr -ErrorAction SilentlyContinue
    } else {
        Write-Warning "Reverb logs retained at $serverOut and $serverErr."
    }
}
