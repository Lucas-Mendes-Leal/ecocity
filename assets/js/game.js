/**
 * Ponto de entrada do painel do jogo (jogo.php).
 */
import './pwa.js';
import { CATEGORIAS, REGIOES } from './data/catalog.js';
import { state, subscribe, iniciarEstado, nivelCidade, estagioIndicador } from './state.js';
import { initCityMap, renderCity, setSelectedRegion, reanexarCidade } from './city/city-map.js';
import { initRegionPanel, openRegionPanel, renderRegionPanel } from './city/region-panel.js';
import { initHud, renderHud } from './ui/hud.js';
import { initTabs } from './ui/tabs.js';
import { initMissions, renderRegistros } from './ui/missions.js';
import { initQuiz, renderQuizList } from './ui/quiz.js';
import { initAchievements, renderAchievements, comemorarConquistas } from './ui/achievements.js';
import { initProfile, renderProfile, fecharConta } from './ui/profile.js';
import { renderWeeklyMissions, notificarEspeciais } from './ui/weekly-missions.js';
import { initBuildMode, renderBuildMode } from './ui/build-mode.js';
import { initDemo } from './ui/demo.js';
import { iniciarTutorial } from './ui/tutorial.js';
import { initFriends } from './ui/friends.js';
import { initCommunity, notificarMissoesComunidade } from './ui/community.js';
import { initEcoGuia } from './ui/ecoguia.js';
import { hydrateIcons } from './ui/icons.js';
import { iniciarSincronia } from './sync.js';
import { $, toast } from './ui/dom.js';
import { iniciarSom, aplicarPreferencias, tocar } from './som.js';

/** Dados da cidade derivados do estado atual. */
function dadosCidade() {
    const estagios = Object.fromEntries(REGIOES.map((r) => [r.id, estagioIndicador(r.indicador)]));
    const indicadores = Object.fromEntries(CATEGORIAS.map((c) => [c.id, state.jogador.indicadores[c.id]]));
    return { nivelCidade: nivelCidade(), estagios, indicadores, construcao: state.construcao };
}

function renderTudo(s) {
    const cidade = dadosCidade();
    renderHud(s);
    renderCity(cidade);
    renderRegionPanel(cidade);
    renderRegistros(s);
    renderQuizList(s);
    renderAchievements();
    renderProfile();
    renderWeeklyMissions();
    renderBuildMode();
}

function notificar(_s, evento) {
    // Som do momento (RN96): conquista > nível > cidade evoluiu > missão (o som.js cala XP e moedas que vêm junto)
    if (evento.novasConquistas?.length) tocar('conquista');
    else if (evento.subiuNivel) tocar('nivel');
    else if (evento.cidadeEvoluiu) tocar('desbloqueio');
    else if (evento.missoesConcluidas?.length) tocar('missao');
    if (state.perfil?.conta?.som) aplicarPreferencias(state.perfil.conta.som);   // mudou noutro aparelho
    if (evento.tipo === 'servidor' && evento.mensagem) toast(evento.mensagem);
    if (evento.cidadeEvoluiu) toast(`Sua cidade evoluiu para o nível ${nivelCidade()}! Novas construções liberadas.`);
    if (evento.subiuNivel) toast(`Parabéns! Você alcançou o nível ${state.jogador.nivel}.`);
    evento.novasConquistas?.forEach((nome) => toast(`Conquista desbloqueada: ${nome}!`));
    if (evento.novasConquistas?.length) comemorarConquistas(evento.novasConquistas);
    evento.missoesConcluidas?.forEach((nome) => toast(`Missão concluída: ${nome}!`));
    notificarMissoesComunidade(state.avisosComunidade);   // repetidas são ignoradas
    notificarEspeciais();                                 // missão especial nova: uma vez só
    if (evento.tipo === 'inicio') {
        // Resultados processados ao abrir o jogo (semanas encerradas enquanto o jogador estava fora)
        state.avisosMissoes.concluidas.forEach((nome) => toast(`Missão concluída: ${nome}!`));
        state.avisosMissoes.naoConcluidas.forEach((nome) => toast(`Missão não concluída: ${nome}. A área perdeu pontos.`, 'error'));
    }
}

hydrateIcons();
iniciarSom(JSON.parse(document.getElementById('dados-iniciais').textContent).perfil?.conta?.som || {});
initTabs($('game-tabs'));
initHud();
// Conta desativada pelo jogador e reativada ao entrar (RN93)
if (JSON.parse(document.getElementById('dados-iniciais').textContent).contaReativada) {
    toast('Sua conta foi reativada. Que bom ter você de volta!');
}

// Voltando à Minha Cidade depois de visitar um amigo, o trânsito volta ao mapa próprio
document.getElementById('t-cidade').addEventListener('click', reanexarCidade);

initCityMap({
    container: $('city-map'),
    legend: $('city-legend'),
    badge: $('city-level-badge'),
    zoomIn: $('zoom-in'),
    zoomOut: $('zoom-out'),
    zoomReset: $('zoom-reset'),
    onSelect: (regiaoId, origem) => {
        setSelectedRegion(regiaoId);
        openRegionPanel(regiaoId, dadosCidade(), origem);
    },
});
initRegionPanel({ onClosed: () => setSelectedRegion(null) });
initMissions();
initAchievements();
initQuiz();
initFriends();
initCommunity();
initEcoGuia();
initProfile();
initBuildMode();
initDemo();

subscribe(renderTudo);
subscribe(notificar);
iniciarEstado();
iniciarSincronia();   // atualização em tempo real (RN85)

// Tutorial de boas-vindas (RN90): só para quem ainda não viu; nunca no modo demonstração
$('profile-tutorial').addEventListener('click', () => { fecharConta({ devolverFoco: false }); iniciarTutorial({ rever: true }); });
if (!state.jogador.tutorialVisto && !new URLSearchParams(location.search).has('demo')) {
    requestAnimationFrame(() => iniciarTutorial());
}
