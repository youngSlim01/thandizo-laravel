# Thandizo — decisões sobre o funcionamento do sistema

Registo das decisões tomadas até agora: o que o sistema faz, porquê, e o que
falta decidir antes de o Thandizo servir novos clientes. Cada decisão tem a
razão ao lado, para quem vier depois perceber o contexto e não a desfazer por
engano.

A migração técnica (Next.js + Supabase → Laravel + MySQL) está em
[`MIGRACAO_LARAVEL.md`](MIGRACAO_LARAVEL.md). Este documento fala do sistema
e do produto.

Última actualização: 8 de Novembro de 2026 (escalões do voucher, CODIGO AF gerado e painel MEAL por ciclo).

---

## 1. Produto e negócio

| Decisão | Razão |
|---|---|
| O Thandizo é uma solução própria de **Gil Romão**. O projecto LINK GCF (Save the Children) usa-a em **piloto**. | Confirmado com a directora do projecto, por escrito (email). |
| Modelo de negócio: **software com operação** (formação, cartões, apoio no terreno), não só software. | O conhecimento de como correr uma distribuição é difícil de copiar; é isso que as organizações compram. |
| Até haver um segundo cliente: **uma instalação por cliente**. | Só com o segundo cliente se percebe o que realmente varia entre organizações (ver secção 11). |
| Nome do produto: **Thandizo** (só a primeira letra maiúscula), com a frase **"Empowering programmes. Improving lives."** | Igual ao logótipo. |
| Versão mostrada no sistema: **v1.0**. | Primeira versão do novo sistema. |

## 2. Arquitectura e tecnologia

| Decisão | Razão |
|---|---|
| **Laravel 13 + Inertia + React + MySQL**. | A equipa domina PHP; tecnologia standard, outros programadores conseguem manter. |
| As regras de negócio vivem em **`app/Services`**, nunca nos controllers nem no React. | Um só sítio para as regras; o ecrã só mostra e recolhe dados. |
| Todos os ecrãs são **páginas Inertia** (`resources/js/pages`). Os ecrãs copiados do sistema antigo e os endpoints `/rpc` foram retirados. | Código limpo antes de passar para `main`. |
| Ids em **UUID**. | Identificadores que não revelam volumes nem se adivinham. |
| Nomes de lugares comparam-se **sem diferenciar maiúsculas nem acentos** (collation `utf8mb4_unicode_ci`). | "Chókwè" e "chokwe" são o mesmo distrito. |
| Mensagens das regras em **`lang/pt/rules.php`**; o servidor responde já em português. | Uma só lista de mensagens, para ecrãs e pedidos JSON. |
| Testes em **MySQL** (`thandizo_test`), nunca em SQLite. | Os triggers e as regras da base de dados só existem em MySQL. |
| Alojamento previsto: **Laravel Cloud**, com MySQL gerido na mesma região. | Menos servidores para gerir. Ver pendentes (secção 10). |

## 3. Dados e arranque

| Decisão | Razão |
|---|---|
| **Os dados do sistema antigo não são migrados**; passa só a estrutura. Os dados são repostos nos testes com utilizadores reais. | Decisão do projecto; evita um comando de migração arriscado sobre saldos. |
| Toda a **comunidade tem localidade** (obrigatória). | Não existem comunidades sem localidade. |
| O **nome do produto é único**. | Não existem produtos com nomes repetidos. |
| **Dados de demonstração** (`php artisan db:seed --class=DemoSeeder`): utilizadores de cada papel, produtos com preço, ciclo aberto, 3 escalões (7 500 / 6 000 / 3 000 MT), 20 agregados com cartão, 5 vendas do mês anterior, cliente e logótipos de exemplo. | Experimentar todos os fluxos no browser, incluindo reconciliações. |

## 4. Regras do dinheiro

O sistema guarda dinheiro de beneficiários: estas regras não se negoceiam no
ecrã, são garantidas pelos Services e, as mais críticas, pela base de dados.

