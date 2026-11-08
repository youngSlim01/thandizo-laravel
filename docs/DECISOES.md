# Thandizo — decisões sobre o funcionamento do sistema

Registo das decisões tomadas até agora: o que o sistema faz, porquê, e o que
falta decidir antes de o Thandizo servir novos clientes. Cada decisão tem a
razão ao lado, para quem vier depois perceber o contexto e não a desfazer por
engano.

A migração técnica (Next.js + Supabase → Laravel + MySQL) está em
[`MIGRACAO_LARAVEL.md`](MIGRACAO_LARAVEL.md). Este documento fala do sistema
e do produto.

Última actualização: Novembro de 2026.

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
| **Dados de demonstração** (`php artisan db:seed --class=DemoSeeder`): utilizadores de cada papel, produtos com preço, ciclo aberto, 20 agregados com cartão, 5 vendas do mês anterior, cliente e logótipos de exemplo. | Experimentar todos os fluxos no browser, incluindo reconciliações. |

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
- O cartão nasce **por distribuir, sem saldo**. Só na **entrega em mão** fica activo com **6 000 MT**, e só com um **ciclo aberto**.
- Na ausência do representante, o cartão pode ser entregue a um **substituto**: o nome e o parentesco ficam no cartão; os dados do agregado não mudam.
- **Cartão perdido**: é cancelado e emitido um novo com o saldo que restava (gestor MEAL ou administrador).
- **Cancelar ou fechar um lote**: gestor MEAL ou administrador; os cartões não entregues são cancelados.

**Ciclos**
- **Um ciclo aberto de cada vez** (`CIC-NN`).
- **Recarga**: 6 000 MT no **mesmo cartão**, para os agregados escolhidos (por zona ou lista de CODIGO AF).
- **Fecho**: o saldo que sobra **expira** e fica no relatório como não utilizado. Não se fecha nos **15 minutos** a seguir a uma venda (ainda pode ser anulada).

**Reconciliação (pagamento ao comerciante)**
- Só **dias já fechados** (até ontem, hora de Moçambique) e só vendas confirmadas ainda não reconciliadas.
- Circuito: **oficial MEAL** cria e envia → **gestor MEAL** aprova ou devolve → **Finanças** validam ou devolvem. Ninguém valida sozinho.
- Uma reconciliação criada pelo gestor MEAL segue directamente para as Finanças.
- Devolver **liberta as vendas** para uma nova reconciliação.
- Paga-se ao comerciante a **parte do voucher**; o copagamento já foi recebido por ele ao balcão.

## 5. Papéis e permissões

| Papel | O que faz |
|---|---|
| **Administrador do sistema** | Tudo, incluindo utilizadores e o **nome da organização** em Configuração. |
| **Gestor MEAL** | Back office completo, utilizadores, aprovar reconciliações, gerir ciclos, cancelar lotes, substituir cartões; em **Configuração**: programa, frase do cliente e logótipos. |
| **Oficial MEAL** | Back office **só da sua província** (beneficiários, cartões, comerciantes, vendas, reconciliações). Sem província não vê nada. |
| **Oficial de Finanças** | Painel, validar ou devolver reconciliações, transacções, relatórios; vê **produtos e preços sem alterar**. |
| **Gestor de comerciante** | POS, vendas e o **catálogo** do seu comerciante. |
| **Operador de comerciante** | POS e as suas vendas. |

- Ninguém altera o seu próprio acesso nem repõe a sua própria palavra-passe pelo ecrã de Utilizadores.
- Toda a alteração de acessos, cliente e logótipos fica na **auditoria** (quem, quando, antes e depois).

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

## 10. Pendentes antes da produção

| Pendente | Notas |
|---|---|
| **Laravel Cloud**: confirmar que o MySQL gerido aceita `log_bin_trust_function_creators` | Necessário para os triggers. Testar num ambiente `staging` a partir deste branch. |
| **Fornecedor de e-mail** | Para a recuperação da palavra-passe. |
| **Logótipo original em vector** | Substituir o desenho actual pelo ficheiro do designer. |
| **Deck de apresentação** | Actualizar com o novo logótipo, a nova foto e "comerciante". |
| **Passar para `main`** | Quando o sistema estiver terminado, com código limpo. |
| **Testes com utilizadores reais** | MEAL, comerciantes e Finanças, repondo os dados. |

## 11. Caminho até aos novos clientes

**Já decidido**
- Primeiro cliente (LINK GCF): instalação própria, configurada pelo ecrã de Configuração.
- O **núcleo** do produto não é configurável: ledger imutável, POS, anulação por movimento inverso, reconciliação com aprovação, auditoria.
- O que varia entre clientes vai para **configuração**, começando pelos parâmetros hoje fixos no código: valor do cartão (6 000 MT), limite de copagamento (20%), janela de anulação (15 min), moeda (MT), fuso (Maputo), língua.

**Por decidir (com o segundo cliente à vista)**
- **Como separar os clientes**: uma base de dados por cliente (isolamento forte, preferido pelo tipo de dados) ou uma base partilhada.
- **O que realmente varia** entre organizações (valor por tamanho do agregado, vários saldos, categorias restritas, níveis de aprovação, divisão geográfica): conversar com 3 a 5 organizações antes de desenhar.
- **Painel de registo de clientes, subdomínios e página de apresentação** do Thandizo: vêm por último.
- **Consola do operador** (para a equipa Thandizo acompanhar cada cliente sem entrar nas contas).
- **Preço**: componente de arranque por programa (formação, cartões, primeira distribuição) e componente recorrente por beneficiário ou por ciclo.
- **Contas sem e-mail** para operadores de comerciante.
- **POS sem internet**, se o terreno o exigir.
