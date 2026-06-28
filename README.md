# 🎮 Gamificação — Service Desk Arena

Plugin de gamificação para **GLPI 11** que transforma o service desk em uma arena competitiva. Técnicos ganham XP, sobem de nível, desbloqueiam conquistas, competem em rankings por temporada e resgatam recompensas reais.

---

## ✨ Funcionalidades

### Para os Técnicos
| Recurso | Descrição |
|---|---|
| **XP & Níveis** | Ganhe pontos por resolver tickets, cumprir SLA, receber 5★ e criar artigos na KB |
| **Battle Pass** | Trilha sazonal de 10 tiers com recompensas automáticas de XP e títulos |
| **Missões semanais** | Desafios que resetam toda segunda — resolva X tickets, cumpra SLA, etc. |
| **Conquistas (Badges)** | 37+ badges em 6 raridades: Comum → Mítico, com efeito holo nos lendários |
| **Leaderboard** | Ranking por temporada individual e por equipe/grupo |
| **Loja de Recompensas** | Troque XP por benefícios reais (day off, cursos, acessórios…) |
| **Notificações in-app** | Toasts em tempo real para XP ganho, level-up e badge desbloqueado |
| **Dashboard pessoal** | Level Orb, barra de XP, estatísticas clicáveis, feed de atividade |

### Para os Administradores
| Recurso | Descrição |
|---|---|
| **Analytics** | KPIs, gráfico de XP 14 dias e painel de aderência SLA com filtro de período |
| **Gerenciar Conquistas** | CRUD completo de badges com 6 categorias e 6 raridades |
| **Gerenciar Missões** | Crie/edite desafios semanais com qualquer métrica disponível |
| **Battle Pass Admin** | Configure tiers por temporada ou restaure os padrões com um clique |
| **Configurações** | Horário comercial, duração de temporada, XP base, bônus de streak, loja, penalidades, visibilidade por entidade |
| **Direitos por perfil** | Controle granular via aba no perfil do GLPI |

---

## 📐 Arquitetura

```
gamification/
├── front/              # Páginas acessadas pelo browser
│   ├── dashboard.php       # Dashboard do técnico
│   ├── leaderboard.php     # Ranking da temporada
│   ├── badges.php          # Vitrine de conquistas
│   ├── quests.php          # Missões semanais
│   ├── battlepass.php      # Battle Pass (trilha de tiers)
│   ├── rewards.php         # Loja de recompensas
│   ├── myprofile.php       # Perfil pessoal
│   ├── analytics.php       # Painel admin de análises
│   ├── managebadges.php    # CRUD de conquistas
│   ├── managequests.php    # CRUD de missões
│   ├── managebattlepass.php# Config do Battle Pass
│   └── config.form.php     # Configurações globais
├── ajax/               # Endpoints AJAX
│   ├── notifications.php   # Poll de toasts (GET, sem CSRF)
│   ├── redeemreward.php    # Resgate de recompensa
│   └── managereward.php    # Aprovação/rejeição (admin)
├── src/                # Classes PHP (namespace GlpiPlugin\Gamification)
│   ├── Score.php           # XP, nível, streak por (user, entity)
│   ├── XPTransaction.php   # Log imutável de eventos
│   ├── EventListener.php   # Hooks GLPI → engine de XP
│   ├── BattlePass.php      # Tiers, claims, award automático
│   ├── Badge.php           # Catálogo de 37+ conquistas
│   ├── BadgeUser.php       # Conquistas ganhas por usuário
│   ├── Quest.php           # Missões semanais e progresso
│   ├── Leaderboard.php     # Ranking por temporada e entidade
│   ├── Season.php          # Gestão de temporadas
│   ├── Reward.php          # Itens da loja
│   ├── RewardOrder.php     # Pedidos de resgate
│   ├── Cron.php            # 4 tarefas agendadas
│   ├── Config.php          # Configurações do plugin
│   ├── Dashboard.php       # Widget na Central do GLPI
│   ├── Menu.php            # Estrutura de menus
│   ├── Profile.php         # Aba de direitos no perfil
│   ├── UserTab.php         # Aba na ficha do usuário
│   └── Ui.php              # Helpers de avatar/UI
├── public/
│   ├── css/gamification.css  # Design system "Service Desk Arena"
│   └── js/gamification.js    # Contadores, toasts, confetti, polling
├── hook.php            # Install / uninstall + migrações idempotentes
└── setup.php           # Hooks e inicialização do plugin
```