**Protecção do histórico**
- O **ledger**, os **itens** das vendas e as **transacções** nunca se alteram nem se apagam: a base de dados recusa (triggers MySQL), mesmo a quem tem acesso directo.
- Saldo do cartão **nunca negativo** (`CHECK` na base de dados).
- Correcções fazem-se sempre com **movimentos inversos** (ex.: anulação), e os dois movimentos ficam visíveis para auditoria.
- Os triggers exigem `log_bin_trust_function_creators=1` no MySQL.

**Venda no POS**
- O **preço** vem da lista do **distrito do comerciante**, e só se vendem produtos do **catálogo** desse comerciante.
- **Desconto**: percentagem (menos de 100%) ou valor fixo (menos do que o subtotal).
- **Copagamento** (o que o beneficiário paga em dinheiro): no máximo **20% do total**. O cartão é sempre a parte principal da compra; sem saldo não há compra.
- O operador **pré-visualiza** os valores, mostra-os ao beneficiário e **confirma com os mesmos valores**; se algo mudou entretanto (preço, saldo), a venda é recusada (`PREVIEW_OUTDATED`) e recalculada.
- Cada venda tem uma **chave única**: repetir o pedido (por falha de rede) nunca debita duas vezes.
- **Anulação**: até **15 minutos**, só pelo operador que fez a venda; o saldo volta ao cartão e o copagamento é devolvido ao beneficiário.
- O código do cartão tem um **dígito de controlo (Luhn)** que apanha erros de digitação antes da consulta.

**Cartões**
- Um **lote por bairro** (`LOTE-<DIS>-NNNN`), um cartão por agregado activo sem cartão. Código do cartão: `VCH-<DIS>-NNNNNN-<dígito>`, com QR.
- O cartão nasce **por distribuir, sem saldo**. Só na **entrega em mão** fica activo com o **valor do escalão** do agregado nesse momento, e só com um **ciclo aberto**.
- Na ausência do representante, o cartão pode ser entregue a um **substituto**: o nome e o parentesco ficam no cartão; os dados do agregado não mudam.
- **Cartão perdido**: é cancelado e emitido um novo com o saldo que restava (gestor MEAL ou administrador).
- **Cancelar ou fechar um lote**: gestor MEAL ou administrador; os cartões não entregues são cancelados.

**Ciclos**
- **Um ciclo aberto de cada vez** (`CIC-NN`).
- Cada ciclo tem um **nome que o identifica** (ex.: "AA - Janela 1") e um **âmbito** opcional (ex.: "Acções antecipadas à seca, Out–Dez"), definidos ao abrir e **editáveis** pelo gestor MEAL e pelo administrador. O código `CIC-NN` é interno e não muda (numeração, confirmação do fecho). Num ciclo fechado mudam só o nome e o âmbito; as datas ficam como foram. Cada alteração fica na auditoria. *(8/11/2026: o programa passa a operar por janelas de acções antecipadas.)*
- **Recarga**: o **valor do escalão** de cada agregado, no **mesmo cartão**, para os agregados escolhidos (por zona ou lista de CODIGO AF). A pré-visualização mostra quanto vai a cada escalão. O ciclo guarda o escalão em que cada agregado estava quando recebeu, para os relatórios.
- **Fecho**: o saldo que sobra **expira** e fica no relatório como não utilizado. Não se fecha nos **15 minutos** a seguir a uma venda (ainda pode ser anulada).

**Escalões do voucher** (decidido a 8/11/2026)

Os beneficiários recebem valores diferentes (ex.: 7 500, 6 000 ou 3 000 MT) consoante a vulnerabilidade e a categoria do grupo.

