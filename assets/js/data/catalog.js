/**
 * Definições estruturais do EcoCity (não são dados de jogador).
 * Espelham as tabelas categoria_ambiental, indicador_ambiental, regiao_cidade
 * e nivel_cidade. Valores numéricos marcados como PROVISÓRIO ainda não foram
 * aprovados (ver specs/site.md §7).
 */

/** 5 categorias ambientais — cada uma com exatamente um indicador (D4). */
export const CATEGORIAS = [
    { id: 'recycling', nome: 'Reciclagem e Resíduos',   icone: 'recycle', curto: 'Reciclagem' },
    { id: 'water',     nome: 'Economia de Água',        icone: 'droplet', curto: 'Água' },
    { id: 'energy',    nome: 'Economia de Energia',     icone: 'bolt', curto: 'Energia' },
    { id: 'mobility',  nome: 'Mobilidade Sustentável',  icone: 'bike', curto: 'Mobilidade' },
    { id: 'nature',    nome: 'Preservação da Natureza', icone: 'tree', curto: 'Natureza' },
];

export const CATEGORIA_POR_ID = Object.fromEntries(CATEGORIAS.map((c) => [c.id, c]));

/** Limites de cada indicador (indicador_ambiental.valor_minimo / valor_maximo). */
export const INDICADOR_MIN = 0;
export const INDICADOR_MAX = 100;

/**
 * Regiões fixas da cidade (regiao_cidade).
 * Imagens do painel: assets/img/locais/{prefixo}-estagio-{1..4}.png
 */
export const REGIOES = [
    {
        id: 'recycling', indicador: 'recycling', prefixo: 'reciclagem',
        nome: 'Bairro e Gestão de Resíduos',
        descricao: 'Mede a limpeza das ruas, o descarte adequado de lixo e o engajamento da comunidade na coleta seletiva.',
    },
    {
        id: 'water', indicador: 'water', prefixo: 'lago',
        nome: 'Lago Urbano e Recursos Hídricos',
        descricao: 'Representa a qualidade da água da cidade, a despoluição do lago e o uso consciente do abastecimento doméstico.',
    },
    {
        id: 'energy', indicador: 'energy', prefixo: 'energia',
        nome: 'Atmosfera e Matriz Energética',
        descricao: 'Indica a emissão de gases poluentes, o consumo elétrico racional e o uso de energia limpa.',
    },
    {
        id: 'mobility', indicador: 'mobility', prefixo: 'mobilidade',
        nome: 'Vias e Mobilidade Urbana',
        descricao: 'Mede a qualidade do transporte público, a presença de ciclovias e a escolha por caminhadas.',
    },
    {
        id: 'nature', indicador: 'nature', prefixo: 'parque',
        nome: 'Parque Natural e Área Verde',
        descricao: 'Representa a saúde da vegetação, a conservação da biodiversidade e a cobertura de árvores da cidade.',
    },
];

export const REGIAO_POR_ID = Object.fromEntries(REGIOES.map((r) => [r.id, r]));

/**
 * Níveis/estágios da cidade (nivel_cidade.pontuacao_minima — D3a).
 * PROVISÓRIO: faixas 0 / 25 / 50 / 75 vindas do protótipo.
 */
export const NIVEIS_CIDADE = [
    { nivel: 1, pontuacaoMinima: 0,  nome: 'Cidade Degradada',       situacao: 'Situação Crítica' },
    { nivel: 2, pontuacaoMinima: 25, nome: 'Cidade em Recuperação',  situacao: 'Em Recuperação' },
    { nivel: 3, pontuacaoMinima: 50, nome: 'Cidade Sustentável',     situacao: 'Situação Sustentável' },
    { nivel: 4, pontuacaoMinima: 75, nome: 'Cidade Modelo',          situacao: 'Referência de Preservação' },
];

/** Estágio (1–4) correspondente a uma pontuação 0–100. */
export function estagioPorPontuacao(pontos) {
    let estagio = 1;
    NIVEIS_CIDADE.forEach((n) => { if (pontos >= n.pontuacaoMinima) estagio = n.nivel; });
    return estagio;
}

/* Faixas de XP do jogador vêm do banco (nivel_jogador), via jogo.php. */


