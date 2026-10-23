<#
.SYNOPSIS
  Gera o MANUAL.pdf a partir de docs/manual/manual.html, com o Chrome (ou o Edge) em modo headless.

.DESCRIPTION
  Veja docs/manual/README.md para os requisitos e para o passo a passo de edicao.
  O PDF e escrito primeiro numa pasta temporaria e so entao movido para o destino:
  se o navegador falhar, o arquivo que ja existe em -Saida nao e tocado.

  Este arquivo e propositalmente so ASCII: o PowerShell 5.1 le .ps1 sem BOM como ANSI
  e corromperia as mensagens acentuadas.

.PARAMETER Html
  HTML de origem. Padrao: manual.html ao lado deste script.

.PARAMETER Saida
  PDF de destino. Padrao: MANUAL.pdf na raiz do repositorio.

.PARAMETER Navegador
  Caminho do chrome.exe ou msedge.exe. Padrao: procura o Chrome e, depois, o Edge.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File docs\manual\gerar-pdf.ps1

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File docs\manual\gerar-pdf.ps1 -Saida $env:TEMP\manual-teste.pdf
#>
[CmdletBinding()]
param(
    [string]$Html,
    [string]$Saida,
    [string]$Navegador
)

$ErrorActionPreference = 'Stop'

# Os padroes ficam aqui e nao no param(): no PowerShell 5.1 o $PSScriptRoot vem vazio dentro do valor
# padrao de um parametro.
$aqui = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $Html) { $Html = Join-Path $aqui 'manual.html' }
if (-not $Saida) { $Saida = Join-Path $aqui '..\..\MANUAL.pdf' }

function Find-Browser {
    $candidatos = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
    )
    foreach ($candidato in $candidatos) {
        if ($candidato -and (Test-Path -LiteralPath $candidato)) { return $candidato }
    }
    return $null
}

function Test-Fonts {
    # A paginacao do PDF depende das fontes do CSS (Georgia, Segoe UI, Consolas e Arial).
    # Sem elas o navegador troca por outra e o texto reflui: avisa, mas nao impede.
    Add-Type -AssemblyName System.Drawing
    $instaladas = (New-Object System.Drawing.Text.InstalledFontCollection).Families | ForEach-Object { $_.Name }
    foreach ($fonte in 'Georgia', 'Segoe UI', 'Consolas', 'Arial') {
        if ($instaladas -notcontains $fonte) {
            Write-Warning "Fonte '$fonte' nao encontrada: o PDF pode sair com outra paginacao."
        }
    }
}

if (-not (Test-Path -LiteralPath $Html)) {
    Write-Error "HTML nao encontrado: $Html"
    exit 1
}
$htmlCompleto = (Resolve-Path -LiteralPath $Html).Path
$saidaCompleta = [System.IO.Path]::GetFullPath($Saida)

if (-not $Navegador) { $Navegador = Find-Browser }
if (-not $Navegador -or -not (Test-Path -LiteralPath $Navegador)) {
    Write-Error 'Chrome ou Edge nao encontrado. Instale um deles ou informe o caminho em -Navegador.'
    exit 1
}

Test-Fonts

# file:///E:/.../manual.html com os espacos do caminho ja codificados.
$uri = ([System.Uri]$htmlCompleto).AbsoluteUri

# Perfil descartavel: sem ele o Chrome pode entregar o pedido a uma janela ja aberta e nao imprimir nada.
$pasta = Join-Path ([System.IO.Path]::GetTempPath()) ('manual-pdf-' + [guid]::NewGuid().ToString('N'))
$perfil = Join-Path $pasta 'perfil'
$temporario = Join-Path $pasta 'MANUAL.pdf'
New-Item -ItemType Directory -Path $perfil -Force | Out-Null

try {
    $argumentos = '--headless=new --disable-gpu --no-pdf-header-footer ' +
        '--user-data-dir="' + $perfil + '" ' +
        '--print-to-pdf="' + $temporario + '" ' +
        '"' + $uri + '"'

    Write-Host "Navegador: $Navegador"
    Write-Host "Origem:    $htmlCompleto"
    $processo = Start-Process -FilePath $Navegador -ArgumentList $argumentos -Wait -PassThru -NoNewWindow

    if ($processo.ExitCode -ne 0) {
        Write-Error "O navegador terminou com codigo $($processo.ExitCode)."
        exit 1
    }
    if (-not (Test-Path -LiteralPath $temporario) -or (Get-Item -LiteralPath $temporario).Length -eq 0) {
        Write-Error 'O navegador nao gerou o PDF.'
        exit 1
    }

    $destinoPasta = Split-Path -Parent $saidaCompleta
    if (-not (Test-Path -LiteralPath $destinoPasta)) { New-Item -ItemType Directory -Path $destinoPasta -Force | Out-Null }
    Move-Item -LiteralPath $temporario -Destination $saidaCompleta -Force

    $tamanho = (Get-Item -LiteralPath $saidaCompleta).Length
    Write-Host "PDF gerado: $saidaCompleta ($tamanho bytes)"
}
finally {
    Remove-Item -LiteralPath $pasta -Recurse -Force -ErrorAction SilentlyContinue
}