| Decisão | Razão |
|---|---|
| O valor mora num **escalão** (nome, valor, descrição); cada agregado pertence a um escalão. Não há valor por agregado nem por ciclo. | Mudar o valor de um grupo é uma só alteração, não centenas; os relatórios por escalão saem directamente. |
| Os escalões são geridos pelo **gestor MEAL e pelo administrador do sistema**, na Configuração. | Decisão do projecto: são regras do programa. |
| O valor **congela-se no momento** da activação ou da recarga (fica no ledger e no cartão). Mudar o valor de um escalão, ou o escalão de um agregado, só conta para as **próximas** activações e recargas. | O dinheiro já entregue nunca muda por uma alteração de configuração. |
| Na **importação**, a coluna opcional **VALOR** escolhe o escalão activo com esse valor; sem a coluna, ou com a célula vazia, o agregado fica no **escalão padrão**. Um valor sem escalão activo é um **erro**: nada é gravado. | Os valores vêm da fonte de dados (lista do MEAL). O escalão cria-se primeiro na Configuração, para que um engano no ficheiro (750 em vez de 7500) nunca crie dinheiro. |
| **Dois escalões activos não podem ter o mesmo valor.** | A importação encontra o escalão pelo valor; dois escalões com o mesmo valor tornariam isso ambíguo. Confirmado: no programa, grupos diferentes nunca recebem o mesmo valor, por isso a divisão fica só por valor. |
| Os escalões **nunca se apagam**, desactivam-se, e só quando já não têm agregados activos. Há sempre um **escalão padrão** activo (de início "Padrão", 6 000 MT), que não pode ser desactivado. | Os relatórios antigos continuam a apontar para o escalão; nenhum agregado fica sem valor. |
| A mudança de escalão de um agregado faz-se em "Editar dados", com motivo, e fica no histórico (escalão antigo → novo). | Auditoria igual à dos outros dados do agregado. |
| O cartão impresso **não mostra o valor**. | O cartão serve vários ciclos e o escalão pode mudar. |

**CODIGO AF gerado pelo sistema** (decidido a 8/11/2026)

No programa, todos os agregados do escalão de 6 000 MT têm CODIGO AF; nos outros escalões alguns não têm.

| Decisão | Razão |
|---|---|
| Cada escalão tem a opção **"Exige CODIGO AF"** (o Padrão exige; os outros escalões podem não exigir). | A regra acompanha o escalão e não um valor escrito no código: se o padrão passar de 6 000 para 6 500 MT, continua a funcionar. |
| Num escalão que não exige CODIGO AF, o agregado sem código recebe um **gerado pelo sistema**, com **formato próprio** `TZ-<DIS>-NNNNNN` (ex.: `TZ-MUT-000123`), numeração única para todo o programa. | Nunca se confunde nem colide com um código oficial (`1234AF000001`). |
| O código gerado é **definitivo**: nunca é substituído pelo oficial. Fica marcado como gerado (aparece na ficha do agregado). | Decisão do projecto: o CODIGO AF identifica o agregado para sempre. |
| **Registo manual**: a caixa "Não tem CODIGO AF" só aparece nos escalões que não o exigem. Sem ela, o campo vazio é erro. | Um registo apressado não cria um código definitivo para quem já tinha um. |
| **Importação**: a célula CODIGO AF pode ficar vazia só nos escalões que não o exigem. A pré-visualização mostra quantos códigos vão ser gerados (por escalão) e pede confirmação; no fim, descarrega-se a lista dos códigos gerados (linha, código, representante). | O MEAL confirma antes de gravar e actualiza o seu ficheiro, para a próxima importação reconhecer os agregados (um código gerado reimportado só é aceite se já existir). |
| **Aviso de possível duplicado** na pré-visualização: uma linha sem CODIGO AF com o mesmo nome, apelido, data de nascimento e bairro de um agregado já registado (ou de outra linha do ficheiro). É um **aviso, não um erro**: o MEAL decide, e o botão passa a "Importar mesmo assim". | Se a lista for reimportada sem os códigos gerados, a mesma pessoa ficaria registada duas vezes, com dois códigos definitivos. Não é erro porque duas pessoas diferentes podem ter os mesmos dados. |

