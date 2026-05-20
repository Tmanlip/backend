param(
  [Parameter(Mandatory = $true)]
  [string]$VmHost,

  [Parameter(Mandatory = $false)]
  [string]$VmUser = "azureuser",

  [Parameter(Mandatory = $false)]
  [string]$SshKeyPath = "",

  [Parameter(Mandatory = $false)]
  [string]$RemoteDir = "~/aslaw-ai-deploy",

  [Parameter(Mandatory = $false)]
  [string]$ModelList = "aslaw-civil aslaw-corporate aslaw-criminal aslaw-general llama3 qwen2.5:7b",

  [Parameter(Mandatory = $false)]
  [string]$AppEnvFile = "",

  [Parameter(Mandatory = $false)]
  [string]$DocModel = "llama3"
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$remoteScript = "$RemoteDir/provision_ai_vm.sh"

$sshParts = @("-o", "StrictHostKeyChecking=accept-new")
if ($SshKeyPath -ne "") {
  $sshParts += @("-i", $SshKeyPath)
}

Write-Host "[1/3] Uploading script to VM"
& ssh @sshParts "$VmUser@$VmHost" "mkdir -p $RemoteDir"
& scp @sshParts "$scriptDir/provision_ai_vm.sh" "$VmUser@$VmHost`:$remoteScript"

Write-Host "[2/3] Running provisioning on VM"
$remoteCmd = "chmod +x $remoteScript; MODEL_LIST='$ModelList' DOC_MODEL='$DocModel'"
if ($AppEnvFile -ne "") {
  $remoteCmd += " APP_ENV_FILE='$AppEnvFile'"
}
$remoteCmd += " sudo -E $remoteScript"

& ssh @sshParts "$VmUser@$VmHost" $remoteCmd

Write-Host "[3/3] Verifying models"
& ssh @sshParts "$VmUser@$VmHost" "ollama list"

Write-Host "Deployment complete."
