# Manual do usuário: fonte do `MANUAL.pdf`

> O `MANUAL.pdf` da raiz do repositório é gerado a partir desta pasta. O `MANUAL.md` (também na raiz) é o mesmo conteúdo em texto, para ler no GitHub. Os dois precisam dizer a mesma coisa: ao mudar um, mude o outro.

Esta pasta existe porque, até 2026-10-22, o HTML que gerava o PDF **não estava no repositório**: só o PDF era versionado, e o ajuste seguinte do manual obrigou a refazer o desenho do zero. Origem: `docs/feature/concluidas/202610/20261022-editar-comprovante-depois-de-paga/` (decisão T1 do `plan.md`, tasks TASK-457 e TASK-458).

## Arquivos

| Arquivo | O que é |
|---|---|
| `manual.html` | A fonte do PDF: um HTML único, com o CSS embutido e as imagens por caminho relativo (`images/...`). |
| `images/` | As 7 capturas de tela do manual, em JPEG (`01-login`, `02-meus-grupos`, `03-resumo`, `05-despesas`, `06-nova-despesa`, `07-pagamentos`, `08-relatorios`). |
| `gerar-pdf.ps1` | Gera o PDF com o Chrome (ou o Edge) em modo headless. |
| `README.md` | Este arquivo. |

O `MANUAL.md` aponta para as mesmas imagens (`docs/manual/images/...`).

## Como gerar o PDF

Na raiz do repositório:

```powershell
powershell -ExecutionPolicy Bypass -File docs\manual\gerar-pdf.ps1
```

Isso grava `MANUAL.pdf` na raiz. Para gerar em outro lugar (por exemplo, para comparar antes de sobrescrever):

```powershell
powershell -ExecutionPolicy Bypass -File docs\manual\gerar-pdf.ps1 -Saida $env:TEMP\manual-teste.pdf
```

Parâmetros: `-Html` (outro HTML de origem), `-Saida` (PDF de destino) e `-Navegador` (caminho de um `chrome.exe` ou `msedge.exe`; sem ele o script procura o Chrome e, depois, o Edge).

O script escreve o PDF numa pasta temporária e só então o move para o destino, então uma falha do navegador não apaga o `MANUAL.pdf` que já existe. Ele usa um perfil descartável do Chrome, para não depender de uma janela já aberta.

O Chrome costuma imprimir linhas `ERROR` sobre extensões e registro GCM. São ruído: o que vale é o código de saída `0` e a linha `PDF gerado: ... (N bytes)`.

## Requisitos

- **Windows**, com **Chrome ou Edge** instalado e **PowerShell 5.1 ou mais novo** (o que vem com o Windows).
- As fontes **Georgia**, **Segoe UI**, **Consolas** e **Arial**, que vêm com o Windows. Elas definem a paginação: sem elas o navegador troca por outra e o texto reflui, mudando onde cada página quebra. O script avisa se alguma faltar. **Em Linux ou macOS o resultado não é o mesmo**, não use para gerar o PDF do repositório.
- Opcional, só para conferir o resultado: as ferramentas do poppler (`pdfinfo`, `pdftotext`, `pdffonts`, `pdftoppm`).

## Como conferir que o PDF gerado é o esperado

O arquivo nunca sai idêntico byte a byte, porque o PDF guarda a data de criação. O que se compara é o conteúdo:

```powershell
pdfinfo MANUAL.pdf | Select-String 'Pages|Page size'
pdftotext -raw MANUAL.pdf - | Out-File texto.txt -Encoding utf8
pdffonts MANUAL.pdf
```

Em 2026-10-23 o script, sem mudar nenhuma palavra do HTML, reproduziu o `MANUAL.pdf` de `dev`: 6 páginas, Letter (612 x 792 pt), texto idêntico (`pdftotext -raw` com espaços normalizados, 4660 caracteres), as mesmas fontes embutidas (Arial-Bold, Consolas, Consolas-Bold, Georgia, Georgia-Bold, Segoe UI, Segoe UI Italic, Segoe UI Semibold, Segoe UI Symbol) e as 6 páginas idênticas pixel a pixel quando renderizadas a 72 dpi; só 12 bytes do arquivo diferiam (metadados de data). Um controle com uma palavra a mais no HTML mudou o texto (4660 para 4666 caracteres) e a página 4, como deveria.

Esse era o PDF de antes do texto da edição de comprovante (TASK-458). Com os dois parágrafos novos o manual passou a ter **7 páginas** (670201 bytes): as páginas 1 a 3 continuam idênticas pixel a pixel, e o texto que entrou foi exatamente o desses dois parágrafos (166 palavras acrescentadas, nenhuma removida).

## Como editar o manual

1. Edite o texto em `manual.html`. Cada tela do manual é um bloco `<div class="screen">` com a captura, a legenda e os parágrafos; as seções "O que é", "Como a conta é calculada", "Ciclo e tipos de despesa" e "Passo a passo" são blocos `<section class="block">`.
2. Faça a mesma mudança, com as mesmas palavras, no `MANUAL.md` (o arquivo usa quebra de linha CRLF: preserve).
3. Gere o PDF para um arquivo temporário (`-Saida`), compare o texto com o `MANUAL.pdf` atual e confira que **só mudaram os parágrafos que você editou**. Renderize as páginas afetadas (`pdftoppm -r 60 -png`) e olhe: um parágrafo a mais pode empurrar uma figura para a página seguinte. Cada tela (`.screen`) e as seções marcadas com `keep` não se dividem entre páginas, então um parágrafo a mais pode mandar o bloco inteiro para a página seguinte, deixar um vazio no pé da anterior e refluir o resto (foi o que levou o PDF de 6 para 7 páginas na TASK-458; a seção "Passo a passo" ganhou `keep` para a lista de passos não ficar cortada entre duas páginas).
4. Gere o `MANUAL.pdf` definitivo e faça o commit de `manual.html`, `MANUAL.md` e `MANUAL.pdf` juntos.

## De onde vêm as capturas

As 7 imagens **não foram refeitas**: foram extraídas do `MANUAL.pdf` anterior (com `pdfimages -j`, do poppler) em 2026-10-22 e renomeadas por tela, porque as capturas originais nunca foram versionadas (o `MANUAL.md` apontava para uma pasta `manual-assets/` que não existia no repositório, então as imagens nunca apareciam no GitHub). Todas têm 1366 px de largura e 542 a 599 px de altura. Mostram o grupo "Piatã House", como diz o rodapé do manual.

Elas são do produto **antes** do popup de comprovante e do "Editar comprovante": o popup e o ícone novos só aparecem no texto, não nas imagens. Para trocar uma captura, tire a nova com a mesma largura, salve em JPEG com o **mesmo nome** (o HTML e o `MANUAL.md` referenciam esses nomes) e gere o PDF de novo.
