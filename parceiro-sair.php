<?php
/** Encerra a sessão do parceiro (só POST com CSRF). A sessão de jogador, se houver, continua. */
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    sair_parceiro();
}
redirect('parceiro-entrar.php');
