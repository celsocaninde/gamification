<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use CommonGLPI;
use Profile as CoreProfile;
use ProfileRight;
use Session;

class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof CoreProfile) {
            return __('Gamificação', 'gamification');
        }
        return '';
    }

    /**
     * The gamification rights and how they are presented on the profile tab.
     * @return array<string, array{0:string,1:string,2:string,3:string}> right => [label, description, icon, accent]
     */
    public static function rightDefinitions(): array
    {
        return [
            'plugin_gamification_dashboard' => [
                __('Ver Dashboard', 'gamification'),
                __('Acessa o painel, conquistas, missões e o perfil de jogador.', 'gamification'),
                'ti-layout-dashboard', 'dashboard',
            ],
            'plugin_gamification_leaderboard' => [
                __('Ver Ranking', 'gamification'),
                __('Visualiza o ranking individual e por equipes da temporada.', 'gamification'),
                'ti-trophy', 'leaderboard',
            ],
            'plugin_gamification_rewards' => [
                __('Loja de Recompensas', 'gamification'),
                __('Pode trocar XP acumulado por recompensas na loja.', 'gamification'),
                'ti-gift', 'rewards',
            ],
            'plugin_gamification_admin' => [
                __('Administrar', 'gamification'),
                __('Configura regras, temporadas, recompensas e vê as análises.', 'gamification'),
                'ti-settings-bolt', 'admin',
            ],
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof CoreProfile)) {
            return false;
        }

        $profiles_id = (int) $item->getID();
        $rights      = self::getRightsForProfile($profiles_id);
        $can_edit    = Session::haveRight('profile', UPDATE);
        $defs        = self::rightDefinitions();
        $action      = \Plugin::getWebDir('gamification') . '/front/profileright.form.php';
        $csrf        = Session::getNewCSRFToken();

        echo "<div class='gamification-wrapper'>";
        // IMPORTANTE: nao usar <form> aqui. Esta aba e renderizada dentro do
        // formulario principal do perfil; um <form> aninhado e descartado pelo
        // navegador e o salvamento se perde. Salvamos via fetch() (ver script).
        echo "<div class='gx-card gx-card-pad gx-rights-card' id='gx-rights-card'"
           . " data-action='" . htmlspecialchars($action) . "'"
           . " data-csrf='" . htmlspecialchars($csrf) . "'"
           . " data-profile='" . $profiles_id . "'>";

        // Header
        echo "<div class='gx-rights-head'>";
        echo "<div class='gx-rights-emblem'><i class='ti ti-trophy'></i></div>";
        echo "<div>";
        echo "<p class='gx-eyebrow mb-1'>" . __('Gamificação', 'gamification') . "</p>";
        echo "<h2 class='h5 m-0'>" . __('Permissões deste perfil', 'gamification') . "</h2>";
        echo "<div class='gx-right-desc'>" . __('Defina o que os usuários com este perfil podem acessar.', 'gamification') . "</div>";
        echo "</div></div>";

        // Rows
        echo "<div class='gx-rights'>";
        foreach ($defs as $right_name => [$label, $desc, $icon, $accent]) {
            $val      = (int) ($rights[$right_name] ?? 0);
            $checked  = $val > 0 ? 'checked' : '';
            $set_val  = ($right_name === 'plugin_gamification_admin') ? 31 : 1;

            echo "<div class='gx-right-row gx-right-row--{$accent}'>";
            echo "<div class='gx-right-ico'><i class='ti {$icon}'></i></div>";
            echo "<div class='gx-right-body'>";
            echo "<div class='gx-right-title'>" . htmlspecialchars($label) . "</div>";
            echo "<div class='gx-right-desc'>" . htmlspecialchars($desc) . "</div>";
            echo "</div>";

            if ($can_edit) {
                echo "<label class='gx-switch' title='" . htmlspecialchars($label) . "'>";
                echo "<input type='checkbox' class='gx-right-check' data-right='" . htmlspecialchars($right_name) . "' data-setval='{$set_val}' {$checked}>";
                echo "<span class='gx-switch-track'></span>";
                echo "</label>";
            } else {
                $cls = $val > 0 ? 'gx-pill--on' : 'gx-pill--off';
                $txt = $val > 0 ? __('Ativo', 'gamification') : __('Inativo', 'gamification');
                echo "<span class='gx-pill {$cls}'>{$txt}</span>";
            }
            echo "</div>";
        }
        echo "</div>"; // .gx-rights

        if ($can_edit) {
            echo "<div class='gx-rights-foot' style='display:flex;align-items:center;gap:.75rem'>";
            echo "<button type='button' id='gx-rights-save' class='btn btn-primary px-4'>";
            echo "<i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'gamification');
            echo "</button>";
            echo "<span id='gx-rights-msg' style='font-size:13px'></span>";
            echo "</div>";
            self::renderRightsScript();
        }

        echo "</div>"; // .gx-rights-card
        echo "</div>"; // .gamification-wrapper

        return true;
    }

    /**
     * Script que salva os direitos via fetch() — contorna o problema de <form>
     * aninhado dentro do formulario do perfil e atualiza sem recarregar.
     */
    private static function renderRightsScript(): void
    {
        $okMsg   = __('Permissões salvas', 'gamification');
        $errMsg  = __('Falha ao salvar permissões', 'gamification');
        echo <<<HTML
<script>
(function () {
   const card = document.getElementById('gx-rights-card');
   const btn  = document.getElementById('gx-rights-save');
   const msg  = document.getElementById('gx-rights-msg');
   if (!card || !btn) { return; }

   btn.addEventListener('click', function () {
      const data = new FormData();
      data.set('_update_gamification_rights', '1');
      data.set('ajax', '1');
      data.set('id', card.dataset.profile);
      data.set('_glpi_csrf_token', card.dataset.csrf);

      card.querySelectorAll('.gx-right-check').forEach(function (cb) {
         data.set('_plugin_gamification_rights[' + cb.dataset.right + ']', cb.checked ? cb.dataset.setval : '0');
      });

      btn.disabled = true;
      msg.textContent = '...';
      msg.style.color = '';

      fetch(card.dataset.action, {
         method: 'POST',
         body: data,
         credentials: 'same-origin',
         headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': card.dataset.csrf }
      })
      .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
      .then(function (p) {
         const ok = p && p.ok !== false;
         msg.textContent = ok ? '$okMsg' : ('$errMsg' + (p && p.message ? ': ' + p.message : ''));
         msg.style.color = ok ? '#2b7a0b' : '#b5179e';
         // token CSRF e de uso unico: atualiza para um proximo salvar, se vier no payload
         if (p && p.csrf) { card.dataset.csrf = p.csrf; }
      })
      .catch(function () {
         msg.textContent = '$errMsg';
         msg.style.color = '#b5179e';
      })
      .finally(function () { btn.disabled = false; });
   });
})();
</script>
HTML;
    }

    /**
     * Persist gamification rights for a profile from a POSTed map.
     * Idempotent upsert into glpi_profilerights; refreshes the live session
     * when editing the current user's active profile. Returns rights changed.
     */
    public static function saveRights(int $profiles_id, array $posted): int
    {
        global $DB;

        if ($profiles_id <= 0 || !Session::haveRight('profile', UPDATE)) {
            return 0;
        }

        $valid   = array_keys(self::rightDefinitions());
        $current = self::getRightsForProfile($profiles_id);
        $changed = 0;

        foreach ($valid as $right_name) {
            // Only the admin right may hold the full mask (31); the rest are READ (1) or none (0).
            $raw = (int) ($posted[$right_name] ?? 0);
            $new = $raw > 0
                ? ($right_name === 'plugin_gamification_admin' ? 31 : 1)
                : 0;
            $old = (int) ($current[$right_name] ?? 0);

            if ($new === $old) {
                continue;
            }

            $existing = $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => ['profiles_id' => $profiles_id, 'name' => $right_name],
            ])->current();

            if ($existing) {
                $DB->update('glpi_profilerights', ['rights' => $new], ['id' => $existing['id']]);
            } else {
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $profiles_id,
                    'name'        => $right_name,
                    'rights'      => $new,
                ]);
            }
            $changed++;

            // Reflect immediately if the admin is editing their own active profile.
            if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profiles_id) {
                $_SESSION['glpiactiveprofile'][$right_name] = $new;
            }
        }

        return $changed;
    }

    public static function getRightsForProfile(int $profiles_id): array
    {
        global $DB;
        $rights = [];
        $iterator = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => ['LIKE', 'plugin_gamification_%']
            ]
        ]);
        
        foreach ($iterator as $row) {
            $rights[$row['name']] = $row['rights'];
        }
        return $rights;
    }
}
