# EcoCity — Roteiro para gerar as imagens das regiões no ChatGPT

> Objetivo: gerar as 16 imagens que faltam (4 regiões × 4 estágios) no **mesmo estilo** das imagens do parque
> (`assets/img/locais/parque-estagio-1..4.png`). Salvar como `assets/img/locais/{prefixo}-estagio-{n}.png`.
> Prefixos: `reciclagem`, `lago`, `energia`, `mobilidade`.

---

## 1. O que as imagens do parque têm em comum (análise)

**Formato e enquadramento (igual nos 4 estágios)**
- Paisagem **16:9** (originais com 2752×1536). O painel do jogo corta em 16:9, então a cena não pode encostar nas bordas.
- **Fundo cinza muito claro e liso** (#E9ECEC), sem textura.
- Atrás do terreno, um **retângulo de céu** que não ocupa a largura toda: começa a ~16% da esquerda, termina a ~16% da direita e vai do topo até ~metade da altura. Tem nuvens arredondadas e **silhuetas de prédios da cidade** em tom claro, como se fosse um cenário de fundo.
- No centro, um **bloco de terreno isométrico flutuante** em forma de losango, visto de cima a ~30° (isometria clássica de jogo). Ponta de baixo no centro inferior da imagem, pontas laterais quase nas bordas.
- O bloco tem **espessura visível só nas duas faces da frente**: uma faixa fina de terra marrom com contorno escuro.
- Elementos altos (árvores, postes) passam um pouco por cima do céu e das bordas do bloco.

**Traço e pintura**
- Ilustração **cartoon vetorial** com **contorno escuro grosso** (marrom-escuro ou cinza-escuro, não preto puro) em todos os objetos.
- Pintura **chapada** (cel shading) com uma ou duas sombras simples por objeto, sem texturas realistas, sem fotografia e sem 3D renderizado.
- Muitos detalhes pequenos (grama em tufos, pedrinhas, flores, lixo), mas tudo legível.
- **Sem pessoas, sem textos, sem logos e sem marca d'água.**

**Composição fixa entre os estágios (essencial)**
Os **mesmos objetos ficam no mesmo lugar** nos 4 estágios; muda só a **condição** deles. No parque: as árvores nos mesmos pontos (secas → com brotos → verdes → frondosas), o chafariz rachado que vira um laguinho com pedras, o mesmo escorregador, balanço e bancos, o mesmo caminho de pedras na diagonal direita e a mesma cerca em volta.

**Evolução de cores por estágio**

| Estágio | Clima geral | Céu | Solo e vegetação | Objetos |
|---|---|---|---|---|
| 1 — Degradado | Dessaturado, cinza-oliva, sujo | Cinza-esverdeado de poluição, prédios cinza | Terra escura rachada, grama amarelada e morta, árvores secas sem folhas | Enferrujados, quebrados, lixo espalhado (papéis, garrafas, sacolas) |
| 2 — Em recuperação | Tons quentes, ainda pálido | Azul claro com nuvens brancas, prédios azul-acinzentados | Terra marrom-alaranjada, grama verde-amarelada em manchas, árvores com os primeiros brotos | Ainda velhos, mas sem lixo; algum conserto (pintura nova) |
| 3 — Sustentável | Vivo e limpo | Azul forte, prédios azuis com jardins no topo | Grama verde viva, árvores com copa cheia, flores | Novos ou bem cuidados; primeiros elementos ecológicos (lixeiras de reciclagem, poste solar, casinha de insetos) |
| 4 — Referência | Exuberante, saturado, luminoso | Azul com raios de sol, prédios com telhados verdes | Vegetação densa, muitas flores, borboletas, água cristalina | Tudo do estágio 3 mais elementos extras (ponte, casinhas de pássaros, estação meteorológica solar) |

---

## 2. Como usar no ChatGPT (passo a passo)

1. **Abra uma conversa nova para cada região** e **anexe duas imagens do parque** como referência de estilo: `parque-estagio-1.png` e `parque-estagio-4.png`.
2. Cole o **Bloco A (estilo)** e, em seguida, o **prompt do estágio 4** da região. Comece pelo estágio mais completo: todos os objetos aparecem nele.
3. Gerada a imagem do estágio 4 e aprovada, **peça os outros estágios como edição dessa imagem** com o **Bloco B (edição)**, um estágio por vez (3, depois 2, depois 1). Se possível, anexe de novo a imagem do estágio 4 em cada pedido. Assim o ChatGPT mantém a mesma câmera e os mesmos objetos no lugar.
4. Peça sempre **"formato paisagem 16:9"**. Se o ChatGPT só oferecer 3:2 (1536×1024), aceite: o jogo corta para 16:9, desde que a cena fique centralizada com margem.
5. Se ele mudar o estilo (realista, 3D, sem contorno), responda: *"Refaça mantendo exatamente o estilo das imagens de referência: cartoon com contorno escuro grosso, cores chapadas, bloco isométrico flutuante sobre fundo cinza claro."*
6. Se ele mudar a posição dos objetos entre os estágios, responda: *"Mantenha a composição idêntica à imagem anexada: mesma câmera, mesmo bloco, mesmos objetos nos mesmos lugares. Mude apenas a condição descrita."*
7. Salve com o nome certo (ex.: `lago-estagio-3.png`) em `assets/img/locais/`.

---

## 3. Bloco A — estilo (colar no início de cada região)

```
Vou te pedir 4 imagens de uma mesma cena de um jogo educativo de cidade sustentável. Cada imagem mostra a mesma região da cidade em um nível ambiental diferente, do 1 (degradado) ao 4 (exemplar). As 4 precisam ter EXATAMENTE a mesma composição, câmera e objetos nos mesmos lugares; só muda a condição do ambiente.

Use as imagens anexadas como referência obrigatória de estilo:
- ilustração cartoon vetorial, contorno escuro grosso (marrom/cinza-escuro) em tudo, cores chapadas com sombra simples, sem realismo, sem 3D renderizado;
- formato paisagem 16:9, fundo cinza muito claro e liso;
- no centro, um bloco de terreno isométrico flutuante em forma de losango, visto de cima a uns 30°, com uma faixa fina de terra marrom nas duas faces da frente;
- atrás do bloco, um retângulo de céu que não ocupa a largura toda (margem cinza dos dois lados), com nuvens arredondadas e silhuetas de prédios da cidade em tom claro;
- muitos detalhes pequenos e legíveis; sem pessoas, sem textos, sem letras, sem logos.
```

## 4. Bloco B — edição para os outros estágios

```
Agora gere o ESTÁGIO {N} editando a imagem anexada. Mantenha idênticos: o estilo, o enquadramento 16:9, a câmera, o bloco isométrico, o retângulo de céu e a posição de TODOS os objetos. Mude apenas a condição do ambiente para:
{cole aqui a descrição do estágio}
```

---

## 5. Prompts por região

### 5.1 Reciclagem — "Bairro & Gestão de Resíduos" (`reciclagem-estagio-N.png`)

**Cena fixa (vale para os 4):** um pedaço de bairro residencial no bloco isométrico. Três casinhas de dois andares no fundo, um prédio baixo de apartamentos à direita, uma rua estreita na diagonal da frente, calçada, um terreno no canto esquerdo, uma área de descarte/coleta no centro (caçamba ou contêineres) e um galpão pequeno no canto direito, à frente do prédio.

- **Estágio 4:**
```
ESTÁGIO 4 (referência): bairro modelo de reciclagem. Casas com telhados verdes e painéis solares, fachadas coloridas e limpas. No centro, um ponto de coleta seletiva com contêineres coloridos (azul papel, vermelho plástico, verde vidro, amarelo metal, marrom orgânico) com símbolo de reciclagem. O galpão vira um centro de reciclagem moderno com fardos de papelão e garrafas prensadas. No terreno da esquerda, composteira e horta comunitária. Árvores e canteiros floridos nas calçadas, rua limpa, céu azul com raios de sol, prédios ao fundo com jardins nos telhados.
```
- **Estágio 3:**
```
Bairro limpo e cuidado: casas pintadas, alguns telhados verdes. No centro, contêineres de coleta seletiva coloridos (sem tanto destaque quanto no estágio 4). O galpão é um centro de reciclagem simples. No terreno da esquerda, uma composteira começando. Grama verde, algumas árvores jovens, rua limpa, céu azul com nuvens brancas.
```
- **Estágio 2:**
```
Bairro em recuperação: casas com pintura desbotada, sem telhados verdes. No centro, uma caçamba comum quase cheia, mas com o lixo dentro dela, e uma lixeira de reciclagem nova ao lado. Poucos sacos de lixo na calçada. O galpão está fechado e velho. Terreno da esquerda de terra com mato baixo. Grama verde-amarelada em manchas, árvores com poucos brotos, céu azul claro e pálido.
```
- **Estágio 1:**
```
Bairro degradado: casas sujas e desbotadas, janelas quebradas. No centro, uma caçamba enferrujada transbordando, com montanhas de sacos de lixo, garrafas PET, papelão e entulho espalhados pela rua e pela calçada. O terreno da esquerda é um lixão a céu aberto com pneus velhos. O galpão está abandonado. Moscas sobre o lixo, grama seca e amarelada, árvores secas, céu cinza-esverdeado de poluição e prédios cinza ao fundo. Cores dessaturadas.
```

### 5.2 Água — "Lago Urbano & Recursos Hídricos" (`lago-estagio-N.png`)

**Cena fixa:** um lago urbano grande ocupando o centro e a esquerda do bloco, com margens irregulares. Um deque de madeira entrando no lago pela direita, um caminho de pedras contornando a margem da frente, um cano de concreto saindo da margem do fundo, juncos e árvores nas margens, dois bancos virados para o lago.

- **Estágio 4:**
```
ESTÁGIO 4 (referência): lago cristalino azul-turquesa com vitórias-régias floridas, peixinhos visíveis, uma família de patos e uma garça. O cano do fundo virou uma pequena queda d'água limpa com plantas. Deque de madeira novo, margens com juncos, flores e pedras arredondadas, árvores frondosas, bancos novos, uma placa de reúso de água (sem texto, só um ícone de gota) e uma cisterna de captação de chuva ao lado do deque. Borboletas e libélulas. Céu azul com raios de sol, prédios com telhados verdes ao fundo.
```
- **Estágio 3:**
```
Lago limpo, azul, com algumas vitórias-régias e um ou dois patos. O cano solta um filete de água limpa. Deque em bom estado, margens verdes com juncos, árvores com copa cheia, bancos inteiros. Céu azul com nuvens brancas.
```
- **Estágio 2:**
```
Lago turvo, verde-acinzentado, sem lixo boiando, mas com algumas manchas de espuma perto do cano. Deque velho com tábuas faltando. Margens com terra exposta e pouca grama, árvores com poucos brotos. Céu azul claro e pálido.
```
- **Estágio 1:**
```
Lago poluído de água marrom-escura e espumosa, com garrafas, sacolas plásticas, pneu e latas boiando. O cano do fundo despeja esgoto escuro. Peixes mortos na margem, deque quebrado e podre, margens de lama rachada com lixo, árvores secas, bancos quebrados. Céu cinza-esverdeado de poluição e prédios cinza ao fundo. Cores dessaturadas.
```

### 5.3 Energia — "Atmosfera & Matriz Energética" (`energia-estagio-N.png`)

**Cena fixa:** um complexo de energia no bloco. Um prédio industrial grande no fundo-centro com duas chaminés altas, um pátio na frente, um terreno amplo no lado esquerdo, uma colina suave no lado direito, uma linha de torres de transmissão saindo para a direita e uma cerca em volta.

- **Estágio 4:**
```
ESTÁGIO 4 (referência): usina de energia limpa. O prédio industrial tem teto verde coberto de plantas e painéis solares; as chaminés foram desativadas e cobertas de trepadeiras (sem fumaça). No terreno da esquerda, fileiras de painéis solares brilhando. Na colina da direita, três turbinas eólicas brancas. Bateria/estação de armazenamento moderna no pátio, árvores e flores em volta, postes solares. Ar limpo, céu azul intenso com raios de sol, prédios ao fundo com telhados verdes e painéis solares.
```
- **Estágio 3:**
```
Usina em transição limpa: chaminés apagadas, sem fumaça. Alguns painéis solares no terreno da esquerda e uma turbina eólica na colina. Teto do prédio com alguns painéis. Grama verde, árvores, céu azul com nuvens brancas.
```
- **Estágio 2:**
```
Fábrica em recuperação: só uma chaminé solta fumaça cinza-clara e fraca. No terreno da esquerda, base de concreto com os primeiros dois painéis solares sendo instalados. Colina com grama rala, árvores com poucos brotos. Céu azul claro, levemente enevoado.
```
- **Estágio 1:**
```
Fábrica poluidora de carvão: as duas chaminés soltam fumaça preta e grossa que escurece o céu. Pilhas de carvão no terreno da esquerda, manchas de óleo no pátio, barris enferrujados, colina de terra seca, árvores secas, fios caídos nas torres. Névoa cinza de poluição sobre tudo, céu cinza-amarronzado e prédios cinza ao fundo. Cores dessaturadas.
```

### 5.4 Mobilidade — "Vias & Mobilidade Urbana" (`mobilidade-estagio-N.png`)

**Cena fixa:** um cruzamento de duas avenidas que se cruzam no centro do bloco, em X seguindo as diagonais do losango. Calçadas largas nos quatro cantos, um ponto de ônibus em um canto, faixas de pedestres nas quatro entradas do cruzamento, um semáforo e uma praça triangular pequena em um dos cantos.

- **Estágio 4:**
```
ESTÁGIO 4 (referência): cruzamento modelo de mobilidade sustentável. Ônibus elétrico verde parado no ponto (com cobertura verde e painel solar), ciclovias verdes pintadas ao lado das avenidas com várias bicicletas e uma estação de bicicletas compartilhadas, patinetes, faixas de pedestres bem pintadas, calçadas largas e arborizadas com canteiros floridos, poucos carros e todos elétricos (sem fumaça). Postes solares, semáforo, praça com árvores frondosas. Céu azul com raios de sol, prédios com telhados verdes ao fundo.
```
- **Estágio 3:**
```
Cruzamento organizado: ônibus elétrico no ponto, ciclovia verde em uma das avenidas com duas bicicletas, poucos carros, sem fumaça, faixas de pedestres pintadas, árvores com copa cheia nas calçadas. Céu azul com nuvens brancas.
```
- **Estágio 2:**
```
Cruzamento em recuperação: trânsito moderado de carros comuns, um ônibus laranja a diesel soltando um pouco de fumaça clara, faixas de pedestres desbotadas, ciclovia começando a ser pintada em um trecho, calçadas com poucas árvores jovens. Céu azul claro e pálido.
```
- **Estágio 1:**
```
Cruzamento caótico: engarrafamento de carros velhos e cinzentos soltando fumaça escura, buzinas (sem texto), asfalto esburacado com poças, faixas de pedestres apagadas, semáforo quebrado, ponto de ônibus destruído, calçadas estreitas com lixo, árvores secas. Névoa cinza de poluição, céu cinza-amarronzado e prédios cinza ao fundo. Cores dessaturadas.
```

---

## 6. Conferência antes de salvar
- Mesmo enquadramento nos 4 estágios? Sobrepondo uma imagem na outra, os objetos estão nos mesmos lugares.
- Fundo cinza claro, retângulo de céu com margens e bloco flutuante com terra marrom nas faces da frente?
- Contorno escuro e cores chapadas, como no parque?
- Sem pessoas, textos, letras ou logos?
- Nome do arquivo certo (`{prefixo}-estagio-{n}.png`) e salvo em `assets/img/locais/`.
- Depois de salvar, as imagens ainda precisam ser otimizadas (pendência de peso das imagens: hoje ~3 MB cada).
