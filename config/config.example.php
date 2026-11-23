<?php
/**
 * Modelo de configuração do EcoCity.
 *
 * Copie este arquivo para "config.local.php" (mesma pasta) e preencha a senha.
 * O config.local.php contém credenciais: não compartilhe nem publique.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,          // MySQL 8.0.31 do Wamp
        'name'    => 'ecocity',
        'user'    => 'root',
        'pass'    => '',            // ← senha do MySQL
        'charset' => 'utf8mb4',
    ],
    // true mostra detalhes de erro na tela (somente em desenvolvimento)
    'debug' => true,

    // true só atrás do Cloudflare Tunnel (servidor Docker): IP e HTTPS do visitante pelos cabeçalhos da Cloudflare
    'proxy_confiavel' => false,

    // Endereço do site usado nos links enviados por e-mail (sem barra no final)
    'url_base' => 'http://localhost/ecocity2',

    // IA local (RN91): Ollama com um modelo de visão. No Docker o serviço "ollama" publica a porta só em 127.0.0.1.
    // Sem este bloco a IA fica ligada com estes valores; 'ativo' => false desliga (fotos voltam ao administrador / 24 h).
    'ia' => [
        'ativo'            => true,
        'url'              => 'http://127.0.0.1:11434',
        'modelo'           => 'qwen3-vl:8b-instruct',
        'confianca_minima' => 0.8,   // a IA só aprova sozinha com esta certeza ou mais
        'tempo_limite'     => 90,    // segundos por análise
    ],

    // E-mail (recuperação de senha) via SMTP — PHPMailer em lib/PHPMailer.
    // Ex.: Gmail = smtp.gmail.com, porta 587, 'tls', usuário = seu e-mail, senha = "senha de app".
    // Sem 'host', o e-mail não é enviado; com 'debug' => true o link aparece na tela (só para teste).
    'smtp' => [
        'host'           => '',
        'porta'          => 587,
        'seguranca'      => 'tls',      // 'tls' (porta 587) ou 'ssl' (porta 465)
        'usuario'        => '',
        'senha'          => '',
        'remetente'      => '',         // e-mail que aparece como remetente
        'nome_remetente' => 'EcoCity',
    ],
];
