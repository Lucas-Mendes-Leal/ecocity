# EcoCity — agenda a geração semanal pela IA (RN91): segunda-feira às 6h.
# A IA lê as notícias ambientais da semana para até 3 quizzes e cria 2 missões semanais + 1 missão de comunidade
# como RASCUNHO (desativadas); o administrador revisa e ativa em Conteúdo. Log em %USERPROFILE%\EcoCity-Backups\quizzes-ia.log.
#
#   powershell -ExecutionPolicy Bypass -File docker\agendar-quizzes-ia.ps1            (cria ou atualiza a tarefa)
#   powershell -ExecutionPolicy Bypass -File docker\agendar-quizzes-ia.ps1 -Executar   (gera agora, como a tarefa faria)
param([switch]$Executar)

$log = Join-Path $env:USERPROFILE 'EcoCity-Backups\quizzes-ia.log'

if ($Executar) {
    New-Item -ItemType Directory -Force (Split-Path $log) | Out-Null
    $saidaQuizzes = docker exec ecocity-web-1 php /var/www/html/database/projeto/gerar-quizzes-ia.php 2>&1
    Add-Content -Path $log -Value $saidaQuizzes -Encoding utf8
    $codQ = $LASTEXITCODE
    $saidaMissoes = docker exec ecocity-web-1 php /var/www/html/database/projeto/gerar-missoes-ia.php 2>&1
    Add-Content -Path $log -Value $saidaMissoes -Encoding utf8
    $codM = $LASTEXITCODE
    if ($codQ -ne 0 -and $codM -ne 0) { exit $codQ }
    exit 0
}

$acao = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Executar"
$quando = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At 6am
$config = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName 'EcoCity - Quizzes IA' -Action $acao -Trigger $quando -Settings $config `
    -Description 'Gera quizzes (rascunho) com a IA a partir de notícias ambientais da semana.' -Force | Out-Null
Write-Output 'Tarefa "EcoCity - Quizzes IA" agendada: segundas às 6h.'
