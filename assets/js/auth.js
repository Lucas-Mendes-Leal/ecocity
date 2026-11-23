/**
 * Login e cadastro — validação no navegador antes do envio.
 * As mesmas regras são aplicadas de novo no servidor (includes/conta.php).
 */
import './pwa.js';
import { $ } from './ui/dom.js';
import { hydrateIcons } from './ui/icons.js';
import { iniciarCenaAuth } from './auth-scene.js';
import { iniciarSeletorAtividades } from './ui/seletor-atividades.js';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const SENHA_MIN = 6;

function setFieldError(fieldId, message) {
    const input = $(fieldId);
    const error = $(`error-${fieldId}`);
    if (error) error.textContent = message;
    if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
}

function clearErrors(form) {
    form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.setAttribute('aria-invalid', 'false'));
    $('alert-box')?.classList.add('hidden');
}

/** Aplica as regras; foca o primeiro campo inválido. Retorna true se tudo passou. */
function validate(rules) {
    let primeiro = null;
    rules.forEach(([fieldId, valid, message]) => {
        if (!valid) {
            setFieldError(fieldId, message);
            primeiro = primeiro || $(fieldId);
        }
    });
    primeiro?.focus();
    return primeiro === null;
}

/** Bloqueia o envio só quando há erro; se válido, o formulário segue para o PHP. */
function onSubmit(form, rules) {
    form.addEventListener('submit', (e) => {
        clearErrors(form);
        if (!validate(rules())) {
            e.preventDefault();
            return;
        }
        form.querySelector('[type="submit"]').disabled = true;
    });
}

const val = (id) => $(id).value.trim();

const loginForm = $('form-login');
if (loginForm) {
    // Área do parceiro (RN95): o funcionário entra com o usuário no lugar do e-mail
    const usuario = 'aceitaUsuario' in loginForm.dataset;
    onSubmit(loginForm, () => [
        usuario
            ? ['email', EMAIL_RE.test(val('email')) || /^[a-z0-9._-]{3,30}$/i.test(val('email')), 'Informe o e-mail do estabelecimento ou o seu usuário.']
            : ['email', EMAIL_RE.test(val('email')), 'Informe um e-mail válido.'],
        ['senha', $('senha').value.length >= SENHA_MIN, `A senha deve ter no mínimo ${SENHA_MIN} caracteres.`],
    ]);
}

const cadastroForm = $('form-cadastro');
if (cadastroForm) {
    onSubmit(cadastroForm, () => [
        ['nome', val('nome').length >= 3, 'Informe seu nome (mínimo 3 caracteres).'],
        ['apelido', val('apelido').length >= 3, 'O apelido deve ter ao menos 3 caracteres.'],
        ['cidade', val('cidade').length >= 3, 'O nome da cidade deve ter ao menos 3 caracteres.'],
        ['email', EMAIL_RE.test(val('email')), 'Informe um e-mail válido.'],
        ['senha', $('senha').value.length >= SENHA_MIN, `A senha deve ter no mínimo ${SENHA_MIN} caracteres.`],
        ['confirmar-senha', $('senha').value === $('confirmar-senha').value && $('confirmar-senha').value !== '', 'As senhas não coincidem.'],
    ]);
}

const esqueciForm = $('form-esqueci');
if (esqueciForm) {
    onSubmit(esqueciForm, () => [['email', EMAIL_RE.test(val('email')), 'Informe um e-mail válido.']]);
}

const redefinirForm = $('form-redefinir');
if (redefinirForm) {
    onSubmit(redefinirForm, () => [
        ['senha', $('senha').value.length >= SENHA_MIN, `A senha deve ter no mínimo ${SENHA_MIN} caracteres.`],
        ['confirmar-senha', $('senha').value === $('confirmar-senha').value && $('confirmar-senha').value !== '', 'As senhas não coincidem.'],
    ]);
}

const parceiroForm = $('form-parceiro');
if (parceiroForm) {
    onSubmit(parceiroForm, () => [
        ['nome', val('nome').length >= 3 && val('nome').length <= 100, 'Nome do estabelecimento: use de 3 a 100 caracteres.'],
        ['responsavel', val('responsavel').length >= 3 && val('responsavel').length <= 100, 'Responsável: use de 3 a 100 caracteres.'],
        ['email', EMAIL_RE.test(val('email')) && val('email').length <= 100, 'Informe um e-mail válido.'],
        ['endereco', val('endereco').length >= 5 && val('endereco').length <= 200, 'Endereço: use de 5 a 200 caracteres.'],
        ['descricao', val('descricao').length >= 10 && val('descricao').length <= 250, 'Descrição: use de 10 a 250 caracteres.'],
        ['atividades', parceiroForm.querySelector('input[name="atividades[]"]:checked') !== null, 'Marque pelo menos uma atividade que o estabelecimento pode verificar.'],
        ['senha', $('senha').value.length >= SENHA_MIN, `A senha deve ter no mínimo ${SENHA_MIN} caracteres.`],
        ['confirmar-senha', $('senha').value === $('confirmar-senha').value && $('confirmar-senha').value !== '', 'As senhas não coincidem.'],
    ]);
}

hydrateIcons();
iniciarSeletorAtividades();
iniciarCenaAuth($('auth-scene'));