---

## 🚀 Instalação

### Requisitos
- GLPI ≥ 11.0.0
- PHP ≥ 8.2
- MySQL/MariaDB

### Via console (recomendado)
```bash
# Copie o plugin para a pasta plugins do GLPI
cp -r gamification/ /var/www/glpi/plugins/

# Instale e ative
php bin/console plugin:install gamification
php bin/console plugin:activate gamification
```

### Atualização de versão existente
```bash
php bin/console plugin:install gamification --force
php bin/console plugin:activate gamification
```
O `--force` re-executa o `install()` sem desinstalar — todas as migrações de schema são idempotentes (só alteram o que falta).

---

## 🗃️ Banco de dados

O plugin cria 14 tabelas com prefixo `glpi_plugin_gamification_*`:

| Tabela | Conteúdo |
|---|---|
| `configs` | Chave→valor das configurações |
| `rules` | Regras de XP por tipo de evento |
| `scores` | Pontuação agregada por (user, entity) |
| `xptransactions` | Log imutável de cada evento de XP |
| `badges` | Catálogo de conquistas |
| `badgeusers` | Conquistas ganhas por usuário |
| `seasons` | Temporadas (ativa, arquivadas) |
| `leaderboard` | Ranking snapshot por (user, season, entity) |
| `rewards` | Itens da loja |
| `rewardorders` | Pedidos de resgate e status |
| `quests` | Definições de missões semanais |
| `questclaims` | Missões completadas por usuário/semana |
| `battlepass_tiers` | Tiers do Battle Pass por temporada |
| `battlepass_claims` | Tiers desbloqueados por usuário |

Todos os dados de jogador são escopados por **entidade** (`entities_id`), permitindo isolamento completo em ambientes multi-entidade.

---

## ⚙️ Tarefas agendadas (Cron)

| Tarefa | Intervalo | Função |
|---|---|---|
| `CheckBadges` | A cada hora | Verifica e concede conquistas automaticamente |
| `CheckQuests` | A cada hora | Concede XP por missões semanais completadas |
| `CheckBattlePass` | A cada hora | Desbloqueia tiers e concede recompensas |
| `ProcessSeason` | Diário | Fecha temporadas vencidas e abre a próxima |

---

## 🎨 Design System

O CSS usa o namespace `gx-` e fica escopo dentro de `.gamification-wrapper` para não vazar para o restante do GLPI. Destaques:

- **Level Orb** — anel de XP com `conic-gradient` via `@property --gx-p`
- **Toasts** — notificações empilhadas no canto inferior direito
- **Confetti** — animação ao subir de nível (respeita `prefers-reduced-motion`)
- **Dark mode** — tokens CSS adaptados para todos os temas escuros do GLPI 11
- **Battle Pass track** — grid responsivo com estados locked/unlocked/claimed animados

---

## 📊 Sistema de XP (padrão)

| Evento | XP |
|---|---|
| Ticket resolvido | +10 |
| Resolução no 1º contato (FCR ≤ 60 min, sem escalada) | +50 |
| SLA cumprido | +20 |
| Avaliação 5★ | +100 |
| Avaliação 4★ | +40 |
| Artigo KB criado | +80 |
| Ticket reaberto | −30 |

Todas as regras são editáveis em **Gamificação → Regras**.

**Fórmula de nível:** `nível = floor(sqrt(xp_total / base)) + 1`  
Com base=100 (padrão): nível 2 = 100 XP, nível 5 = 1.600 XP, nível 10 = 8.100 XP.

---

## 🔐 Direitos

| Direito | Escopo |
|---|---|
| `plugin_gamification_dashboard` | Acesso ao painel do jogador |
| `plugin_gamification_leaderboard` | Acesso ao ranking |
| `plugin_gamification_rewards` | Acesso à loja de recompensas |
| `plugin_gamification_admin` | Acesso às telas administrativas |

Super-Admin (perfil 4) e Técnico (perfil 6) recebem direitos padrão na instalação.

---

## 📝 Licença

GPLv3+ — veja [LICENSE](LICENSE).
