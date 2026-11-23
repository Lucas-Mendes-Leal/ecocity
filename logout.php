<?php
require __DIR__ . '/includes/bootstrap.php';

// Só encerra a sessão por POST com token válido (evita logout forçado por link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    sair();
}
redirect('login.php');