**Reconciliação (pagamento ao comerciante)**
- Só **dias já fechados** (até ontem, hora de Moçambique) e só vendas confirmadas ainda não reconciliadas.
- Circuito: **oficial MEAL** cria e envia → **gestor MEAL** aprova ou devolve → **Finanças** validam ou devolvem. Ninguém valida sozinho.
- Uma reconciliação criada pelo gestor MEAL segue directamente para as Finanças.
- Devolver **liberta as vendas** para uma nova reconciliação.
- Paga-se ao comerciante a **parte do voucher**; o copagamento já foi recebido por ele ao balcão.

**Painel MEAL** (decidido a 8/11/2026)

| Decisão | Razão |
|---|---|
| O painel é para o **dia-a-dia do MEAL** e organiza-se **por ciclo** (o aberto por omissão; escolhe-se outro no topo), não por mês. | O programa funciona por ciclos; a pergunta diária é "o que faço hoje?". |
| Sem comparações com o mês anterior. O **ritmo** compara o valor usado com o **tempo do ciclo** (dia N de M) e, no gráfico, com o **ciclo anterior nos mesmos dias**. | As comparações mensais davam "↑100%" quando o mês anterior era zero; uma linha recta até ao fecho engana quando as compras se concentram no início. |
| **Alertas** (cartões impressos por entregar, reconciliações devolvidas, ciclo a fechar em 7 dias com saldo) e **Atenção** (quem não comprou, bairros com 30% ou mais sem compras, agregados sem cartão, lotes por imprimir, reconciliações à espera do gestor); cada linha leva ao ecrã onde se resolve. | Mostrar o que precisa de acção em vez de só números. |
| Utilização **por escalão** (com estado "no ritmo" / "atrasado"), por sexo, cobertura no mapa, quem não comprou por bairro, últimas actividades e o que falta pagar a cada comerciante (por reconciliar, em aprovação, validado). | Indicadores pedidos para acompanhar o ciclo. |
| **Produtos** saem do painel (ficam no relatório do ciclo). | Sem categoria preenchida, o gráfico mostrava "Outros 100%". |
| O **Oficial MEAL** vê o mesmo painel só com a sua província. | Âmbito igual ao resto do back office. |
| Desenho a partir das referências dadas (cartões de indicadores com ícone, painéis de alerta, tabelas com barra e estado, demografia). | Pedido do projecto. |

## 5. Papéis e permissões

Dois perfis de administração, com papéis distintos:

| Papel | O que faz |
|---|---|
| **Administrador do sistema** (administrador técnico) | Acesso global: tudo, incluindo utilizadores, Configuração e o **nome da organização**. Entra no **painel técnico** (utilizadores, actividade, saúde do sistema) e o menu mostra **todas as secções** (decidido a 8/11/2026: é quem resolve problemas em qualquer área). |
| **Gestor MEAL** (administrador operacional) | Back office completo, utilizadores, aprovar reconciliações, gerir ciclos, cancelar lotes, substituir cartões; em **Configuração**: programa, frase do cliente, escalões do voucher e logótipos. |
| **Oficial MEAL** | Back office **só da sua província** (beneficiários, cartões, comerciantes, vendas, reconciliações). Sem província não vê nada. |
| **Oficial de Finanças** | Painel, validar ou devolver reconciliações, transacções, relatórios; vê **produtos e preços sem alterar**. |
| **Gestor de comerciante** | POS, vendas e o **catálogo** do seu comerciante. |
| **Operador de comerciante** | POS e as suas vendas. |

