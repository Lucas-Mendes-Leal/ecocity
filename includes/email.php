<?php
/**
 * Envio de e-mail por SMTP com PHPMailer 6.9.3 (lib/PHPMailer, sem Composer).
 * Configuração em config/config.local.php, chave 'smtp' (ver config.example.php).
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once ECOCITY_ROOT . '/lib/PHPMailer/Exception.php';
require_once ECOCITY_ROOT . '/lib/PHPMailer/PHPMailer.php';
require_once ECOCITY_ROOT . '/lib/PHPMailer/SMTP.php';

/** SMTP preenchido no config.local.php? */
function smtp_configurado(): bool
{
    $s = config('smtp');
    return is_array($s) && !empty($s['host']) && !empty($s['remetente']);
}

/**
 * Envia um e-mail. Retorna false (e registra no log) se o SMTP não estiver configurado ou falhar.
 */
function enviar_email(string $para, string $nome, string $assunto, string $html, string $texto): bool
{
    if (!smtp_configurado()) {
        return false;
    }
    $s = config('smtp');
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $s['host'];
        $mail->Port = (int) ($s['porta'] ?? 587);
        $mail->SMTPAuth = !empty($s['usuario']);
        $mail->Username = (string) ($s['usuario'] ?? '');
        $mail->Password = (string) ($s['senha'] ?? '');
        $mail->SMTPSecure = ($s['seguranca'] ?? 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($s['remetente'], $s['nome_remetente'] ?? 'EcoCity');
        $mail->addAddress($para, $nome);
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body = $html;
        $mail->AltBody = $texto;
        $mail->send();
        return true;
    } catch (PHPMailerException $ex) {
        error_log('EcoCity — e-mail não enviado: ' . $mail->ErrorInfo);
        return false;
    }
}

/** Monta e envia o e-mail com o link de redefinição de senha. */
function enviar_email_redefinicao(string $para, string $nome, string $link): bool
{
    $minutos = REDEFINICAO_VALIDADE_MIN;
    $nomeHtml = e($nome);
    $linkHtml = e($link);
    $html = <<<HTML
<div style="font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#2C3E50;max-width:520px;margin:0 auto">
  <h2 style="color:#2E7D32">Redefinir sua senha do EcoCity</h2>
  <p>Olá, {$nomeHtml}!</p>
  <p>Recebemos um pedido para redefinir a senha da sua conta. Clique no botão abaixo para escolher uma nova senha:</p>
  <p style="text-align:center;margin:28px 0">
    <a href="{$linkHtml}" style="background:#2E7D32;color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:700">Redefinir senha</a>
  </p>
  <p>O link vale por {$minutos} minutos e só pode ser usado uma vez.</p>
  <p style="color:#5F6B78;font-size:13px">Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.</p>
</div>
HTML;
    $texto = "Olá, {$nome}!\n\nPara redefinir a senha do EcoCity, abra o link abaixo (vale por {$minutos} minutos, uso único):\n{$link}\n\nSe você não pediu a redefinição, ignore este e-mail.";
    return enviar_email($para, $nome, 'Redefinir sua senha — EcoCity', $html, $texto);
}
