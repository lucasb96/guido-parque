# Central de Dúvidas · Guido Parque Nações Unidas

Site de perguntas e respostas sobre o empreendimento, publicado de graça no **GitHub Pages**
e atualizado sozinho todo dia por um robô (**GitHub Actions**).

## Como funciona

```text
Google Drive  ─┐
Site da Cury  ─┼─►  Robô diário (06h, GitHub Actions)  ─►  site no GitHub Pages
content/ (Git)─┘     sincroniza, lê PDFs (com OCR),          (data.json + search.json)
                     gera o site e publica
```

- **`content/`**: o que a Comissão edita no painel (perguntas, linha do tempo, glossário,
  situação atual, dados dos documentos, textos colados de PDFs).
- **`state/`**: o que o robô atualiza sozinho (lista de arquivos do Drive, textos extraídos,
  andamento da obra, fornecedores). **Não edite à mão.**
- O banco SQLite (`storage/`) é só um cache, recriado a partir desses arquivos.

## O que é automático (todo dia às 06h)
- Detecta arquivos **novos, alterados ou removidos** nas pastas do Drive (inclui subpastas).
- **Extrai o texto** de PDFs, Word, Google Docs e Planilhas e o coloca na busca do site.
  PDFs **escaneados** são lidos com **OCR** (Tesseract, em português).
  CPF, RG, e-mails e IPs são ocultados automaticamente.
- Atualiza **fornecedores** (planilha colaborativa), **galeria** e **acompanhamento da obra** (site da Cury).
- Gera e publica o site. Para rodar na hora: aba **Actions** → *Atualizar e publicar o site* → **Run workflow**.

## Editar o conteúdo (painel da Comissão)
O painel roda no seu computador:
1. Dê dois cliques em **`iniciar-painel.bat`**. Ele baixa as últimas atualizações e abre o painel.
   Na primeira vez, se pedir, instale o PHP com `winget install PHP.PHP.8.3`.
2. Edite perguntas, situação, linha do tempo etc. Tudo é salvo em `content/`.
3. Clique em **"Publicar alterações"** no início do painel. Em 1 a 2 minutos, o site é atualizado.

Requisitos no computador: **Git** (logado no GitHub; `gh auth setup-git` resolve) e **PHP 8+**.
O arquivo `app/config.php` (não vai para o GitHub) guarda a senha do painel:
gere o hash com `php cron/hash-senha.php "sua senha"`.

## Primeira configuração do repositório (uma vez)
1. **Settings → Secrets and variables → Actions → New secret**: `GOOGLE_API_KEY` com a chave da Google Drive API.
2. **Settings → Pages → Build and deployment → Source: GitHub Actions**.
3. No Google Cloud, deixe a chave restrita à **Google Drive API**.

## Estrutura
```text
index.html, css/, js/, img/   site (app instalável, modo escuro, busca no navegador)
content/  state/              dados versionados (ver acima)
admin/                        painel da Comissão (uso local)
app/                          código PHP: Drive, extração/OCR, busca, exportação
tools/build.php               gera o site estático em dist/ (--sync para sincronizar antes)
.github/workflows/site.yml    robô diário
iniciar-painel.bat            abre o painel no Windows
api.php, cron/, sync-web.php  alternativa para hospedagem PHP tradicional (opcional)
```

## Links úteis
- `.../#faq-voltagem` abre direto uma pergunta (botão "Copiar link" em cada card)
- `.../?q=vaga` abre já com uma busca
- `.../#doc-<id>` abre um documento no visualizador
