param(
    [string]$PhpPath,
    [string]$TaskName = 'Report Tracking Email Outbox'
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$workerPath = Join-Path $projectRoot 'app\workers\send_email_outbox.php'

if (-not (Test-Path -LiteralPath $workerPath -PathType Leaf)) {
    throw "Email outbox worker not found: $workerPath"
}

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $phpCommand = Get-Command 'php.exe' -ErrorAction Stop
    $PhpPath = $phpCommand.Source
} elseif (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable not found: $PhpPath"
}

$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$workerPath = (Resolve-Path -LiteralPath $workerPath).Path
$action = New-ScheduledTaskAction `
    -Execute $PhpPath `
    -Argument ('"{0}"' -f $workerPath) `
    -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval ([TimeSpan]::FromMinutes(1))
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew

$username = "$env:USERDOMAIN\$env:USERNAME"
$credential = Get-Credential -UserName $username -Message 'Enter the Windows account credentials for the scheduled email worker.'
$password = $credential.GetNetworkCredential().Password

Register-ScheduledTask `
    -TaskName $TaskName `
    -Description 'Processes queued report-tracking emails every minute.' `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -User $credential.UserName `
    -Password $password `
    -Force | Out-Null

Write-Output "Registered '$TaskName' to process the email outbox every minute."
