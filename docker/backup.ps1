<#
  EcoCity — backup do banco do site (Docker).

  Tipos:
    full         dump completo (mysqldump --single-transaction, com views, triggers, procedures e events)
                 + cópia das fotos de evidência; inicia um binlog novo (ponto de partida dos demais).
    diferencial  binlogs desde o último full (tudo o que mudou desde ele).
    incremental  binlogs desde o último backup de qualquer tipo (só o que mudou desde o anterior).

  Restauração: docker/restaurar.ps1 (full + binlogs até um horário escolhido).
  Retenção: 4 fulls; diferenciais e incrementais por 14 dias.
  Usa a conta ecocity_backup (perfil de backup, database/projeto/08-seguranca.sql); senha lida do docker/.env.

  Uso:  powershell -ExecutionPolicy Bypass -File docker\backup.ps1 -Tipo full [-Destino C:\EcoCity-Backups]
#>
param(
    [Parameter(Mandatory = $true)][ValidateSet('full', 'diferencial', 'incremental')][string]$Tipo,
    [string]$Destino = (Join-Path $env:USERPROFILE 'EcoCity-Backups')
)
$ErrorActionPreference = 'Stop'
$pasta = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $pasta

# Senha da conta de backup (nunca exibida)
$env_ = @{}
Get-Content (Join-Path $pasta '.env') | Where-Object { $_ -match '^\s*([A-Z_]+)=(.*)$' } | ForEach-Object { $env_[$Matches[1]] = $Matches[2].Trim() }
if (-not $env_['SENHA_BACKUP']) { throw 'SENHA_BACKUP não encontrada no docker/.env' }
$senha = $env_['SENHA_BACKUP']

$agora = Get-Date -Format 'yyyy-MM-dd_HHmmss'
New-Item -ItemType Directory -Force -Path $Destino, (Join-Path $Destino 'full'), (Join-Path $Destino 'binlog') | Out-Null
$estadoArq = Join-Path $Destino 'estado.json'
$estado = if (Test-Path $estadoArq) { Get-Content $estadoArq -Raw | ConvertFrom-Json } else { $null }
$log = Join-Path $Destino 'backup.log'
function Registrar([string]$texto) { $linha = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') [$Tipo] $texto"; Add-Content -Path $log -Value $linha -Encoding utf8; Write-Output $linha }

function Mysql([string]$sql) {
    $saida = docker compose exec -T -e "MYSQL_PWD=$senha" db mysql -uecocity_backup -N -e $sql 2>$null
    if ($LASTEXITCODE -ne 0) { throw "mysql falhou: $sql" }
    return $saida
}
function Binlogs { return @(Mysql 'SHOW BINARY LOGS' | ForEach-Object { ($_ -split "`t")[0] }) }
function CopiarBinlogs([string]$aPartirDe, [string]$subpasta) {
    # Fecha o binlog atual para que os anteriores fiquem completos e copia os do intervalo
    docker compose exec -T -e "MYSQL_PWD=$senha" db mysqladmin -uecocity_backup flush-logs 2>$null | Out-Null
    $todos = Binlogs
    $atual = $todos[-1]
    $copiar = $todos | Where-Object { $_ -ge $aPartirDe -and $_ -ne $atual }
    $dir = Join-Path (Join-Path $Destino 'binlog') $subpasta
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    foreach ($b in $copiar) { docker compose cp "db:/var/lib/mysql/$b" (Join-Path $dir $b) | Out-Null }
    return @{ copiados = @($copiar); proximo = $atual; pasta = $dir }
}

try {
    if ($Tipo -eq 'full') {
        $arq = Join-Path (Join-Path $Destino 'full') "ecocity-full-$agora.sql"
        # --flush-logs abre um binlog novo; --source-data=2 anota no dump a posição de início
        # O dump é gravado dentro do container e copiado em binário (o pipe do PowerShell 5.1 estragaria os acentos)
        docker compose exec -T -e "MYSQL_PWD=$senha" db sh -c 'mysqldump -uecocity_backup --single-transaction --routines --triggers --events --flush-logs --source-data=2 --set-gtid-purged=OFF --default-character-set=utf8mb4 ecocity > /tmp/ecocity-full.sql 2>/tmp/ecocity-full.err' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "mysqldump falhou: $(docker compose exec -T db cat /tmp/ecocity-full.err)" }
        docker compose cp db:/tmp/ecocity-full.sql $arq | Out-Null
        docker compose exec -T db rm -f /tmp/ecocity-full.sql /tmp/ecocity-full.err | Out-Null
        if (-not (Test-Path $arq) -or (Get-Item $arq).Length -lt 1000) { throw 'dump vazio' }
        $inicio = (Binlogs)[-1]
        Compress-Archive -Path $arq -DestinationPath "$arq.zip" -Force
        Remove-Item $arq
        # Fotos de evidência (ficam fora do banco)
        $fotos = Join-Path (Split-Path $pasta -Parent) 'uploads\evidencias'
        if (Test-Path $fotos) { Compress-Archive -Path (Join-Path $fotos '*') -DestinationPath (Join-Path (Join-Path $Destino 'full') "evidencias-$agora.zip") -Force -ErrorAction SilentlyContinue }
        @{ full = "ecocity-full-$agora.sql.zip"; fullData = $agora; binlogInicioFull = $inicio; binlogProximo = $inicio } | ConvertTo-Json | Set-Content $estadoArq -Encoding utf8
        Registrar "full: ecocity-full-$agora.sql.zip ($([math]::Round((Get-Item "$arq.zip").Length / 1KB)) KB); binlogs a partir de $inicio"
        # Retenção: 4 fulls
        Get-ChildItem (Join-Path $Destino 'full') -Filter 'ecocity-full-*.zip' | Sort-Object Name -Descending | Select-Object -Skip 4 | Remove-Item
        Get-ChildItem (Join-Path $Destino 'full') -Filter 'evidencias-*.zip' | Sort-Object Name -Descending | Select-Object -Skip 4 | Remove-Item
    } else {
        if (-not $estado) { throw 'Nenhum backup full ainda: rode -Tipo full primeiro.' }
        $desde = if ($Tipo -eq 'diferencial') { $estado.binlogInicioFull } else { $estado.binlogProximo }
        $r = CopiarBinlogs $desde "$Tipo-$agora"
        $estado.binlogProximo = $r.proximo
        $estado | ConvertTo-Json | Set-Content $estadoArq -Encoding utf8
        Registrar "$($r.copiados.Count) binlog(s) desde $desde → $($r.pasta)"
    }
    # Retenção: diferenciais e incrementais por 14 dias
    Get-ChildItem (Join-Path $Destino 'binlog') -Directory | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-14) } | Remove-Item -Recurse -Force
} catch {
    Registrar "ERRO: $($_.Exception.Message)"
    exit 1
}