- Ninguém altera o seu próprio acesso nem repõe a sua própria palavra-passe pelo ecrã de Utilizadores.
- **Primeiro administrador de uma instalação**: criado com `php artisan thandizo:create-admin` (nome, e-mail, nome de utilizador). O comando gera uma palavra-passe temporária forte, mostrada uma só vez, a trocar no primeiro acesso, e regista a criação na auditoria. Razão: em produção nunca se usa uma conta conhecida.
- A conta `admin` / `password` dos seeders é **só para desenvolvimento** e nunca deve existir em produção.
- Toda a alteração de acessos, cliente, escalões e logótipos fica na **auditoria** (quem, quando, antes e depois).

## 6. POS

- O **POS escolhido** fica na **sessão**; com um só POS activo no comerciante, é esse. Quem tem vários escolhe ao entrar.
- A venda faz-se em **6 passos**: voucher → produtos → carrinho → desconto → pagamento → confirmação (com recibo).
- Hoje o POS **precisa de internet** (como o sistema antigo).

## 7. Marca e cliente da instalação

- O produto chama-se sempre **Thandizo**; o **cliente** aparece **junto** do nome: cabeçalhos, recibo, cartão, PDFs, email, rodapé do login.
- Dados do cliente em **Configuração** (base de dados, não `.env`): **organização**, **programa** (um só campo, por agora) e **frase do cliente** (opcional).
- **Logótipos do cartão**: até **3**, PNG/JPG/WebP até 1 MB, carregados em Configuração, impressos da esquerda para a direita. Os cartões já impressos não mudam.
- **Quem muda o quê**: o gestor MEAL muda programa, frase e logótipos; o **nome da organização só o administrador do sistema** (é a identidade do cliente).
- Sem cliente configurado, só aparece Thandizo com a frase do produto.
- Logótipo do produto em vector (`ThandizoMark`), igual em todo o sistema; favicon e ícone de telemóvel gerados do mesmo desenho.

**Identidade visual única** (8/11/2026)

| Decisão | Razão |
|---|---|
| Um só conjunto de cores, letra e medidas para todo o sistema (website, entrada, back office, Finanças, painel técnico e POS), definido nas variáveis de `resources/css/app.css` (`:root`). Os ecrãs não escrevem cores à mão. | O Thandizo passa a ter identidade própria; mudar a marca é mudar um sítio. |
| Verde da marca = o do logótipo (`#007716`), com uma versão escura, uma clara e o verde-folha (`#7EDB33`) só como acento. Estados: verde (feito), âmbar (atenção), vermelho (erro), azul (informação). | Seis verdes diferentes passaram a um. |
| Letra **Montserrat** em todo o sistema, carregada uma vez. | Já era a do website e da entrada; agora é de todos os ecrãs. |
| Sem emojis; símbolos sóbrios de uma cor. | Aspecto profissional e igual em todos os aparelhos. |
| Três experiências: website (apresentação), entrada (acesso) e área de cada perfil. O website pode sair para `thandizo.org` quando houver mais clientes; cada cliente terá a sua instalação. | Separação decidida; a separação física fica para o segundo cliente. |

## 8. Termos e linguagem

- Interface em **português (PT/Moçambique)**.
- **"Comerciante"** em vez de "agrodealer" (recomendação de um especialista em *cash and voucher assistance*). Códigos **`MER-<DIS>-NNN`**; página em `/admin/comerciantes`; "Rede de comerciantes".
- Os nomes internos do código (tabelas `agrodealers`, classes `Agrodealer`, papéis `AGRODEALER_*`) **mantêm-se**: ninguém os vê e mudá-los traria risco sem ganho.

## 9. Entrada no sistema

- Ecrã de entrada segundo o desenho aprovado: foto da comunidade, logótipo e frase à esquerda; formulário à direita; letra **Montserrat** (servida pelo próprio sistema).
- Entra-se com **nome de utilizador ou e-mail**. O nome de utilizador é opcional, único, em minúsculas (letras, números, `.`, `_`, `-`) e nunca tem `@`.
- "Esqueceu a sua palavra-passe?" aceita nome de utilizador ou e-mail e envia o link **para o e-mail da conta**; a resposta é igual exista ou não a conta.
- Sem caixa "manter sessão": a sessão fica sempre lembrada no aparelho.
- Contas novas recebem uma **palavra-passe temporária** e têm de escolher outra no primeiro acesso.
- O **e-mail continua obrigatório** em todas as contas (a recuperação depende dele).

