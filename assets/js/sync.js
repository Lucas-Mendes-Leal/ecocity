/**
 * Sincronização em tempo real do jogo (RN85).
 *
 * A cada 4 s com a aba visível envia ao servidor as versões que conhece (api/sincronizar.php) e
 * recebe só os dados dos domínios que mudaram — por outra pessoa, por outra aba ou pelas regras
 * automáticas. Aba escondida: pausa; ao voltar, sincroniza na hora. Depois de uma ação do jogador,
 * sincroniza logo. Sem conexão: espera 8, 16 e até 30 s e mostra um aviso discreto.
 */
import { state, chamarApi, aplicarSincronia } from './state.js';

const INTERVALO_MS = 4000;
const ESPERA_MAX_MS = 30000;
const APOS_ACAO_MS = 600;

let timer = null;
let emAndamento = false;
let falhas = 0;

let sessaoMudou = false;   // 403: o token desta página é de uma sessão antiga (a conta entrou de novo noutra aba)

function aviso(mostrar) {
    let el = document.getElementById('sync-offline');
    if (!mostrar) { el?.remove(); return; }
    if (!el) {
        el = document.createElement('div');
        el.id = 'sync-offline';
        el.className = 'sync-offline';
        el.setAttribute('role', 'status');
        document.body.append(el);
    }
    el.innerHTML = sessaoMudou
        ? 'Sua sessão mudou. Recarregue a página. <button type="button" class="btn btn-primary btn-sm">Recarregar</button>'
        : 'Sem conexão com o servidor. Tentando de novo…';
    el.querySelector('button')?.addEventListener('click', () => window.location.reload());
}

function agendar(ms) {
    clearTimeout(timer);
    if (!document.hidden) timer = setTimeout(sincronizar, ms);
}

export async function sincronizar() {
    clearTimeout(timer);
    if (document.hidden || sessaoMudou) return;
    if (emAndamento) { agendar(APOS_ACAO_MS); return; }
    emAndamento = true;
    try {
        const r = await chamarApi('api/sincronizar.php', { versoes: JSON.stringify(state.versoes || {}) });
        aplicarSincronia(r);
        falhas = 0;
        aviso(false);
    } catch (err) {
        falhas++;
        if (err.status === 403) sessaoMudou = true;   // não adianta tentar de novo: só recarregando
        if (falhas >= 2 || sessaoMudou) aviso(true);
    } finally {
        emAndamento = false;
        if (!sessaoMudou) agendar(falhas ? Math.min(ESPERA_MAX_MS, INTERVALO_MS * 2 ** falhas) : INTERVALO_MS);
    }
}

export function iniciarSincronia() {
    document.addEventListener('visibilitychange', () => {
        if (document.hidden || sessaoMudou) clearTimeout(timer); else sincronizar();
    });
    document.addEventListener('ecocity:acao', () => agendar(APOS_ACAO_MS));
    window.addEventListener('online', () => sincronizar());
    agendar(INTERVALO_MS);
}
