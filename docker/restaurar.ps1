<#
  EcoCity — restauração do banco a partir dos backups de docker/backup.ps1.

  Restaura num container SEPARADO (ecocity-restore, porta local 3317), sem tocar no site:
    1. sobe um MySQL 8.0.40 novo;
    2. importa o último backup full (ou o indicado em -Full);
    3. reaplica os binlogs a partir do início do full, até o horário -Ate (recuperação em um ponto no tempo)
       — sem -Ate, aplica tudo o que houver;
    4. confere a integridade (CALL sp_verificar_integridade()).
  Para colocar no ar, siga o plano de recuperação (specs/banco-de-dados.md): parar o site, importar o
  dump gerado aqui no container db e recriar as contas (database/projeto/08-seguranca.sql).

  Uso:  powershell -ExecutionPolicy Bypass -File docker\restaurar.ps1 [-Destino C:\EcoCity-Backups] [-Ate "2026-11-18 10:30:00"] [-Full arquivo.zip]
  Remover o container de teste depois:  docker rm -f ecocity-restore
#>
param(
    [string]$Destino = (Join-Path $env:USERPROFILE 'EcoCity-Backups'),
    [string]$Ate = '',
    [string]$Full = '',
    [string]$Container = 'ecocity-restore',
    [string]$MysqlBinlog = '',   # mysqlbinlog.exe (a imagem oficial do MySQL não traz); padrão: o do Wamp
    [int]$Porta = 3317   # 3306 é o MySQL do Wamp e 3307 o MariaDB
)
$ErrorActionPreference = 'Continue'   # comandos nativos (docker) escrevem avisos no stderr; os erros são conferidos por `$LASTEXITCODE`
$estado = Get-Content (Join-Path $Destino 'estado.json') -Raw | ConvertFrom-Json
if (-not $Full) { $Full = Join-Path (Join-Path $Destino 'full') $estado.full }
if (-not (Test-Path $Full)) { throw "Backup full não encontrado: $Full" }
if (-not $MysqlBinlog) { $MysqlBinlog = Get-ChildItem 'C:\wamp64\bin\mysql\*\bin\mysqlbinlog.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName }
$senha = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
function Passo([string]$t) { Write-Output "$(Get-Date -Format 'HH:mm:ss') $t" }

# 1. MySQL novo e vazio (mesmas opções do site)
docker rm -f $Container 2>$null | Out-Null
docker run -d --name $Container -e "MYSQL_ROOT_PASSWORD=$senha" -e MYSQL_DATABASE=ecocity -p "127.0.0.1:${Porta}:3306" mysql:8.0.40 `
    --sql-mode= --default-time-zone=-03:00 --character-set-server=utf8mb4 --collation-server=utf8mb4_0900_ai_ci | Out-Null
if ($LASTEXITCODE -ne 0) { throw "não foi possível criar o container $Container (porta $Porta ocupada?)" }
Passo "container $Container criado; aguardando o MySQL..."
for ($i = 0; $i -lt 60; $i++) {
    docker exec -e "MYSQL_PWD=$senha" $Container mysql -uroot -e 'SELECT 1' ecocity 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep 2
}

# 2. Backup full
$tmp = Join-Path $env:TEMP "ecocity-restore-$(Get-Random)"
Expand-Archive -Path $Full -DestinationPath $tmp -Force
$sql = Get-ChildItem $tmp -Filter '*.sql' | Select-Object -First 1
docker cp $sql.FullName "${Container}:/tmp/full.sql" | Out-Null
docker exec -e "MYSQL_PWD=$senha" $Container sh -c 'mysql -uroot --default-character-set=utf8mb4 ecocity < /tmp/full.sql' 2>$null
if ($LASTEXITCODE -ne 0) { throw 'falha ao importar o backup full' }
Passo "full importado: $(Split-Path $Full -Leaf)"

# 3. Binlogs a partir do início do full (todas as pastas de backup, sem repetir arquivo)
$binlogs = Get-ChildItem (Join-Path $Destino 'binlog') -Recurse -Filter 'binlog.*' |
    Where-Object { $_.Name -ge $estado.binlogInicioFull } | Sort-Object Name, LastWriteTime -Descending |
    Group-Object Name | ForEach-Object { $_.Group[0] } | Sort-Object Name
if ($binlogs) {
    if (-not $MysqlBinlog -or -not (Test-Path $MysqlBinlog)) { throw 'mysqlbinlog.exe não encontrado (use -MysqlBinlog caminho)' }
    # Converte os binlogs em SQL no Windows (fuso de Brasília: o horário de -Ate é usado direto) e grava em arquivo
    $sqlBin = Join-Path $tmp 'binlogs.sql'
    $args_ = @('--database=ecocity', "--result-file=$sqlBin")
    if ($Ate) { $args_ += "--stop-datetime=$Ate" }
    & $MysqlBinlog @args_ ($binlogs | ForEach-Object { $_.FullName })
    if ($LASTEXITCODE -ne 0) { throw 'mysqlbinlog falhou ao ler os binlogs' }
    docker cp $sqlBin "${Container}:/tmp/binlogs.sql" | Out-Null
    docker exec -e "MYSQL_PWD=$senha" $Container sh -c 'mysql -uroot --default-character-set=utf8mb4 < /tmp/binlogs.sql'
    if ($LASTEXITCODE -ne 0) { throw 'falha ao reaplicar os binlogs' }
    Passo "$($binlogs.Count) binlog(s) reaplicado(s)$(if ($Ate) { " até $Ate" } else { ' (tudo)' })"
} else {
    Passo 'nenhum binlog depois do full'
}
Remove-Item $tmp -Recurse -Force

# 4. Integridade
Passo 'integridade:'
docker exec -e "MYSQL_PWD=$senha" $Container mysql -uroot -t ecocity -e 'CALL sp_verificar_integridade(); SELECT COUNT(*) AS jogadores FROM jogador;' 2>$null
Passo "pronto: banco restaurado em $Container (127.0.0.1:$Porta, usuário root; senha só nesta execução)."