**Página pública** (8/11/2026)
- Em `/`, quem não tem sessão vê a apresentação do Thandizo; quem tem sessão vai directo para a sua área. Razão: quem quer conhecer a solução tem onde a ver sem conta.
- Conteúdo (8/11/2026): o Thandizo apresenta-se como plataforma de **gestão de programas, assistência e monitoria de impacto**, não só de vouchers. Secções: início, sobre (propósito, solução, para quem), funcionalidades (beneficiários, vouchers, programas, monitoria, transações, reconciliação, administração, segurança), como funciona (planear, implementar, acompanhar, reportar), perfis (administrador, gestor de programa, MEAL, finanças, comerciante) e impacto (inclusão, eficiência, transparência, impacto).
- Os textos descrevem só o que o sistema faz hoje: não prometem funcionamento sem rede nem medição da produção agrícola. Os números do painel na ilustração são de exemplo.
- O cliente aparece discretamente no rodapé ("Em uso: …"), como no ecrã de entrada.

## 10. Pendentes antes da produção

| Pendente | Notas |
|---|---|
| **Laravel Cloud**: confirmar que o MySQL gerido aceita `log_bin_trust_function_creators` | Necessário para os triggers. Testar num ambiente `staging` a partir deste branch. |
| **Fornecedor de e-mail** | Para a recuperação da palavra-passe. |
| **Monitorização no painel técnico** | Sincronização, serviços, backups, saúde da aplicação e histórico de utilização ficam "N/D" / "Não monitorizado" até ao deploy; ligam-se à monitorização do alojamento (Laravel Cloud). Nunca mostrar números inventados. (8/11/2026) |
| **Logótipo original em vector** | Substituir o desenho actual pelo ficheiro do designer. |
| **Deck de apresentação** | Actualizar com o novo logótipo, a nova foto e "comerciante". |
| **Administrador de produção** | Criar com `php artisan thandizo:create-admin` no ambiente do Laravel Cloud; não correr os seeders em produção. |
| **Passar para `main`** | Quando o sistema estiver terminado, com código limpo. |
| **Testes com utilizadores reais** | MEAL, comerciantes e Finanças, repondo os dados. |

## 11. Caminho até aos novos clientes

**Já decidido**
- Primeiro cliente (LINK GCF): instalação própria, configurada pelo ecrã de Configuração.
- O **núcleo** do produto não é configurável: ledger imutável, POS, anulação por movimento inverso, reconciliação com aprovação, auditoria.
- O **valor do voucher** já é configurável, por escalões (ver Regras do dinheiro).
- O que varia entre clientes vai para **configuração**, começando pelos parâmetros hoje fixos no código: limite de copagamento (20%), janela de anulação (15 min), moeda (MT), fuso (Maputo), língua.

**Por decidir (com o segundo cliente à vista)**
- **Como separar os clientes**: uma base de dados por cliente (isolamento forte, preferido pelo tipo de dados) ou uma base partilhada.
- **O que realmente varia** entre organizações (valor por tamanho do agregado, vários saldos, categorias restritas, níveis de aprovação, divisão geográfica): conversar com 3 a 5 organizações antes de desenhar.
- **Painel de registo de clientes, subdomínios e página de apresentação** do Thandizo: vêm por último.
- **Consola do operador** (para a equipa Thandizo acompanhar cada cliente sem entrar nas contas).
- **Preço**: componente de arranque por programa (formação, cartões, primeira distribuição) e componente recorrente por beneficiário ou por ciclo.
- **Contas sem e-mail** para operadores de comerciante.
- **POS sem internet**, se o terreno o exigir.
