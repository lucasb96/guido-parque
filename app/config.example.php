<?php
/*
 * Copie este arquivo para "config.php" (nesta mesma pasta) e preencha.
 * NUNCA publique o config.php em repositórios ou envie por chat.
 */
return [
    // Chave da API do Google (Google Cloud > Credenciais), restrita à Google Drive API
    'google_api_key' => 'COLE_AQUI_A_CHAVE',

    // Pastas públicas do Google Drive que serão monitoradas (inclui subpastas)
    'drive_folders' => [
        '15U7aaL1TqGK2hOr0Vlu2ILvUxVqNSpRB', // Informativos
        '1Facfx9GzPhTotkJDz3ZP_T6RLUzY314m', // Documentos oficiais
    ],

    // Senha do painel /admin. Gere o hash com:  php cron/hash-senha.php "sua senha"
    'admin_password_hash' => '',

    // Token para disparar a sincronização por URL (para hospedagens com "cron por URL").
    // Use uma sequência longa e aleatória. Deixe vazio para desativar.
    'sync_token' => '',

    // Pasta onde ficam o banco de dados e os logs. O ideal é FORA da pasta pública
    // (ex.: '/home/seu_usuario/condominio-dados'). Vazio = pasta "storage" do projeto,
    // protegida por .htaccess (funciona em Apache/LiteSpeed, NÃO em Nginx).
    'storage_path' => '',

    // Fuso horário
    'timezone' => 'America/Sao_Paulo',

    // Página do empreendimento no site da Cury (fonte do "Acompanhamento de obras")
    'cury_progress_url' => 'https://cury.net/imovel/SP/zona-sul/guido-parque-nacoes-unidas',

    // Arquivos maiores que isso (MB) não são baixados para extração de texto
    'max_download_mb' => 40,
];
