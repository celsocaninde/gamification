/**
 * Gamification JS
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Counter Animations
    const counters = document.querySelectorAll('.animate-number');
    counters.forEach(counter => {
        const target = +counter.getAttribute('data-target');
        const duration = 1500; // ms
        const increment = target / (duration / 16); // 60fps
        
        let current = 0;
        const updateCounter = () => {
            current += increment;
            if (current < target) {
                counter.innerText = Math.ceil(current);
                requestAnimationFrame(updateCounter);
            } else {
                counter.innerText = target;
            }
        };
        updateCounter();
    });

    // 2. Redeem Reward logic
    const redeemButtons = document.querySelectorAll('.btn-redeem-reward');
    redeemButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const rewardId = this.dataset.id;
            const rewardName = this.dataset.name;
            const cost = this.dataset.cost;
            const csrfToken = document.querySelector('input[name="_glpi_csrf_token"]')?.value || document.querySelector('meta[name="glpi-csrf-token"]')?.content;
            
            if (confirm(`Tem certeza que deseja resgatar '${rewardName}' por ${cost} XP?`)) {
                fetch('../../ajax/redeemreward.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Glpi-Csrf-Token': csrfToken
                    },
                    body: new URLSearchParams({
                        '_glpi_csrf_token': csrfToken,
                        'rewards_id': rewardId
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showToast(data.message || 'Erro ao resgatar', 'danger');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('Erro de comunicação', 'danger');
                });
            }
        });
    });

    // 3. Admin approve/reject orders
    const orderBtns = document.querySelectorAll('.btn-order-action');
    orderBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const orderId = this.dataset.id;
            const action = this.dataset.action; // 'approve' or 'reject'
            const csrfToken = document.querySelector('input[name="_glpi_csrf_token"]')?.value;
            
            let notes = prompt(action === 'approve' ? "Notas de aprovação (opcional):" : "Motivo da rejeição (opcional):");
            if (notes === null) return; // user cancelled prompt

            fetch('../../ajax/managereward.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': csrfToken
                },
                body: new URLSearchParams({
                    '_glpi_csrf_token': csrfToken,
                    'order_id': orderId,
                    'action': action,
                    'notes': notes
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message);
                }
            });
        });
    });

    // 4. Leaderboard filters auto-submit
    const lbFilters = document.querySelectorAll('.lb-filter');
    lbFilters.forEach(f => {
        f.addEventListener('change', () => {
            document.getElementById('leaderboard-filter-form').submit();
        });
    });

    // 5. Level-up celebration: confetti when the user's level increased
    //    since the last time they opened a gamification page.
    const wrap = document.querySelector('[data-gx-level]');
    if (wrap) {
        const current = parseInt(wrap.dataset.gxLevel, 10);
        const stored = parseInt(localStorage.getItem('gx_last_level') || '0', 10);
        if (!Number.isNaN(current)) {
            if (stored && current > stored) {
                gxConfetti();
                showToast(`🎉 Você subiu para o nível ${current}!`, 'success');
            }
            localStorage.setItem('gx_last_level', String(current));
        }
    }

    // 6. In-app notifications: poll unseen XP / level-up / badge events and
    //    surface them as toasts on any GLPI page.
    gxPollNotifications();
});

function gxPollNotifications() {
    if (window.top !== window.self) return; // only in the top frame
    const root = (window.CFG_GLPI && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
    fetch(root + '/plugins/gamification/ajax/notifications.php', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
    .then(r => (r.ok ? r.json() : []))
    .then(list => {
        if (!Array.isArray(list) || !list.length) return;
        let delay = 0;
        let leveled = false;
        list.forEach(n => {
            setTimeout(() => gxGamifyToast(n), delay);
            delay += 650;
            if (n.kind === 'level') leveled = true;
        });
        if (leveled) gxConfetti();
    })
    .catch(() => {});
}

function gxGamifyToast(n) {
    let container = document.getElementById('gamify-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'gamify-toast-container';
        container.className = 'gamify-toast-container';
        document.body.appendChild(container);
    }
    const el = document.createElement('div');
    el.className = 'gx-toast gx-toast--' + (n.accent || 'cyan');
    el.innerHTML =
        '<div class="gx-toast-ico"><i class="ti ' + (n.icon || 'ti-bolt') + '"></i></div>' +
        '<div class="gx-toast-body">' +
        '<div class="gx-toast-title">' + gxEsc(n.title || '') + '</div>' +
        (n.text ? '<div class="gx-toast-text">' + gxEsc(n.text) + '</div>' : '') +
        '</div>';
    container.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-in'));
    setTimeout(() => {
        el.classList.remove('is-in');
        setTimeout(() => el.remove(), 350);
    }, 5000);
}

function gxEsc(s) {
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}

function gxConfetti() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    const colors = ['#6d5bff', '#22d3ee', '#ffb020', '#19c37d', '#ff5470'];
    const layer = document.createElement('div');
    layer.className = 'gx-confetti';
    document.body.appendChild(layer);

    for (let i = 0; i < 90; i++) {
        const p = document.createElement('i');
        p.style.left = Math.random() * 100 + 'vw';
        p.style.background = colors[i % colors.length];
        p.style.animationDuration = (2 + Math.random() * 2) + 's';
        p.style.animationDelay = (Math.random() * 0.5) + 's';
        p.style.transform = `rotate(${Math.random() * 360}deg)`;
        layer.appendChild(p);
    }
    setTimeout(() => layer.remove(), 4500);
}

function showToast(message, type = 'success') {
    let container = document.getElementById('gamify-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'gamify-toast-container';
        container.className = 'gamify-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${type} border-0 show`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                ${type === 'success' ? '<i class="ti ti-check me-2"></i>' : '<i class="ti ti-alert-triangle me-2"></i>'}
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
