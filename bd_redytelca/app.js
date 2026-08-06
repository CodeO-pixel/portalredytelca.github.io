window.clientesCache = [];
window.usuariosCache = [];
window.nodosCache = [];
window.oltsCache = [];
window.napsCache = [];
window.serviciosCache = [];
window.facturasCache = [];
window.pagosCache = [];
window.planesCache = [];
window.contratosCache = [];
window.permissionContext = {
    authenticated: false,
    user: '',
    roleId: null,
    roleName: '',
    token: '',
    modules: [],
    must_change_password: false
};
window.currentClienteSearch = '';
window.currentUsersPage = 1;
window.currentUsersFilter = 'Todos';
window.currentNapsPage = 1;
window.currentPlanesPage = 1;
window.currentContratosPage = 1;
window.currentOltsPage = 1;
window.currentNodosPage = 1;
window.currentTasksPage = 1;
window.currentTicketsPage = 1;
window.currentFacturasPage = 1;
window.currentPagosPage = 1;
window.currentMapFilter = 'Todos';
window.currentTicketsFilter = 'Todos';
window.taskPageSize = 8;
window.ticketPageSize = 8;
window.facturaPageSize = 8;
window.pagoPageSize = 8;
window.mapLastMode = 'normal';
window.leafletMapInitialized = false;
window.leafletMapInstance = null;
window.leafletBaseLayers = null;
window.leafletActiveBaseLayer = null;
window.leafletMarkers = [];
window.leafletSearchMarker = null;
const MARACAIBO_FALLBACK_CENTER = { lat: 10.6427, lon: -71.6125 };

function getClienteById(id) {
    return window.clientesCache.find(cliente => Number(cliente.id_cliente) === Number(id));
}

function persistSessionContext(context) {
    if (!context || !context.token) {
        localStorage.removeItem('redytelca_session');
        return;
    }
    localStorage.setItem('redytelca_session', JSON.stringify(context));
}

function clearSessionContext() {
    window.permissionContext = {
        authenticated: false,
        user: '',
        roleId: null,
        roleName: '',
        token: '',
        modules: [],
        must_change_password: false
    };
    localStorage.removeItem('redytelca_session');

    const overlay = document.getElementById('login-overlay');
    if (overlay) {
        overlay.style.display = 'flex';
    }

    const label = document.getElementById('profile-name-label');
    if (label) {
        label.textContent = 'Usuario';
    }

    const menu = document.getElementById('sidebar-nav');
    if (menu) {
        renderMenu();
    }
}

function getStoredSession() {
    try {
        return JSON.parse(localStorage.getItem('redytelca_session') || 'null');
    } catch (error) {
        return null;
    }
}

function buildDefaultMenuItems() {
    return [
        { page: 'dash', label: 'Dashboard', module: 'Análisis' },
        { page: 'mapa', label: 'Mapa', module: 'Operación' },
        { page: 'clientes', label: 'Clientes', module: 'Operación' }
    ];
}

const CONFIG_MODULE_NAME = 'Configuración';

function isAdminRole() {
    const roleId = Number(window.permissionContext?.roleId || 0);
    const roleName = String(window.permissionContext?.roleName || '').toLowerCase();
    return roleId === 1 || roleName.includes('admin') || roleName.includes('administrador');
}

/**
 * CORRECCIÓN #2 — REESTRUCTURA DE SIDEBAR EN 5 SECCIONES FIJAS Y
 * DESPLEGABLES (Principal / Operación / Infraestructura / Finanzas /
 * Configuración).
 *
 * ANTES: renderMenu() agrupaba por `item.module` usando el nombre crudo
 * que devuelve la tabla `modulos` (7 valores distintos: Principal,
 * Clientes, Operación, Configuración, Reportes, Infraestructura,
 * Finanzas), y solo el grupo "Configuración" era colapsable
 * (`isConfigGroup`). Esto producía 7 grupos visuales distintos en vez
 * de los 5 pactados, y el orden de aparición dependía del orden de
 * `id_modulo` en la BD, no de un criterio de UX fijo.
 *
 * AHORA: normalizeModuleToSection() colapsa 'Clientes' y 'Reportes'
 * dentro de 'Principal' (son vistas de uso diario, no un dominio propio
 * separado) — esto es puramente un agrupamiento VISUAL: la tabla
 * `modulos`/`rol_modulo_pagina` no se toca, por lo que el RBAC real
 * (qué rol ve qué página) sigue funcionando exactamente igual. Se
 * define SECTION_ORDER para forzar el orden de aparición fijo
 * (Principal, Operación, Infraestructura, Finanzas, Configuración),
 * independiente del id_modulo. Las 5 secciones son ahora `collapsible`
 * por igual (antes solo Configuración lo era), y se auto-expande la
 * sección que contiene la página actualmente activa (o 'Principal' en
 * el primer login).
 */
function renderMenu(autoNavigate = true) {
    const container = document.getElementById('sidebar-nav');
    if (!container) {
        return;
    }

    const normalizePageId = (value) => {
        const raw = String(value || '').trim().toLowerCase();
        const aliases = {
            'registro': 'clientes',
            'dashboard': 'dash',
            'inicio': 'dash',
            'gestión de clientes': 'clientes',
            'gestion de clientes': 'clientes',
            'registrar cliente': 'clientes',
            'control de tareas': 'tareas',
            'cambiar contraseña': 'password',
            'configuración': 'configuracion',
            'configuracion': 'configuracion',
            'administración y accesos': 'configuracion',
            'administracion y accesos': 'configuracion',
            'permisos': 'permisos',
            'usuarios': 'usuarios',
            'tickets': 'tickets',
            'mapa': 'mapa',
            'reportes': 'reportes',
            'facturación': 'facturas',
            'facturacion': 'facturas',
            'pagos': 'pagos',
            'planes': 'planes',
            'contratos': 'contratos',
            'notificaciones': 'notificaciones',
            'equipos': 'equipos'
        };
        return aliases[raw] || raw || 'dash';
    };

    const normalizeModuleToSection = (rawModule) => {
        const raw = String(rawModule || '').trim().toLowerCase();
        const sectionMap = {
            'analisis': 'Análisis',
            'análisis': 'Análisis',
            'dashboard': 'Análisis',
            'principal': 'Análisis',
            'clientes': 'Operación',
            'mapa': 'Operación',
            'tareas': 'Operación',
            'tickets': 'Operación',
            'operación': 'Operación',
            'operacion': 'Operación',
            'infraestructura': 'Infraestructura',
            'nodos': 'Infraestructura',
            'olts': 'Infraestructura',
            'naps': 'Infraestructura',
            'equipos': 'Infraestructura',
            'finanzas': 'Finanzas',
            'facturación': 'Finanzas',
            'facturacion': 'Finanzas',
            'pagos': 'Finanzas',
            'planes': 'Finanzas',
            'contratos': 'Finanzas',
            'configuración': 'Configuración',
            'configuracion': 'Configuración',
            'general': 'Configuración',
            'reportes': 'Análisis'
        };
        return sectionMap[raw] || 'Análisis';
    };

    const items = [...buildDefaultMenuItems()];
    const seen = new Map(items.map(item => [item.page, item]));

    (window.permissionContext.modules || []).forEach(entry => {
        const pageId = normalizePageId(entry.url || entry.slug || entry.page || entry.vista_key || entry.pagina || entry.vista || entry.modulo);
        if (!pageId || seen.has(pageId)) {
            return;
        }

        if (pageId === 'usuarios' || pageId === 'permisos') {
            return;
        }

        items.push({
            page: pageId,
            label: entry.vista || entry.pagina || entry.label || entry.modulo || entry.url || entry.slug || 'Vista',
            module: normalizeModuleToSection(entry.modulo)
        });
        seen.set(pageId, true);
    });

    if (isAdminRole()) {
        const configEntries = [
            { page: 'configuracion', label: 'General', module: 'Configuración' },
            { page: 'usuarios', label: 'Usuarios', module: 'Configuración' },
            { page: 'permisos', label: 'Permisos', module: 'Configuración' }
        ];

        configEntries.forEach(entry => {
            if (!seen.has(entry.page)) {
                items.push(entry);
                seen.set(entry.page, true);
            }
        });
    }

    const sidebarItems = items.filter(item => item.page !== 'password');

    const activePageBefore = document.querySelector('.page.active')?.id || null;

    const groups = {};
    sidebarItems.forEach(item => {
        const section = normalizeModuleToSection(item.module);
        if (!groups[section]) {
            groups[section] = [];
        }
        groups[section].push(item);
    });

    // Orden fijo de las 5 secciones pactadas, independiente del orden en
    // que la tabla `modulos` devuelva las filas (id_modulo 1..7).
    const SECTION_ORDER = ['Análisis', 'Operación', 'Infraestructura', 'Finanzas', 'Configuración'];
    const groupNames = SECTION_ORDER.filter(name => groups[name] && groups[name].length);

    // Sección que debe abrirse por defecto: la que contiene la página
    // activa antes de re-renderizar (o 'Análisis' en el primer login,
    // ya que la página estática de arranque es #dash).
    const activeSectionBefore = activePageBefore
        ? (sidebarItems.find(item => item.page === activePageBefore)?.module || 'Análisis')
        : 'Análisis';

    container.innerHTML = groupNames.map(groupName => {
        const itemsMarkup = groups[groupName].map(item => `
            <div class="nav-item" data-page="${item.page}" onclick="showPage('${item.page}', this)">${item.label}</div>
        `).join('');

        const shouldExpandByDefault = groupName === activeSectionBefore;

        return `
            <div class="nav-group collapsible${shouldExpandByDefault ? ' expanded' : ''}">
                <div class="nav-group-title" onclick="toggleNavGroup(this)">
                    ${groupName}
                    <span class="nav-group-arrow">${shouldExpandByDefault ? '▾' : '▸'}</span>
                </div>
                ${itemsMarkup}
            </div>
        `;
    }).join('');

    renderProfileMenu();

    if (autoNavigate) {
        const firstItem = container.querySelector('.nav-group.expanded .nav-item') || container.querySelector('.nav-item');
        if (firstItem) {
            showPage(firstItem.getAttribute('data-page'), firstItem);
        }
        return;
    }

    if (activePageBefore) {
        const matchingNav = container.querySelector(`.nav-item[data-page="${activePageBefore}"]`);
        if (matchingNav) {
            matchingNav.classList.add('active');
            const parentGroup = matchingNav.closest('.nav-group.collapsible');
            if (parentGroup) {
                parentGroup.classList.add('expanded');
                const arrow = parentGroup.querySelector('.nav-group-arrow');
                if (arrow) arrow.textContent = '▾';
            }
        }
    }
}

function renderProfileMenu() {
    const menu = document.getElementById('profile-menu');
    if (!menu) {
        return;
    }

    menu.innerHTML = `
        <button type="button" onclick="selectProfileMenuPage('password')">Cambiar contraseña</button>
        <button type="button" onclick="logout()">Cerrar sesión</button>
    `;
}

function toggleNavGroup(titleEl) {
    const group = titleEl.closest('.nav-group');
    if (!group) {
        return;
    }
    const expanded = group.classList.toggle('expanded');
    const arrow = group.querySelector('.nav-group-arrow');
    if (arrow) {
        arrow.textContent = expanded ? '▾' : '▸';
    }
}

function selectProfileMenuPage(pageId) {
    showPage(pageId, null);
    toggleProfileMenu();
}

async function restoreSession(autoNavigate = true) {
    const stored = getStoredSession();
    if (!stored || !stored.token) {
        clearSessionContext();
        return false;
    }

    try {
        const response = await fetch('auth_state.php?token=' + encodeURIComponent(stored.token), {
            headers: { 'X-Session-Token': stored.token }
        });

        if (!response.ok) {
            console.error('auth_state.php respondió con estado', response.status);
            clearSessionContext();
            return false;
        }

        const result = await response.json();

        if (!result || result.status !== 'success' || !result.authenticated) {
            clearSessionContext();
            return false;
        }

        window.permissionContext = result;
        persistSessionContext(result);

        const label = document.getElementById('profile-name-label');
        if (label) {
            label.textContent = result.user || 'Usuario';
        }

        renderMenu(autoNavigate);
        if (result.must_change_password) {
            showPage('password', null);
            const feedback = document.getElementById('password-feedback');
            if (feedback) {
                feedback.style.color = 'orange';
                feedback.textContent = 'Debes cambiar tu contraseña temporal antes de continuar.';
            }
        }
        return true;
    } catch (error) {
        console.error('No se pudo validar la sesión contra el servidor:', error);
        clearSessionContext();
        return false;
    }
}

async function logout() {
    const stored = getStoredSession();
    const tokenParam = stored && stored.token ? ('?token=' + encodeURIComponent(stored.token)) : '';
    try {
        await fetch('logout.php' + tokenParam, {
            method: 'POST',
            headers: stored && stored.token ? { 'X-Session-Token': stored.token } : {}
        });
    } catch (error) {
        console.warn('No se pudo cerrar la sesión del servidor:', error);
    }
    clearSessionContext();
}

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const main = document.querySelector('.main-content');
    sidebar?.classList.toggle('collapsed');
    main?.classList.toggle('expanded');
    if (document.getElementById('mapa')?.classList.contains('active')) {
        setTimeout(() => {
            window.leafletMapInstance?.invalidateSize();
        }, 250);
    }
}

async function showPage(pageId, element) {
    if (window.permissionContext.must_change_password && pageId !== 'password') {
        pageId = 'password';
        element = null;
    }

    if ((pageId === 'configuracion' || pageId === 'usuarios' || pageId === 'permisos') && !isAdminRole()) {
        return;
    }

    if (pageId === 'password') {
        const userInput = document.getElementById('password-user');
        if (userInput) {
            userInput.value = window.permissionContext?.user || '';
        }
    }

    const pages = document.querySelectorAll('.page');
    const navItems = document.querySelectorAll('.nav-item');

    pages.forEach(page => page.classList.remove('active'));
    navItems.forEach(item => item.classList.remove('active'));

    const selectedPage = document.getElementById(pageId);
    if (selectedPage) {
        selectedPage.classList.add('active');
    }

    let selectedNav = element || document.querySelector(`.nav-item[data-page="${pageId}"]`);
    if (selectedNav) {
        selectedNav.classList.add('active');
        const parentGroup = selectedNav.closest('.nav-group.collapsible');
        if (parentGroup) {
            parentGroup.classList.add('expanded');
            const arrow = parentGroup.querySelector('.nav-group-arrow');
            if (arrow) arrow.textContent = '▾';
        }
    }

    const titles = {
        dash: 'Dashboard',
        clientes: 'Clientes',
        tareas: 'Control de tareas',
        tickets: 'Tickets',
        mapa: 'Mapa técnico',
        configuracion: 'Administración y accesos',
        usuarios: 'Usuarios',
        permisos: 'Gestión de roles',
        password: 'Cambiar contraseña',
        reportes: 'Reportes',
        nodos: 'Nodos',
        olts: 'OLTs',
        naps: 'NAPs',
        equipos: 'Equipos',
        facturas: 'Facturación',
        pagos: 'Pagos',
        planes: 'Planes',
        contratos: 'Contratos'
    };

    const title = document.getElementById('page-title');
    if (title) {
        title.textContent = titles[pageId] || 'Panel';
    }

    if (pageId === 'reportes') {
        renderReportes();
    }

    if (pageId === 'dash') {
        await Promise.all([loadFacturas(), loadPagos(), loadOlts(), loadNaps()]);
        updateDashboardData();
    }

    if (pageId === 'nodos' || pageId === 'olts' || pageId === 'naps' || pageId === 'equipos') {
        await loadInfraestructura();
    }
    if (pageId === 'facturas' || pageId === 'pagos' || pageId === 'planes' || pageId === 'contratos') {
        await loadFinanzas();
    }
    if (pageId === 'mapa') {
        window.mapFilterState = { router: '', emisor: '', ubicacion: '' };
        ['map-filter-router', 'map-filter-emisor', 'map-filter-ubicacion'].forEach(id => {
            const select = document.getElementById(id);
            if (select) {
                select.value = '';
            }
        });
        updateMapFilterBadge();
        await loadInfraestructura();
        renderMapItems();
        setTimeout(() => {
            window.leafletMapInstance?.invalidateSize();
        }, 100);
        const mapFilterButtons = document.querySelectorAll('#map-filters .filter-pill');
        if (typeof window.setMapFilter === 'function') {
            const activeButton = document.querySelector(`#map-filters .filter-pill[data-filter="${window.currentMapFilter || 'Todos'}"]`) || document.querySelector('#map-filters .filter-pill.active');
            window.setMapFilter(window.currentMapFilter || 'Todos', activeButton);
        } else if (mapFilterButtons.length) {
            mapFilterButtons.forEach(btn => btn.classList.remove('active'));
            const defaultButton = mapFilterButtons[0];
            if (defaultButton) {
                defaultButton.classList.add('active');
                window.currentMapFilter = defaultButton.dataset.filter || 'Todos';
            }
        }
    }
    if (window.innerWidth <= 768) {
        toggleSidebar();
    }
}

function toggleProfileMenu() {
    const menu = document.getElementById('profile-menu');
    if (menu) {
        menu.classList.toggle('show');
    }
}

function setUsersFilter(filter, element) {
    const buttons = document.querySelectorAll('#user-filters .filter-pill');
    buttons.forEach(btn => btn.classList.remove('active'));
    if (element) {
        element.classList.add('active');
    }
    window.currentUsersFilter = filter || 'Todos';
    renderUsersPage(1);
}

function filterUsers() {
    const activeButton = document.querySelector('#user-filters .filter-pill.active');
    const filter = activeButton ? activeButton.textContent.trim() : window.currentUsersFilter || 'Todos';
    setUsersFilter(filter);
}

function abrirResetPassword(userId, username) {
    const modal = document.getElementById('reset-password-modal');
    if (!modal) {
        return;
    }
    document.getElementById('reset-user-id').value = userId || '';
    document.getElementById('reset-username').value = username || '';
    document.getElementById('reset-new-password').value = '';
    document.getElementById('reset-password-feedback').textContent = '';
    modal.style.display = 'flex';
}

function renderUsersPage(page = 1) {
    const tbody = document.querySelector('#users-table tbody');
    const pagination = document.getElementById('users-pagination');
    if (!tbody) {
        return;
    }

    const users = Array.isArray(window.usuariosCache) ? window.usuariosCache : [];
    const search = document.getElementById('users-search')?.value.trim().toLowerCase() || '';
    const filter = window.currentUsersFilter || 'Todos';
    const filteredUsers = users.filter(usuario => {
        const estado = usuario.estado || 'Activo';
        const matchesFilter = filter === 'Todos' || estado === filter;
        const searchText = `${usuario.username || ''} ${usuario.email || ''} ${usuario.nombre_rol || ''} ${estado}`.toLowerCase();
        const matchesSearch = !search || searchText.includes(search);
        return matchesFilter && matchesSearch;
    });

    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(filteredUsers.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentUsersPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleUsers = filteredUsers.slice(start, start + pageSize);

    tbody.innerHTML = visibleUsers.map(usuario => {
        const estado = usuario.estado || 'Activo';
        const verified = usuario.email_verified ? 'Verificado' : 'Pendiente';
        const verifiedClass = usuario.email_verified ? 'status-ok' : 'status-warning';
        const mustChange = usuario.must_change_password ? 'Sí' : 'No';
        const rolId = usuario.id_rol || '';
        return `
            <tr data-status="${escapeHtml(estado)}" data-user-id="${escapeHtml(String(usuario.id_usuario || ''))}">
                <td>${escapeHtml(usuario.username || 'Usuario')}</td>
                <td>${escapeHtml(usuario.email || '')}</td>
                <td>${escapeHtml(usuario.nombre_rol || 'Sin rol')}</td>
                <td><span class="status ${verifiedClass}">${escapeHtml(verified)}</span></td>
                <td>${escapeHtml(mustChange)}</td>
                <td>${escapeHtml(usuario.ultima_conexion || 'Nunca')}</td>
                <td>
                    <div class="table-actions">
                        ${usuario.email_verified ? '' : `<button type="button" data-user-id="${escapeHtml(String(usuario.id_usuario || ''))}" onclick="marcarEmailVerificado(this.getAttribute('data-user-id'))" style="width:auto; padding:8px 10px;">Verificar correo</button>`}
                        <button type="button" data-user-id="${escapeHtml(String(usuario.id_usuario || ''))}" onclick="abrirEditarUsuario(this.getAttribute('data-user-id'))" style="width:auto; padding:8px 10px;">Editar</button>
                        <button type="button" data-user-id="${escapeHtml(String(usuario.id_usuario || ''))}" data-username="${escapeHtml(usuario.username || '')}" onclick="abrirResetPassword(this.getAttribute('data-user-id'), this.getAttribute('data-username'))" style="width:auto; padding:8px 10px;">Restablecer</button>
                        <button type="button" data-user-id="${escapeHtml(String(usuario.id_usuario || ''))}" onclick="eliminarUsuario(this.getAttribute('data-user-id'))" style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderUsersPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderUsersPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function setTicketsFilter(filter, element) {
    const buttons = document.querySelectorAll('#ticket-filters .filter-pill');
    buttons.forEach(btn => btn.classList.remove('active'));
    if (element) {
        element.classList.add('active');
    }
    window.currentTicketsFilter = filter || 'Todos';
    renderTicketsPage(1);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderConfigUsersList() {
    const container = document.getElementById('config-users-list');
    if (!container) {
        return;
    }

    const users = Array.isArray(window.usuariosCache) ? window.usuariosCache : [];
    if (!users.length) {
        container.innerHTML = '<p style="color:#64748b; margin:0;">Aún no hay usuarios cargados.</p>';
        return;
    }

    container.innerHTML = users.map(usuario => `
        <div style="padding:12px; background:#f8fafc; border-radius:14px;">
            <strong>${escapeHtml(usuario.username || 'Usuario')}</strong>
            <p style="margin:4px 0 0; color:#64748b;">${escapeHtml(usuario.email || '')}</p>
            <small style="color:#334155;">Rol: ${escapeHtml(usuario.nombre_rol || 'Sin rol')}</small>
        </div>
    `).join('');
}

function renderRoleList(roles) {
    const container = document.getElementById('config-roles-list');
    if (!container) {
        return;
    }

    if (!Array.isArray(roles) || !roles.length) {
        container.innerHTML = '<p style="color:#64748b; margin:0;">No hay roles definidos.</p>';
        return;
    }

    container.innerHTML = roles.map(role => {
        const isAdminRole = Number(role.id_rol) === 1;
        const actionsDisabled = isAdminRole ? 'disabled' : '';
        const actionTitle = isAdminRole ? 'El rol Administrador no puede editarse ni eliminarse' : '';
        return `
            <div class="role-card" onclick="selectRole('${escapeHtml(String(role.id_rol))}')">
                <div class="role-card-icon">👥</div>
                <div class="role-card-info">
                    <strong>${escapeHtml(role.nombre_rol)}</strong>
                    <span>ID ${escapeHtml(String(role.id_rol))}</span>
                </div>
                <div class="role-card-actions" title="${escapeHtml(actionTitle)}">
                    <button type="button" ${actionsDisabled} onclick="event.stopPropagation(); abrirEditarRol('${escapeHtml(String(role.id_rol))}')" style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" ${actionsDisabled} onclick="event.stopPropagation(); eliminarRol('${escapeHtml(String(role.id_rol))}')" style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </div>
        `;
    }).join('');
}

function selectRole(roleId) {
    const configSelect = document.getElementById('config-role-select');
    if (!configSelect) {
        return;
    }
    configSelect.value = String(roleId);
    loadRolePermissions('config');
}

function abrirEditarUsuario(userId) {
    const usuario = (window.usuariosCache || []).find(item => Number(item.id_usuario) === Number(userId));
    if (!usuario) {
        alert('No se encontró el usuario seleccionado.');
        return;
    }

    const modal = document.getElementById('edit-user-modal');
    if (!modal) {
        return;
    }

    document.getElementById('edit-user-id').value = usuario.id_usuario || '';
    document.getElementById('edit-user-username').value = usuario.username || '';
    document.getElementById('edit-user-email').value = usuario.email || '';
    const rolSelect = document.getElementById('edit-user-role');
    if (rolSelect) {
        rolSelect.value = String(usuario.id_rol || '');
    }
    modal.style.display = 'flex';
}

async function guardarUsuarioEditado() {
    const id = document.getElementById('edit-user-id').value;
    const payload = {
        id_usuario: id,
        username: document.getElementById('edit-user-username').value.trim(),
        email: document.getElementById('edit-user-email').value.trim(),
        id_rol: document.getElementById('edit-user-role').value
    };

    if (!payload.username || !payload.email || !payload.id_rol) {
        alert('Completa todos los campos del usuario.');
        return;
    }

    try {
        const response = await fetch('usuarios_editar.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            document.getElementById('edit-user-modal').style.display = 'none';
            await loadUsers();
            await loadRoles();
        }
    } catch (error) {
        console.error('Error editando usuario:', error);
        alert('No se pudo editar el usuario.');
    }
}

async function eliminarUsuario(userId) {
    if (!confirm('¿Seguro que deseas eliminar este usuario?')) {
        return;
    }

    try {
        const response = await fetch('usuarios_eliminar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_usuario: userId })
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            await loadUsers();
            await loadRoles();
        }
    } catch (error) {
        console.error('Error eliminando usuario:', error);
        alert('No se pudo eliminar el usuario.');
    }
}

function abrirEditarRol(roleId) {
    const rol = (window.rolesCache || []).find(item => Number(item.id_rol) === Number(roleId));
    if (!rol) {
        alert('No se encontró el rol seleccionado.');
        return;
    }

    if (Number(rol.id_rol) === 1) {
        alert('El rol Administrador no puede editarse.');
        return;
    }

    const modal = document.getElementById('edit-role-modal');
    if (!modal) {
        return;
    }
    document.getElementById('edit-role-id').value = rol.id_rol || '';
    document.getElementById('edit-role-name').value = rol.nombre_rol || '';
    modal.style.display = 'flex';
}

async function guardarRolEditado() {
    const payload = {
        id_rol: document.getElementById('edit-role-id').value,
        nombre_rol: document.getElementById('edit-role-name').value.trim()
    };

    if (!payload.id_rol || !payload.nombre_rol) {
        alert('Completa el nombre del rol.');
        return;
    }

    try {
        const response = await fetch('roles_editar.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            document.getElementById('edit-role-modal').style.display = 'none';
            await loadRoles();
        }
    } catch (error) {
        console.error('Error editando rol:', error);
        alert('No se pudo editar el rol.');
    }
}

async function eliminarRol(roleId) {
    const rol = (window.rolesCache || []).find(item => Number(item.id_rol) === Number(roleId));
    if (Number(rol?.id_rol) === 1) {
        alert('El rol Administrador no puede eliminarse.');
        return;
    }

    if (!confirm('¿Seguro que deseas eliminar este rol?')) {
        return;
    }

    try {
        const response = await fetch('roles_eliminar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_rol: roleId })
        });
        const result = await response.json();
        if (result.status === 'success') {
            alert(result.message || 'Rol eliminado');
            await loadRoles();
        } else {
            alert(result.message || 'No se pudo eliminar el rol');
        }
    } catch (error) {
        console.error('Error eliminando rol:', error);
        alert('No se pudo eliminar el rol.');
    }
}

function populateAssignmentSelectors() {
    const taskClient = document.getElementById('task-client');
    const taskUser = document.getElementById('task-user');
    const ticketClient = document.getElementById('ticket-client');
    const ticketUser = document.getElementById('ticket-user');

    const buildClientOptions = () => ['<option value="">Sin cliente</option>', ...window.clientesCache.map(cliente => `<option value="${cliente.id_cliente}">${escapeHtml(`${cliente.nombres || ''} ${cliente.apellidos || ''}`.trim() || cliente.cedula || 'Cliente')}</option>`)].join('');
    const buildUserOptions = () => ['<option value="">Sin asignar</option>', ...window.usuariosCache.map(usuario => `<option value="${usuario.id_usuario}">${escapeHtml(usuario.username || 'Usuario')}</option>`)].join('');

    const refillPreservingSelection = (select, buildOptionsFn) => {
        if (!select) {
            return;
        }
        const previousValue = select.value;
        select.innerHTML = buildOptionsFn();
        if (previousValue && select.querySelector(`option[value="${previousValue}"]`)) {
            select.value = previousValue;
        }
    };

    refillPreservingSelection(taskClient, buildClientOptions);
    refillPreservingSelection(ticketClient, buildClientOptions);
    refillPreservingSelection(taskUser, buildUserOptions);
    refillPreservingSelection(ticketUser, buildUserOptions);
}

function buildServicioOptions() {
    const activos = (window.serviciosCache || []).filter(sv => sv.estado_comercial !== 'retirado');
    return ['<option value="">Selecciona un servicio</option>', ...activos.map(sv => {
        const nombre = `${sv.nombres || ''} ${sv.apellidos || ''}`.trim() || sv.cedula || 'Cliente';
        const aliasRef = sv.alias ? ` — ${sv.alias}` : '';
        const direccionRef = sv.direccion_texto ? ` (${sv.direccion_texto})` : '';
        return `<option value="${sv.id_servicio}" data-cliente="${sv.id_cliente}">${escapeHtml(nombre + aliasRef + direccionRef)}</option>`;
    })].join('');
}

function populateFinanzasSelectors() {
    const facturaServicio = document.getElementById('factura-servicio');
    const pagoServicio = document.getElementById('pago-servicio');
    const contratoServicio = document.getElementById('contrato-servicio');

    const refillPreservingSelection = (select) => {
        if (!select) {
            return;
        }
        const previousValue = select.value;
        select.innerHTML = buildServicioOptions();
        if (previousValue && select.querySelector(`option[value="${previousValue}"]`)) {
            select.value = previousValue;
        }
    };

    refillPreservingSelection(facturaServicio);
    refillPreservingSelection(pagoServicio);
    refillPreservingSelection(contratoServicio);

    if (pagoServicio) {
        pagoServicio.onchange = populatePagoFacturaOptions;
    }
}

function toggleResetPasswordModal(forceClose = false, reset = true) {
    const modal = document.getElementById('reset-password-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }

    if (!forceClose && reset) {
        document.getElementById('reset-user-id').value = '';
        document.getElementById('reset-username').value = '';
        document.getElementById('reset-new-password').value = '';
        document.getElementById('reset-password-feedback').textContent = '';
    }
}

async function marcarEmailVerificado(userId) {
    if (!userId) {
        return;
    }

    try {
        const response = await fetch('usuarios_verificar_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_usuario: userId })
        });
        const result = await response.json();
        if (result.status === 'success') {
            await loadUsers();
        } else {
            alert(result.message || 'No se pudo verificar el correo.');
        }
    } catch (error) {
        console.error('Error verificando correo:', error);
        alert('No se pudo verificar el correo.');
    }
}

function toggleClientModal(forceClose = false, reset = true) {
    const modal = document.getElementById('client-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('client-id').value = '';
        document.getElementById('client-nombres').value = '';
        document.getElementById('client-apellidos').value = '';
        document.getElementById('client-cedula').value = '';
        document.getElementById('client-telefono').value = '';
        document.getElementById('client-correo').value = '';
    }
}

function toggleTaskForm(forceClose = false, reset = true) {
    const modal = document.getElementById('task-modal');
    const form = document.getElementById('task-form');
    const title = document.getElementById('task-title');
    const description = document.getElementById('task-description');
    const status = document.getElementById('task-status');
    const priority = document.getElementById('task-priority');
    const client = document.getElementById('task-client');
    const user = document.getElementById('task-user');
    const id = document.getElementById('task-id');

    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    } else if (form) {
        form.style.display = forceClose ? 'none' : (form.style.display === 'none' ? 'block' : 'none');
    }

    if (!forceClose && reset) {
        if (title) title.value = '';
        if (description) description.value = '';
        if (status) status.value = 'Pendiente';
        if (priority) priority.value = 'Media';
        if (client) client.value = '';
        if (user) user.value = '';
        if (id) id.value = '';
    }
}

function toggleTicketForm(forceClose = false, reset = true) {
    const modal = document.getElementById('ticket-modal');
    const form = document.getElementById('ticket-form');
    const subject = document.getElementById('ticket-subject');
    const description = document.getElementById('ticket-description');
    const status = document.getElementById('ticket-status');
    const priority = document.getElementById('ticket-priority');
    const client = document.getElementById('ticket-client');
    const user = document.getElementById('ticket-user');
    const id = document.getElementById('ticket-id');

    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    } else if (form) {
        form.style.display = forceClose ? 'none' : (form.style.display === 'none' ? 'block' : 'none');
    }

    if (!forceClose && reset) {
        if (subject) subject.value = '';
        if (description) description.value = '';
        if (status) status.value = 'Abierto';
        if (priority) priority.value = 'Media';
        if (client) client.value = '';
        if (user) user.value = '';
        if (id) id.value = '';
    }
}

function toggleCloseTicketModal(forceClose = false, reset = true) {
    const modal = document.getElementById('close-ticket-modal');
    const details = document.getElementById('close-ticket-details');
    const id = document.getElementById('close-ticket-id');

    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }

    if (!forceClose && reset) {
        if (details) details.value = '';
        if (id) id.value = '';
    }
}

function toggleClientForm(forceClose = false, reset = true) {
    const modal = document.getElementById('client-modal');
    const nombres = document.getElementById('client-nombres');
    const apellidos = document.getElementById('client-apellidos');
    const cedula = document.getElementById('client-cedula');
    const telefono = document.getElementById('client-telefono');
    const correo = document.getElementById('client-correo');
    const id = document.getElementById('client-id');

    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }

    if (!forceClose && reset) {
        if (nombres) nombres.value = '';
        if (apellidos) apellidos.value = '';
        if (cedula) cedula.value = '';
        if (telefono) telefono.value = '';
        if (correo) correo.value = '';
        if (id) id.value = '';
    }
}

async function guardarTarea() {
    const id = document.getElementById('task-id').value;
    const payload = {
        titulo: document.getElementById('task-title').value.trim(),
        descripcion: document.getElementById('task-description').value.trim(),
        estado: document.getElementById('task-status').value,
        prioridad: document.getElementById('task-priority').value,
        id_cliente: document.getElementById('task-client').value,
        id_usuario_creador: document.getElementById('task-user').value
    };

    if (!payload.titulo) {
        alert('Ingresa un título para la tarea');
        return;
    }

    const response = await fetch('api_tareas.php', {
        method: id ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(id ? { ...payload, id_tarea: id } : payload)
    });
    const result = await response.json();
    alert(result.message || 'Operación realizada');
    if (result.status === 'success') {
        toggleTaskForm(true);
        await loadTareas();
    }
}

async function guardarTicket() {
    const id = document.getElementById('ticket-id').value;
    const payload = {
        asunto: document.getElementById('ticket-subject').value.trim(),
        descripcion: document.getElementById('ticket-description').value.trim(),
        estado: document.getElementById('ticket-status').value,
        prioridad: document.getElementById('ticket-priority').value,
        id_cliente: document.getElementById('ticket-client').value,
        id_usuario_creador: document.getElementById('ticket-user').value
    };

    if (!payload.asunto) {
        alert('Ingresa un asunto para el ticket');
        return;
    }

    const response = await fetch('api_tickets.php', {
        method: id ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(id ? { ...payload, id_ticket: id } : payload)
    });
    const result = await response.json();
    alert(result.message || 'Operación realizada');
    if (result.status === 'success') {
        toggleTicketForm(true);
        await loadTickets();
    }
}

async function guardarCliente() {
    const id = document.getElementById('client-id').value;
    const clienteData = {
        nombres: document.getElementById('client-nombres').value.trim(),
        apellidos: document.getElementById('client-apellidos').value.trim(),
        cedula: document.getElementById('client-cedula').value.trim(),
        telefono: document.getElementById('client-telefono').value.trim(),
        correo: document.getElementById('client-correo').value.trim()
    };

    if (!clienteData.nombres || !clienteData.apellidos || !clienteData.cedula || !clienteData.telefono || !clienteData.correo) {
        alert('Por favor completa los campos obligatorios.');
        return;
    }

    const endpoint = id ? 'cliente_actualizar.php' : 'registrar_cliente.php';
    const payload = id ? { ...clienteData, id_cliente: id } : clienteData;

    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        let result;
        try {
            result = await response.json();
        } catch (parseError) {
            throw new Error('El servidor respondió con un formato inválido (HTTP ' + response.status + '). Revisa el log de PHP.');
        }

        const mensajeExtra = !id && result.status === 'success'
            ? ' Ahora añade su primer servicio desde la ficha del cliente (botón "Ficha" → "+ Añadir servicio").'
            : '';
        alert((result.message || (result.status === 'success' ? 'Operación realizada' : 'No se pudo procesar la solicitud')) + mensajeExtra);

        if (result.status === 'success') {
            toggleClientForm(true);
            await loadClientes(window.currentClienteSearch);
        }
    } catch (error) {
        console.error('Error guardando cliente:', error);
        alert('No se pudo guardar el cliente: ' + error.message);
    }
}

function getStatusClass(status) {
    const value = String(status || '').toLowerCase();
    if (value.includes('complet') || value.includes('cerr')) return 'status-ok';
    if (value.includes('curso') || value.includes('proceso')) return 'status-warning';
    return 'status-alert';
}

async function loadTareas() {
    try {
        const response = await fetch('api_tareas.php');
        const result = await response.json();
        const tareas = Array.isArray(result.tareas) ? result.tareas : [];
        window.tareasCache = tareas;
        renderTasksPage(1);
    } catch (error) {
        console.error('No se pudieron cargar las tareas:', error);
    }
}

function renderTasksPage(page = 1) {
    const container = document.getElementById('task-list');
    const pagination = document.getElementById('tasks-pagination');
    if (!container) {
        return;
    }

    const tareas = Array.isArray(window.tareasCache) ? window.tareasCache : [];
    const totalPages = Math.max(1, Math.ceil(tareas.length / window.taskPageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentTasksPage = safePage;

    const start = (safePage - 1) * window.taskPageSize;
    const visibleTasks = tareas.slice(start, start + window.taskPageSize);

    container.innerHTML = visibleTasks.length ? visibleTasks.map(tarea => `
            <tr>
                <td>
                    <strong>${escapeHtml(tarea.titulo || 'Sin título')}</strong>
                    <p style="margin:4px 0 0; color:#64748b; font-size:0.85rem;">${escapeHtml(tarea.descripcion || 'Sin descripción')}</p>
                </td>
                <td>${escapeHtml(tarea.nombres ? `${tarea.nombres} ${tarea.apellidos || ''}`.trim() : 'Sin asignar')}</td>
                <td>${escapeHtml(tarea.creador || 'Sin asignar')}</td>
                <td>${escapeHtml(tarea.prioridad || 'Media')}</td>
                <td><span class="status ${getStatusClass(tarea.estado)}">${escapeHtml(tarea.estado || 'Pendiente')}</span></td>
                <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button type="button" onclick='editarTarea(${tarea.id_tarea})' style="width:auto; padding:8px 10px;">Editar</button>
                        <button type="button" onclick='cerrarTarea(${tarea.id_tarea})' style="width:auto; padding:8px 10px; background:#16a34a;">Cerrar</button>
                        <button type="button" onclick='eliminarTarea(${tarea.id_tarea})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                    </div>
                </td>
            </tr>
        `).join('') : '<tr><td colspan="6">No hay tareas registradas.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderTasksPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderTasksPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

async function loadTickets() {
    try {
        const response = await fetch('api_tickets.php');
        const result = await response.json();
        const tickets = Array.isArray(result.tickets) ? result.tickets : [];
        window.ticketsCache = tickets;
        renderTicketsPage(1);
    } catch (error) {
        console.error('No se pudieron cargar los tickets:', error);
    }
}

function renderTicketsPage(page = 1) {
    const container = document.getElementById('ticket-list');
    const pagination = document.getElementById('tickets-pagination');
    if (!container) {
        return;
    }

    const tickets = Array.isArray(window.ticketsCache) ? window.ticketsCache : [];
    const filter = window.currentTicketsFilter || 'Todos';
    const filteredTickets = tickets.filter(ticket => {
        if (filter === 'Todos') {
            return true;
        }
        return String(ticket.estado || '').toLowerCase() === String(filter || '').toLowerCase();
    });

    const totalPages = Math.max(1, Math.ceil(filteredTickets.length / window.ticketPageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentTicketsPage = safePage;

    const start = (safePage - 1) * window.ticketPageSize;
    const visibleTickets = filteredTickets.slice(start, start + window.ticketPageSize);

    container.innerHTML = visibleTickets.length ? visibleTickets.map(ticket => `
            <tr data-ticket-status="${escapeHtml(ticket.estado || 'Abierto')}">
                <td>
                    <strong>${escapeHtml(ticket.asunto || 'Sin asunto')}</strong>
                    <p style="margin:4px 0 0; color:#64748b; font-size:0.85rem;">${escapeHtml(ticket.descripcion || 'Sin descripción')}</p>
                </td>
                <td>${escapeHtml(ticket.nombres ? `${ticket.nombres} ${ticket.apellidos || ''}`.trim() : 'Sin asignar')}</td>
                <td>${escapeHtml(ticket.creador || 'Sin asignar')}</td>
                <td>${escapeHtml(ticket.prioridad || 'Media')}</td>
                <td><span class="status ${getStatusClass(ticket.estado)}">${escapeHtml(ticket.estado || 'Abierto')}</span></td>
                <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <button type="button" onclick='editarTicket(${ticket.id_ticket})' style="width:auto; padding:8px 10px;">Editar</button>
                        <button type="button" onclick='cerrarTicket(${ticket.id_ticket})' style="width:auto; padding:8px 10px; background:#16a34a;">Cerrar</button>
                        <button type="button" onclick='eliminarTicket(${ticket.id_ticket})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                    </div>
                </td>
            </tr>
        `).join('') : '<tr><td colspan="6">No hay tickets registrados.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderTicketsPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderTicketsPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function editarTarea(id) {
    const tarea = (window.tareasCache || []).find(item => Number(item.id_tarea) === Number(id));
    if (!tarea) return;
    document.getElementById('task-id').value = tarea.id_tarea;
    document.getElementById('task-title').value = tarea.titulo || '';
    document.getElementById('task-description').value = tarea.descripcion || '';
    document.getElementById('task-status').value = tarea.estado || 'Pendiente';
    document.getElementById('task-priority').value = tarea.prioridad || 'Media';
    document.getElementById('task-client').value = tarea.id_cliente || '';
    document.getElementById('task-user').value = tarea.id_usuario_creador || '';
    toggleTaskForm(false, false);
}

function editarTicket(id) {
    const ticket = (window.ticketsCache || []).find(item => Number(item.id_ticket) === Number(id));
    if (!ticket) return;
    document.getElementById('ticket-id').value = ticket.id_ticket;
    document.getElementById('ticket-subject').value = ticket.asunto || '';
    document.getElementById('ticket-description').value = ticket.descripcion || '';
    document.getElementById('ticket-status').value = ticket.estado || 'Abierto';
    document.getElementById('ticket-priority').value = ticket.prioridad || 'Media';
    document.getElementById('ticket-client').value = ticket.id_cliente || '';
    document.getElementById('ticket-user').value = ticket.id_usuario_creador || '';
    toggleTicketForm(false, false);
}

async function eliminarTarea(id) {
    if (!confirm('¿Deseas eliminar esta tarea?')) return;
    const response = await fetch('api_tareas.php?id=' + id, { method: 'DELETE' });
    const result = await response.json();
    alert(result.message || 'Tarea eliminada');
    if (result.status === 'success') {
        await loadTareas();
    }
}

async function eliminarTicket(id) {
    if (!confirm('¿Deseas eliminar este ticket?')) return;
    const response = await fetch('api_tickets.php?id=' + id, { method: 'DELETE' });
    const result = await response.json();
    alert(result.message || 'Ticket eliminado');
    if (result.status === 'success') {
        await loadTickets();
    }
}

async function cerrarTarea(id) {
    const response = await fetch('api_tareas.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_tarea: id, titulo: '', descripcion: '', estado: 'Completada', prioridad: 'Media' })
    });
    const result = await response.json();
    alert(result.message || 'Tarea cerrada');
    if (result.status === 'success') {
        await loadTareas();
    }
}

function abrirCierreTicket(id) {
    const ticket = (window.ticketsCache || []).find(item => Number(item.id_ticket) === Number(id));
    if (!ticket) {
        alert('No se encontró el ticket seleccionado.');
        return;
    }
    document.getElementById('close-ticket-id').value = ticket.id_ticket;
    document.getElementById('close-ticket-details').value = '';
    toggleCloseTicketModal(false, false);
}

async function confirmarCierreTicket() {
    const id = document.getElementById('close-ticket-id').value;
    const details = document.getElementById('close-ticket-details').value.trim();
    const ticket = (window.ticketsCache || []).find(item => Number(item.id_ticket) === Number(id));

    if (!ticket) {
        alert('No se encontró el ticket seleccionado.');
        return;
    }

    const descripcion = [ticket.descripcion, details ? `Cierre: ${details}` : ''].filter(Boolean).join('\n\n');
    const response = await fetch('api_tickets.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id_ticket: id,
            asunto: ticket.asunto || '',
            descripcion,
            estado: 'Cerrado',
            prioridad: ticket.prioridad || 'Media',
            id_cliente: ticket.id_cliente || null,
            id_usuario_creador: ticket.id_usuario_creador || null
        })
    });
    const result = await response.json();
    alert(result.message || 'Ticket cerrado');
    if (result.status === 'success') {
        toggleCloseTicketModal(true);
        await loadTickets();
    }
}

async function cerrarTicket(id) {
    abrirCierreTicket(id);
}

function exportClosedTicketsPdf() {
    const closedTickets = (window.ticketsCache || []).filter(ticket => String(ticket.estado || '').toLowerCase() === 'cerrado');
    if (!closedTickets.length) {
        alert('No hay tickets cerrados para exportar.');
        return;
    }

    const rows = closedTickets.map(ticket => `
        <tr>
            <td>${escapeHtml(ticket.asunto || 'Sin asunto')}</td>
            <td>${escapeHtml(ticket.nombres ? `${ticket.nombres} ${ticket.apellidos || ''}`.trim() : 'Sin asignar')}</td>
            <td>${escapeHtml(ticket.creador || 'Sin asignar')}</td>
            <td>${escapeHtml(ticket.prioridad || 'Media')}</td>
            <td>${escapeHtml(ticket.descripcion || 'Sin detalle')}</td>
        </tr>
    `).join('');

    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
        alert('El navegador bloqueó la ventana de impresión.');
        return;
    }

    printWindow.document.write(`<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <title>Tickets cerrados</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #0f172a; }
                h1 { margin-bottom: 8px; }
                table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                th, td { border: 1px solid #dbe3f0; padding: 8px; text-align: left; font-size: 12px; }
                th { background: #f8fafc; }
                .meta { color: #64748b; margin-bottom: 12px; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body>
            <h1>Tickets cerrados</h1>
            <div class="meta">Exportado desde REDYTELCA</div>
            <table>
                <thead>
                    <tr>
                        <th>Asunto</th>
                        <th>Cliente</th>
                        <th>Creado por</th>
                        <th>Prioridad</th>
                        <th>Detalle de cierre</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </body>
        </html>`);
    printWindow.document.close();
    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 250);
}

function initLeafletMap() {
    if (window.leafletMapInitialized || typeof L === 'undefined') {
        return window.leafletMapInstance;
    }

    const container = document.getElementById('map-embed');
    if (!container) {
        return null;
    }

    const map = L.map('map-embed', {
        zoomControl: true,
        scrollWheelZoom: true
    });

    const normalLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    });
    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
    });

    window.leafletBaseLayers = { Mapa: normalLayer, Satelital: satelliteLayer };
    window.leafletActiveBaseLayer = normalLayer;
    normalLayer.addTo(map);
    L.control.layers(window.leafletBaseLayers, null, { position: 'topright' }).addTo(map);
    map.setView([MARACAIBO_FALLBACK_CENTER.lat, MARACAIBO_FALLBACK_CENTER.lon], 13);
    window.leafletMapInstance = map;
    window.leafletMapInitialized = true;
    return map;
}

function setMapMode(mode, element) {
    const panel = document.getElementById('map-panel');
    const buttons = document.querySelectorAll('.view-toggle .toggle-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    if (panel) {
        panel.classList.toggle('satellite', mode === 'satelite');
    }
    if (element) {
        element.classList.add('active');
    }

    const map = window.leafletMapInstance || initLeafletMap();
    if (map && window.leafletBaseLayers) {
        const nextLayer = mode === 'satelite' ? window.leafletBaseLayers.Satelital : window.leafletBaseLayers.Mapa;
        if (nextLayer && (!window.leafletActiveBaseLayer || window.leafletActiveBaseLayer !== nextLayer)) {
            if (window.leafletActiveBaseLayer && map.hasLayer(window.leafletActiveBaseLayer)) {
                map.removeLayer(window.leafletActiveBaseLayer);
            }
            nextLayer.addTo(map);
            window.leafletActiveBaseLayer = nextLayer;
        }
    }
    window.mapLastMode = mode;
}

window.mapFilterState = {
    router: '',
    emisor: '',
    ubicacion: ''
};

function normalizarCoordenada(valorTexto) {
    const texto = String(valorTexto ?? '').trim();
    if (!texto) {
        return '';
    }
    const normalizado = texto.replace(',', '.').trim();
    if (!/^-?\d{1,3}(\.\d+)?$/.test(normalizado)) {
        return null;
    }
    return normalizado;
}

function calculateMapBounds(items = null) {
    const points = (items || getMapItems()).filter(item => Number.isFinite(item.lat) && Number.isFinite(item.lon) && !(Number(item.lat) === 0 && Number(item.lon) === 0));
    if (!points.length) {
        return null;
    }
    return L.latLngBounds(points.map(item => [item.lat, item.lon]));
}

function calculateMapCenter() {
    const bounds = calculateMapBounds();
    if (!bounds) {
        return { lat: MARACAIBO_FALLBACK_CENTER.lat, lon: MARACAIBO_FALLBACK_CENTER.lon };
    }
    const center = bounds.getCenter();
    return { lat: center.lat, lon: center.lng };
}

function setMapViewCenter(center, zoom = 15) {
    const map = window.leafletMapInstance || initLeafletMap();
    if (!map || !center) {
        return;
    }
    map.flyTo([Number(center.lat), Number(center.lon)], zoom, { duration: 0.8, easeLinearity: 0.25 });
}

function getMapItems() {
    const items = [];

    (window.nodosCache || []).forEach(nodo => {
        const lat = Number(nodo.latitud);
        const lon = Number(nodo.longitud);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            return;
        }
        items.push({
            id: `nodo-${nodo.id_nodo}`,
            type: 'Nodo',
            icon: '📍',
            title: nodo.nombre || 'Nodo sin nombre',
            subtitle: nodo.ubicacion || 'Sin ubicación',
            meta: `Lat ${nodo.latitud}, Lon ${nodo.longitud}`,
            lat,
            lon,
            className: 'nodo',
            routerValue: nodo.nombre || '',
            emisorValue: nodo.ubicacion || '',
            ubicacionValue: nodo.ubicacion || ''
        });
    });

    (window.oltsCache || []).forEach(olt => {
        const lat = Number(olt.latitud);
        const lon = Number(olt.longitud);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            return;
        }
        items.push({
            id: `olt-${olt.id_olt}`,
            type: 'Router',
            icon: '🖧',
            title: olt.codigo || olt.marca_modelo || 'Router sin código',
            subtitle: olt.nombre_nodo || 'Nodo no asignado',
            meta: olt.marca_modelo || 'Router de infraestructura',
            lat,
            lon,
            className: 'router',
            routerValue: olt.codigo || olt.marca_modelo || '',
            emisorValue: olt.nombre_nodo || '',
            ubicacionValue: olt.nombre_nodo || ''
        });
    });

    (window.napsCache || []).forEach(nap => {
        const lat = Number(nap.latitud);
        const lon = Number(nap.longitud);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            return;
        }
        items.push({
            id: `nap-${nap.id_nap}`,
            type: 'Emisor',
            icon: '🔌',
            title: nap.codigo || 'Emisor sin código',
            subtitle: nap.olt_codigo || nap.olt_nombre || 'Sin OLT',
            meta: nap.ubicacion_fisica || 'Sin ubicación',
            lat,
            lon,
            className: 'emisor',
            routerValue: nap.olt_codigo || nap.olt_nombre || '',
            emisorValue: nap.codigo || '',
            ubicacionValue: nap.ubicacion_fisica || ''
        });
    });

    (window.clientesCache || []).forEach(cliente => {
        const lat = Number(cliente.latitud);
        const lon = Number(cliente.longitud);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            return;
        }
        items.push({
            id: `cliente-${cliente.id_cliente}`,
            type: 'Cliente',
            icon: '👤',
            title: `${cliente.nombres || ''} ${cliente.apellidos || ''}`.trim() || 'Cliente',
            subtitle: cliente.nap_codigo || cliente.olt_codigo || 'Sin servicio',
            meta: cliente.direccion || 'Sin dirección',
            lat,
            lon,
            className: 'client',
            routerValue: cliente.olt_codigo || '',
            emisorValue: cliente.nap_codigo || '',
            ubicacionValue: cliente.direccion || cliente.nodo_nombre || ''
        });
    });

    return items;
}

function fillMapFilterSelect(selectId, values, defaultLabel) {
    const select = document.getElementById(selectId);
    if (!select) {
        return;
    }

    const selectedValue = select.value || '';
    const sortedValues = Array.from(values).filter(Boolean).sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));
    select.innerHTML = `<option value="">${escapeHtml(defaultLabel)}</option>` + sortedValues.map(value => `
        <option value="${escapeHtml(value)}"${value === selectedValue ? ' selected' : ''}>${escapeHtml(value)}</option>
    `).join('');
}

function buildMapFilterOptions() {
    const routerValues = new Set((window.oltsCache || []).map(olt => olt.codigo || olt.marca_modelo || '').filter(Boolean));
    const emisorValues = new Set((window.napsCache || []).map(nap => nap.codigo || '').filter(Boolean));
    const ubicacionValues = new Set();

    (window.nodosCache || []).forEach(nodo => { if (nodo.ubicacion) ubicacionValues.add(nodo.ubicacion); });
    (window.napsCache || []).forEach(nap => { if (nap.ubicacion_fisica) ubicacionValues.add(nap.ubicacion_fisica); });
    (window.clientesCache || []).forEach(cliente => { if (cliente.direccion) ubicacionValues.add(cliente.direccion); });

    fillMapFilterSelect('map-filter-router', routerValues, 'Router (todos)');
    fillMapFilterSelect('map-filter-emisor', emisorValues, 'Emisor (todos)');
    fillMapFilterSelect('map-filter-ubicacion', ubicacionValues, 'Ubicación (todas)');
}

function onMapFilterChange() {
    const router = document.getElementById('map-filter-router')?.value || '';
    const emisor = document.getElementById('map-filter-emisor')?.value || '';
    const ubicacion = document.getElementById('map-filter-ubicacion')?.value || '';
    window.mapFilterState = { router, emisor, ubicacion };
    updateMapFilterBadge();
    renderMapItems();
}

function updateMapFilterBadge() {
    const badge = document.getElementById('map-filters-active');
    if (!badge) {
        return;
    }
    const hasActiveFilters = Boolean(window.mapFilterState?.router || window.mapFilterState?.emisor || window.mapFilterState?.ubicacion);
    badge.style.display = hasActiveFilters ? 'inline-flex' : 'none';
    badge.textContent = hasActiveFilters ? 'Filtro activo' : '';
}

function limpiarFiltrosMapa() {
    window.mapFilterState = { router: '', emisor: '', ubicacion: '' };
    ['map-filter-router', 'map-filter-emisor', 'map-filter-ubicacion'].forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.value = '';
        }
    });
    updateMapFilterBadge();
    onMapFilterChange();
}

function itemMatchesFilter(item) {
    const { router, emisor, ubicacion } = window.mapFilterState;
    if (item.type === 'Nodo') {
        return !ubicacion || item.ubicacionValue === ubicacion;
    }
    return (!router || item.routerValue === router)
        && (!emisor || item.emisorValue === emisor)
        && (!ubicacion || item.ubicacionValue === ubicacion);
}

function renderMapItems() {
    const listContainer = document.getElementById('map-item-list');
    const map = initLeafletMap();
    if (!listContainer || !map) {
        return;
    }

    buildMapFilterOptions();
    const allItems = getMapItems();
    const visibleItems = allItems.filter(itemMatchesFilter);

    listContainer.innerHTML = visibleItems.length ? visibleItems.map(item => {
        const typeClass = item.className === 'nodo' ? 'map-item-nodo' : item.className === 'router' ? 'map-item-olt' : item.className === 'emisor' ? 'map-item-nap' : 'map-item-client';
        const missingLocation = Number(item.lat) === 0 && Number(item.lon) === 0;
        return `
            <div class="map-item ${typeClass}" data-type="${escapeHtml(item.type)}" data-map-item-id="${escapeHtml(item.id)}">
                <div class="map-item-icon">${escapeHtml(item.icon)}</div>
                <div class="map-item-type">${escapeHtml(item.type)}</div>
                <div class="map-item-title">${escapeHtml(item.title)}</div>
                <div class="map-item-subtitle">${escapeHtml(item.subtitle)}</div>
                <div class="map-item-meta">${escapeHtml(item.meta)}</div>
                ${missingLocation ? '<div class="map-item-meta" style="color:var(--warning); font-size:0.78rem; margin-top:6px;">Sin ubicación registrada</div>' : ''}
            </div>
        `;
    }).join('') : '<div class="map-item-empty">No hay datos visibles para el filtro seleccionado.</div>';

    window.leafletMarkers.forEach(marker => marker.remove());
    window.leafletMarkers = [];
    window.leafletMarkersById = new Map();

    visibleItems.filter(item => !(Number(item.lat) === 0 && Number(item.lon) === 0)).forEach(item => {
        const marker = L.marker([item.lat, item.lon], {
            icon: L.divIcon({
                html: `<div style="font-size:22px;line-height:1;transform:translate(-50%,-100%);">${item.icon}</div>`,
                className: 'leaflet-map-marker',
                iconSize: [28, 28],
                iconAnchor: [14, 28]
            })
        }).bindPopup(`<div style="min-width:180px;"><strong>${escapeHtml(item.title)}</strong><br><span style="color:#64748b;">${escapeHtml(item.subtitle)}</span><br><small>${escapeHtml(item.meta)}</small></div>`).addTo(map);
        window.leafletMarkers.push(marker);
        window.leafletMarkersById.set(item.id, marker);
    });

    if (window.leafletSearchMarker) {
        window.leafletSearchMarker.remove();
        window.leafletSearchMarker = null;
    }

    const bounds = calculateMapBounds(allItems);
    if (bounds) {
        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
    } else {
        map.setView([MARACAIBO_FALLBACK_CENTER.lat, MARACAIBO_FALLBACK_CENTER.lon], 13);
    }

    listContainer.querySelectorAll('.map-item').forEach(card => {
        card.addEventListener('click', () => {
            const marker = window.leafletMarkersById?.get(card.dataset.mapItemId);
            if (marker) {
                map.setView([marker.getLatLng().lat, marker.getLatLng().lng], 15);
                marker.openPopup();
            }
        });
    });
}

function searchMapCoordinates() {
    const latInput = document.getElementById('map-search-lat');
    const lonInput = document.getElementById('map-search-lon');
    const latValue = latInput?.value?.trim() || '';
    const lonValue = lonInput?.value?.trim() || '';

    if (latValue === '' || lonValue === '' || isNaN(Number(latValue)) || isNaN(Number(lonValue))) {
        alert('Ingresa latitud y longitud válidas.');
        return;
    }

    const map = window.leafletMapInstance || initLeafletMap();
    if (!map) {
        return;
    }

    const lat = Number(latValue);
    const lon = Number(lonValue);
    if (window.leafletSearchMarker) {
        window.leafletSearchMarker.remove();
    }
    window.leafletSearchMarker = L.marker([lat, lon], {
        icon: L.divIcon({
            html: '<div style="font-size:22px;line-height:1;transform:translate(-50%,-100%);">🔎</div>',
            className: 'leaflet-map-marker',
            iconSize: [28, 28],
            iconAnchor: [14, 28]
        })
    }).bindPopup('Resultado de búsqueda').addTo(map);

    map.flyTo([lat, lon], 16, { duration: 0.8, easeLinearity: 0.25 });
    window.leafletSearchMarker.openPopup();
    renderMapItems();
}

function updateDashboardData() {
    const date = new Date();
    const formattedDate = date.toLocaleDateString('es-ES', {
        day: '2-digit',
        month: 'long',
        year: 'numeric'
    });

    const dashboardDate = document.getElementById('dashboard-date');
    if (dashboardDate) {
        dashboardDate.textContent = formattedDate;
    }

    const totalClientes = Array.isArray(window.clientesCache) ? window.clientesCache.length : 0;
    const clientesPreview = document.getElementById('preview-clientes-count');
    if (clientesPreview) {
        clientesPreview.textContent = totalClientes;
    }

    const servicios = Array.isArray(window.serviciosCache) ? window.serviciosCache : [];
    const serviciosPreview = document.getElementById('preview-servicios-count');
    if (serviciosPreview) {
        const activos = servicios.filter(item => String(item.estado_comercial || '').toLowerCase() === 'activo').length;
        serviciosPreview.textContent = `${activos}/${servicios.length}`;
    }

    const serviciosBreakdown = document.getElementById('preview-servicios-breakdown');
    if (serviciosBreakdown) {
        const estados = servicios.reduce((acc, servicio) => {
            const estado = String(servicio.estado_comercial || 'sin_estado').trim() || 'sin_estado';
            acc[estado] = (acc[estado] || 0) + 1;
            return acc;
        }, {});
        serviciosBreakdown.innerHTML = Object.entries(estados).map(([estado, count]) => `<div><span>${escapeHtml(estado)}</span> <strong>${count}</strong></div>`).join('');
    }

    const contratos = Array.isArray(window.contratosCache) ? window.contratosCache : [];
    const contratosPreview = document.getElementById('preview-contratos-count');
    const contratosBreakdown = document.getElementById('preview-contratos-breakdown');
    if (contratosPreview) {
        contratosPreview.textContent = contratos.length;
    }
    if (contratosBreakdown) {
        const vigentes = contratos.filter(item => String(item.estado || '').toLowerCase() === 'vigente').length;
        const noVigentes = contratos.length - vigentes;
        contratosBreakdown.innerHTML = `<div><span>Vigentes</span> <strong>${vigentes}</strong></div><div><span>Vencidos/rescindidos</span> <strong>${noVigentes}</strong></div>`;
    }

    const tickets = Array.isArray(window.ticketsCache) ? window.ticketsCache : [];
    const ticketsAbiertos = tickets.filter(item => String(item.estado || '').toLowerCase() !== 'cerrado').length;
    const ticketsValue = document.getElementById('preview-tickets-count');
    if (ticketsValue) {
        ticketsValue.textContent = ticketsAbiertos;
    }

    const tareas = Array.isArray(window.tareasCache) ? window.tareasCache : [];
    const tareasPendientes = tareas.filter(item => String(item.estado || '').toLowerCase() !== 'completada').length;
    const tareasValue = document.getElementById('preview-tareas-count');
    if (tareasValue) {
        tareasValue.textContent = tareasPendientes;
    }

    const olts = Array.isArray(window.oltsCache) ? window.oltsCache : [];
    const oltsValue = document.getElementById('preview-olts-count');
    const oltsBreakdown = document.getElementById('preview-olts-breakdown');
    if (oltsValue) {
        oltsValue.textContent = olts.length;
    }
    if (oltsBreakdown) {
        const conNaps = olts.filter(item => Number(item.total_naps || item.naps_count || 0) > 0).length;
        const sinNaps = olts.length - conNaps;
        oltsBreakdown.innerHTML = `<div><span>Con NAPs</span> <strong>${conNaps}</strong></div><div><span>Sin NAPs</span> <strong>${sinNaps}</strong></div>`;
    }

    const naps = Array.isArray(window.napsCache) ? window.napsCache : [];
    const napsValue = document.getElementById('preview-naps-count');
    const napsBreakdown = document.getElementById('preview-naps-breakdown');
    if (napsValue) {
        napsValue.textContent = naps.length;
    }
    if (napsBreakdown) {
        const totalClientes = naps.reduce((acc, nap) => acc + Number(nap.total_clientes || 0), 0);
        const totalPuertos = naps.reduce((acc, nap) => acc + Number(nap.cantidad_puertos_max || 0), 0);
        const ocupacion = totalPuertos > 0 ? Math.round((totalClientes / totalPuertos) * 100) : 0;
        napsBreakdown.innerHTML = `<div><span>Clientes</span> <strong>${totalClientes}</strong></div><div><span>Ocupación</span> <strong>${ocupacion}%</strong></div>`;
    }

    const facturas = Array.isArray(window.facturasCache) ? window.facturasCache : [];
    const facturasVencidasValue = document.getElementById('preview-facturas-vencidas-count');
    const facturasVencidasPill = document.getElementById('preview-facturas-vencidas-pill');
    const facturasVencidas = facturas.filter(item => String(item.estado || '').toLowerCase() === 'vencida').length;
    if (facturasVencidasValue) {
        facturasVencidasValue.textContent = facturasVencidas;
    }
    if (facturasVencidasPill) {
        facturasVencidasPill.className = facturasVencidas > 0 ? 'stat-pill negative' : 'stat-pill positive';
        facturasVencidasPill.textContent = facturasVencidas > 0 ? 'Requiere seguimiento' : 'Sin incidencias';
    }

    const pagos = Array.isArray(window.pagosCache) ? window.pagosCache : [];
    const pagosPendientesValue = document.getElementById('preview-pagos-pendientes-count');
    if (pagosPendientesValue) {
        pagosPendientesValue.textContent = pagos.filter(item => String(item.estado || '').toLowerCase() === 'pendiente').length;
    }

    const oltTableBody = document.getElementById('olt-table-body');
    if (oltTableBody) {
        const olts = Array.isArray(window.oltsCache) ? window.oltsCache : [];
        oltTableBody.innerHTML = olts.length ? olts.slice(0, 5).map(olt => {
            const totalNaps = Number(olt.total_naps || olt.naps_count || 0);
            const estado = totalNaps > 0 ? 'Con NAPs' : 'Sin NAPs';
            const estadoClass = totalNaps > 0 ? 'status status-ok' : 'status status-warning';
            const estadoLabel = totalNaps > 0 ? 'Operativa' : 'Sin NAPs';
            return `
                <tr>
                    <td>${escapeHtml(olt.codigo || 'Sin código')}</td>
                    <td>${escapeHtml(olt.nombre_nodo || olt.nodo || 'Sin nodo')}</td>
                    <td>${totalNaps}</td>
                    <td><span class="${estadoClass}">${escapeHtml(estadoLabel)}</span></td>
                </tr>
            `;
        }).join('') : '<tr><td colspan="4">No hay OLTs registradas.</td></tr>';
    }

    const clearChartState = (canvas) => {
        const parent = canvas.parentElement;
        if (!parent) return;
        parent.querySelectorAll('.dashboard-chart-empty').forEach(element => element.remove());
    };

    const showChartState = (canvas, message) => {
        clearChartState(canvas);
        const parent = canvas.parentElement;
        if (!parent) return;
        const note = document.createElement('p');
        note.className = 'stat-label dashboard-chart-empty';
        note.style.marginTop = '8px';
        note.textContent = message;
        parent.appendChild(note);
    };

    const renderChart = (canvasId, chartConfig) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const previous = window.dashboardCharts?.[canvasId];
        if (previous) previous.destroy();
        window.dashboardCharts = window.dashboardCharts || {};
        clearChartState(canvas);
        if (typeof Chart === 'undefined') {
            showChartState(canvas, 'No se pudo cargar Chart.js');
            return;
        }
        const ctx = canvas.getContext('2d');
        const chart = new Chart(ctx, chartConfig);
        window.dashboardCharts[canvasId] = chart;
    };

    const incomeCanvas = document.getElementById('dashboard-income-chart');
    if (incomeCanvas) {
        const periods = Array.from(new Set([...facturas.map(item => String(item.periodo || '').trim()).filter(Boolean), ...pagos.map(item => String(item.periodo || '').trim()).filter(Boolean)]));
        const dataSegments = periods.slice(-6).map(period => {
            const validPayments = pagos.filter(item => String(item.estado || '').toLowerCase() === 'validado' && String(item.periodo || '') === period);
            return validPayments.reduce((acc, payment) => acc + Number(payment.monto || 0), 0);
        });
        if (periods.length >= 2 && dataSegments.some(value => value > 0)) {
            renderChart('dashboard-income-chart', {
                type: 'line',
                data: {
                    labels: periods.slice(-6),
                    datasets: [{
                        label: 'Ingresos validado',
                        data: dataSegments,
                        borderColor: 'var(--accent)',
                        backgroundColor: 'rgba(37, 99, 235, 0.18)',
                        fill: true,
                        tension: 0.35
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { color: 'var(--muted)' } }, x: { ticks: { color: 'var(--muted)' } } }
                }
            });
        } else {
            showChartState(incomeCanvas, 'Aún no hay suficiente historial de pagos');
        }
    }

    const billingCanvas = document.getElementById('dashboard-billing-chart');
    if (billingCanvas) {
        const stateCounts = facturas.reduce((acc, factura) => {
            const estado = String(factura.estado || 'pendiente').toLowerCase();
            acc[estado] = (acc[estado] || 0) + 1;
            return acc;
        }, {});
        const labels = Object.keys(stateCounts);
        const data = Object.values(stateCounts);
        const colors = labels.map(label => label === 'pagada' ? 'var(--success)' : (label === 'vencida' ? 'var(--danger)' : 'var(--warning)'));
        if (labels.length) {
            renderChart('dashboard-billing-chart', {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: colors }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        } else {
            showChartState(billingCanvas, 'Sin datos suficientes todavía');
        }
    }

    const servicesCanvas = document.getElementById('dashboard-services-chart');
    if (servicesCanvas) {
        const stateCounts = servicios.reduce((acc, servicio) => {
            const estado = String(servicio.estado_comercial || 'pendiente').toLowerCase();
            acc[estado] = (acc[estado] || 0) + 1;
            return acc;
        }, {});
        const labels = Object.keys(stateCounts);
        const data = Object.values(stateCounts);
        const colors = labels.map(label => label === 'activo' ? 'var(--success)' : (label === 'suspendido' || label === 'pendiente' ? 'var(--warning)' : 'var(--danger)'));
        if (labels.length) {
            renderChart('dashboard-services-chart', {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: colors }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        } else {
            showChartState(servicesCanvas, 'Sin datos suficientes todavía');
        }
    }

    const oltsCanvas = document.getElementById('dashboard-olts-chart');
    if (oltsCanvas) {
        const labels = olts.slice(0, 8).map(olt => olt.codigo || 'Sin código');
        const data = olts.slice(0, 8).map(olt => Number(olt.total_naps || olt.naps_count || 0));
        if (labels.length) {
            renderChart('dashboard-olts-chart', {
                type: 'bar',
                data: { labels, datasets: [{ label: 'NAPs por OLT', data, backgroundColor: 'var(--accent)' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        } else {
            showChartState(oltsCanvas, 'Sin datos suficientes todavía');
        }
    }

    const napsCanvas = document.getElementById('dashboard-naps-chart');
    if (napsCanvas) {
        const labels = naps.slice(0, 8).map(nap => nap.codigo || 'Sin código');
        const data = naps.slice(0, 8).map(nap => Number(nap.total_clientes || 0));
        const maxData = naps.slice(0, 8).map(nap => Number(nap.cantidad_puertos_max || 0));
        if (labels.length) {
            renderChart('dashboard-naps-chart', {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Clientes', data, backgroundColor: 'var(--accent)' }, { label: 'Puertos', data: maxData, backgroundColor: 'var(--warning)' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
            });
        } else {
            showChartState(napsCanvas, 'Sin datos suficientes todavía');
        }
    }

    const ticketsCanvas = document.getElementById('dashboard-tickets-chart');
    if (ticketsCanvas) {
        const counts = tickets.reduce((acc, ticket) => {
            const priority = String(ticket.prioridad || 'Media').trim() || 'Media';
            const estado = String(ticket.estado || 'Abierto').trim() || 'Abierto';
            const key = `${priority}:${estado}`;
            acc[key] = (acc[key] || 0) + 1;
            return acc;
        }, {});
        const labels = Object.keys(counts);
        const data = Object.values(counts);
        const colors = labels.map(label => {
            const priority = label.split(':')[0].toLowerCase();
            return priority === 'alta' ? 'var(--danger)' : (priority === 'media' ? 'var(--warning)' : 'var(--success)');
        });
        if (labels.length) {
            renderChart('dashboard-tickets-chart', {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Tickets', data, backgroundColor: colors }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        } else {
            showChartState(ticketsCanvas, 'Sin datos suficientes todavía');
        }
    }
}

function renderReportes() {
    const totalClientesEl = document.getElementById('report-total-clientes');
    const tareasPendientesEl = document.getElementById('report-tareas-pendientes');
    const tareasCompletadasEl = document.getElementById('report-tareas-completadas');
    const ticketsAbiertosEl = document.getElementById('report-tickets-abiertos');
    const ticketsPrioridadEl = document.getElementById('report-tickets-prioridad');

    if (!totalClientesEl && !tareasPendientesEl && !ticketsAbiertosEl) {
        return;
    }

    const tareas = Array.isArray(window.tareasCache) ? window.tareasCache : [];
    const tickets = Array.isArray(window.ticketsCache) ? window.ticketsCache : [];
    const clientesTotal = Number(window.clientesCache?.length || 0);

    const tareasPendientes = tareas.filter(t => String(t.estado || '').toLowerCase() !== 'completada').length;
    const tareasCompletadas = tareas.filter(t => String(t.estado || '').toLowerCase() === 'completada').length;
    const ticketsAbiertos = tickets.filter(t => String(t.estado || '').toLowerCase() !== 'cerrado').length;

    if (totalClientesEl) totalClientesEl.textContent = clientesTotal;
    if (tareasPendientesEl) tareasPendientesEl.textContent = tareasPendientes;
    if (tareasCompletadasEl) tareasCompletadasEl.textContent = tareasCompletadas;
    if (ticketsAbiertosEl) ticketsAbiertosEl.textContent = ticketsAbiertos;

    if (ticketsPrioridadEl) {
        const porPrioridad = { Alta: 0, Media: 0, Baja: 0 };
        tickets.forEach(t => {
            const prioridad = t.prioridad && porPrioridad.hasOwnProperty(t.prioridad) ? t.prioridad : 'Media';
            porPrioridad[prioridad]++;
        });
        ticketsPrioridadEl.innerHTML = Object.keys(porPrioridad).map(prioridad => `
            <div><span>Prioridad ${escapeHtml(prioridad)}</span><strong>${porPrioridad[prioridad]}</strong></div>
        `).join('');
    }
}

function updateZoneLabel() {
    const zoneSelect = document.getElementById('profile-zone');
    if (!zoneSelect) {
        return;
    }

    const zoneBadge = document.getElementById('profile-status-badge');
    if (zoneBadge) {
        zoneBadge.textContent = zoneSelect.value;
        zoneBadge.className = 'status ' + (zoneSelect.value === 'BAJO' ? 'status-warn' : 'status-ok');
    }
}

function getServicioEstadoClass(estado) {
    const value = String(estado || '').toLowerCase();
    if (value === 'activo') return 'status-ok';
    if (value === 'pendiente' || value === 'suspendido') return 'status-warning';
    return 'status-alert';
}

function renderClientServiciosPage(idCliente, page = 1) {
    const container = document.getElementById('client-servicios-list');
    const pagination = document.getElementById('client-servicios-pagination');
    if (!container) {
        return;
    }

    const serviciosCliente = (window.serviciosCache || []).filter(sv => Number(sv.id_cliente) === Number(idCliente));
    const pageSize = 6;
    const totalPages = Math.max(1, Math.ceil(serviciosCliente.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentClientServiciosPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleServicios = serviciosCliente.slice(start, start + pageSize);

    const serviciosRows = visibleServicios.length ? visibleServicios.map(sv => `
        <tr>
            <td>
                <strong>${escapeHtml(sv.alias || 'Servicio')}</strong>
                <p style="margin:4px 0 0; color:#64748b; font-size:0.85rem;">${escapeHtml(sv.direccion_texto || 'Sin dirección')}</p>
            </td>
            <td>${escapeHtml(sv.plan_nombre || 'Sin plan')}</td>
            <td>${escapeHtml(sv.nap_codigo || sv.olt_codigo || 'Sin NAP')}</td>
            <td><span class="status ${getServicioEstadoClass(sv.estado_comercial)}">${escapeHtml(sv.estado_comercial || 'pendiente')}</span></td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarServicio(${sv.id_servicio})' style="width:auto; padding:6px 10px;">Editar</button>
                    <button type="button" onclick='eliminarServicio(${sv.id_servicio})' style="width:auto; padding:6px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="5">Este cliente aún no tiene servicios registrados.</td></tr>';

    container.innerHTML = serviciosRows;

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderClientServiciosPage(${idCliente}, ${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderClientServiciosPage(${idCliente}, ${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function renderClientProfile(cliente) {
    const modal = document.getElementById('client-detail-modal');
    const content = document.getElementById('client-detail-content');
    if (!modal || !content || !cliente) {
        return;
    }

    const fullName = `${cliente.nombres || ''} ${cliente.apellidos || ''}`.trim();

    content.innerHTML = `
        <div class="detail-grid">
            <div class="detail-card">
                <span class="detail-label">Nombre</span>
                <div class="detail-value">${escapeHtml(fullName || 'Sin nombre')}</div>
            </div>
            <div class="detail-card">
                <span class="detail-label">Cédula</span>
                <div class="detail-value">${escapeHtml(cliente.cedula || '-')}</div>
            </div>
            <div class="detail-card">
                <span class="detail-label">Teléfono</span>
                <div class="detail-value">${escapeHtml(cliente.num_telefono || '-')}</div>
            </div>
            <div class="detail-card">
                <span class="detail-label">Correo</span>
                <div class="detail-value">${escapeHtml(cliente.correo || '-')}</div>
            </div>
        </div>
        <div style="margin-top:20px;">
            <div class="card-title-row">
                <h3>Servicios del cliente</h3>
                <button type="button" class="table-action" onclick="abrirNuevoServicio(${cliente.id_cliente})">+ Añadir servicio</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Servicio / Dirección</th>
                            <th>Plan</th>
                            <th>NAP</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="client-servicios-list"></tbody>
                </table>
            </div>
            <div id="client-servicios-pagination" class="pagination-row"></div>
        </div>
    `;

    renderClientServiciosPage(cliente.id_cliente, 1);
    modal.dataset.clienteId = cliente.id_cliente;
    modal.style.display = 'flex';
}

function closeClientDetailModal() {
    const modal = document.getElementById('client-detail-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function openUserDetailModal(user) {
    const modal = document.getElementById('user-detail-modal');
    const content = document.getElementById('user-detail-content');
    if (!modal || !content || !user) {
        return;
    }

    content.innerHTML = `
        <div class="detail-card">
            <span class="detail-label">Usuario</span>
            <div class="detail-value">${escapeHtml(user.username || 'Usuario')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Rol</span>
            <div class="detail-value">${escapeHtml(user.nombre_rol || 'Sin rol')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Estado</span>
            <div class="detail-value">${escapeHtml(user.estado || 'Activo')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Última conexión</span>
            <div class="detail-value">${escapeHtml(user.ultima_conexion || 'Nunca')}</div>
        </div>
    `;

    modal.style.display = 'flex';
}

function closeUserDetailModal() {
    const modal = document.getElementById('user-detail-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function openNapDetailModal(nap) {
    const modal = document.getElementById('nap-detail-modal');
    const content = document.getElementById('nap-detail-content');
    if (!modal || !content || !nap) {
        return;
    }

    content.innerHTML = `
        <div class="detail-card">
            <span class="detail-label">Código</span>
            <div class="detail-value">${escapeHtml(nap.codigo || '-')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Puertos</span>
            <div class="detail-value">${escapeHtml(nap.cantidad_puertos_max || '-')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Ubicación</span>
            <div class="detail-value">${escapeHtml(nap.ubicacion_fisica || 'Sin ubicación')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">OLT</span>
            <div class="detail-value">${escapeHtml(nap.olt_codigo || nap.olt_nombre || 'Sin OLT')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Nodo</span>
            <div class="detail-value">${escapeHtml(nap.nodo_nombre || '-')}</div>
        </div>
        <div class="detail-card">
            <span class="detail-label">Clientes conectados</span>
            <div class="detail-value">${escapeHtml(nap.total_clientes ?? 0)}</div>
        </div>
    `;

    modal.style.display = 'flex';
}

function closeNapDetailModal() {
    const modal = document.getElementById('nap-detail-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function changeClientPage(page) {
    loadClientes(window.currentClienteSearch, page);
}

async function loadClientes(filter = '', page = 1) {
    const list = document.getElementById('clientes-list');
    const pagination = document.getElementById('clientes-pagination');
    if (!list) {
        return;
    }

    window.currentClienteSearch = typeof filter === 'string' ? filter : '';
    const safePage = Number(page) > 1 ? Number(page) : 1;

    try {
        const response = await fetch('clientes_listar.php?filter=' + encodeURIComponent(window.currentClienteSearch) + '&page=' + safePage + '&per_page=10');

        let result;
        try {
            result = await response.json();
        } catch (parseError) {
            throw new Error('El servidor respondió con un formato inválido (HTTP ' + response.status + '). Revisa el log de errores de PHP.');
        }

        if (!result || result.status !== 'success') {
            throw new Error((result && result.message) ? result.message : 'Respuesta inválida del servidor');
        }

        const clientes = Array.isArray(result.clientes) ? result.clientes : [];
        window.clientesCache = clientes;
        populateAssignmentSelectors();
        refreshMapIfVisible();
        updateDashboardData();

        if (clientes.length === 0) {
            const emptyMessage = window.currentClienteSearch
                ? 'No se encontraron clientes que coincidan con la búsqueda.'
                : 'No hay clientes registrados todavía.';
            list.innerHTML = `<tr><td colspan="7">${escapeHtml(emptyMessage)}</td></tr>`;
            if (pagination) {
                pagination.innerHTML = '';
            }
            return;
        }

        list.innerHTML = clientes.map(cliente => `
            <tr data-client-id="${cliente.id_cliente}">
                <td><strong>${escapeHtml(`${cliente.nombres || ''} ${cliente.apellidos || ''}`.trim())}</strong></td>
                <td>${escapeHtml(cliente.cedula || '')}</td>
                <td>${escapeHtml(cliente.num_telefono || '')}</td>
                <td>${escapeHtml(cliente.correo || '')}</td>
                <td>${escapeHtml(cliente.direccion || 'No registrada')}</td>
                <td><span style="display:inline-block; padding:4px 10px; border-radius:999px; background:#eef4ff; color:#2563eb; font-weight:600; font-size:0.85rem;">${Number(cliente.total_servicios || 0)} servicio(s)</span></td>
                <td>
                    <div class="table-actions">
                        <button data-client-action="profile" data-client-id="${cliente.id_cliente}" style="width:auto; padding:8px 10px;">Ficha</button>
                        <button data-client-action="edit" data-client-id="${cliente.id_cliente}" style="width:auto; padding:8px 10px;">Editar</button>
                        <button data-client-action="delete" data-client-id="${cliente.id_cliente}" style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                    </div>
                </td>
            </tr>
        `).join('');

        if (pagination) {
            const totalPages = Number(result.pages || 1);
            const currentPage = Number(result.page || 1);
            pagination.innerHTML = `
                <div class="pagination-controls">
                    <button onclick="changeClientPage(${Math.max(1, currentPage - 1)})" ${currentPage <= 1 ? 'disabled' : ''}>Anterior</button>
                    <span>Página ${currentPage} / ${totalPages} · ${result.total || clientes.length} clientes</span>
                    <button onclick="changeClientPage(${Math.min(totalPages, currentPage + 1)})" ${currentPage >= totalPages ? 'disabled' : ''}>Siguiente</button>
                </div>
            `;
        }
    } catch (error) {
        console.error('No se pudo cargar el listado real de clientes:', error);
        window.clientesCache = [];
        populateAssignmentSelectors();
        updateDashboardData();
        list.innerHTML = `<tr><td colspan="7" style="color:#dc2626;">
            No se pudo conectar con el servidor de clientes.<br>
            <small>${escapeHtml(error.message || 'Error desconocido')}</small><br>
            <button type="button" onclick="loadClientes(window.currentClienteSearch)" style="width:auto; margin-top:8px; padding:6px 10px;">Reintentar</button>
        </td></tr>`;
        if (pagination) {
            pagination.innerHTML = '';
        }
    }
}

function editarCliente(id) {
    const cliente = window.clientesCache.find(c => Number(c.id_cliente) === Number(id));
    if (!cliente) {
        alert('Cliente no encontrado.');
        return;
    }

    document.getElementById('client-id').value = cliente.id_cliente;
    document.getElementById('client-nombres').value = cliente.nombres || '';
    document.getElementById('client-apellidos').value = cliente.apellidos || '';
    document.getElementById('client-cedula').value = cliente.cedula || '';
    document.getElementById('client-telefono').value = cliente.num_telefono || '';
    document.getElementById('client-correo').value = cliente.correo || '';

    const modal = document.getElementById('client-modal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

async function eliminarCliente(id) {
    if (!confirm('¿Seguro que deseas eliminar este cliente? Se eliminarán también todos sus servicios, facturas y pagos asociados.')) {
        return;
    }

    try {
        const response = await fetch('cliente_eliminar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_cliente: id })
        });

        let result;
        try {
            result = await response.json();
        } catch (parseError) {
            throw new Error('El servidor respondió con un formato inválido (HTTP ' + response.status + ').');
        }

        alert(result.message || (result.status === 'success' ? 'Cliente eliminado' : 'No se pudo eliminar'));
        if (result.status === 'success') {
            await loadClientes(window.currentClienteSearch);
            await loadServicios();
        }
    } catch (error) {
        console.error('Error eliminando cliente:', error);
        alert('No se pudo eliminar el cliente: ' + error.message);
    }
}

async function guardarResetPassword() {
    const userId = document.getElementById('reset-user-id').value;
    const username = document.getElementById('reset-username').value.trim();
    const password = document.getElementById('reset-new-password').value;
    const feedback = document.getElementById('reset-password-feedback');

    if (!username || !password) {
        feedback.style.color = 'red';
        feedback.textContent = 'Completa los datos para restablecer la contraseña.';
        return;
    }

    try {
        const response = await fetch('cambiar_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password_actual: '', password_nueva: password, user_id: userId })
        });
        const result = await response.json();
        feedback.style.color = result.status === 'success' ? 'green' : 'red';
        feedback.textContent = result.message;
        if (result.status === 'success') {
            toggleResetPasswordModal(true);
            await loadUsers();
        }
    } catch (error) {
        feedback.style.color = 'red';
        feedback.textContent = 'No se pudo restablecer la contraseña.';
        console.error(error);
    }
}

async function cambiarPassword() {
    const user = document.getElementById('password-user').value.trim();
    const current = document.getElementById('password-current').value;
    const newPass = document.getElementById('password-new').value;
    const confirm = document.getElementById('password-confirm').value;
    const feedback = document.getElementById('password-feedback');

    if (!user || !current || !newPass || !confirm) {
        feedback.style.color = 'red';
        feedback.textContent = 'Todos los campos son obligatorios.';
        return;
    }

    if (newPass !== confirm) {
        feedback.style.color = 'red';
        feedback.textContent = 'La nueva contraseña no coincide.';
        return;
    }

    const response = await fetch('cambiar_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: user, password_actual: current, password_nueva: newPass })
    });

    const result = await response.json();
    feedback.style.color = result.status === 'success' ? 'green' : 'red';
    feedback.textContent = result.message;

    if (result.status === 'success') {
        window.permissionContext.must_change_password = false;
        persistSessionContext(window.permissionContext);
        document.getElementById('password-user').value = '';
        document.getElementById('password-current').value = '';
        document.getElementById('password-new').value = '';
        document.getElementById('password-confirm').value = '';
    }
}

async function loadRoles() {
    const select = document.getElementById('role-select');
    const createSelect = document.getElementById('new-user-role');
    const configSelect = document.getElementById('config-role-select');
    const editUserRoleSelect = document.getElementById('edit-user-role');

    try {
        const response = await fetch('roles_listar.php');
        const text = await response.text();
        let result;

        try {
            result = JSON.parse(text);
        } catch (error) {
            result = null;
        }

        const roles = (result && Array.isArray(result.roles))
            ? result.roles
            : [
                { id_rol: 1, nombre_rol: 'Administrador' },
                { id_rol: 2, nombre_rol: 'Operador' }
            ];

        window.rolesCache = roles;
        const options = roles.map(rol => `<option value="${rol.id_rol}">${escapeHtml(rol.nombre_rol)}</option>`).join('');

        if (select) select.innerHTML = options;
        if (createSelect) createSelect.innerHTML = options;
        if (configSelect) configSelect.innerHTML = options;
        if (editUserRoleSelect) editUserRoleSelect.innerHTML = options;

        renderRoleList(roles);

        if (roles.length > 0 && select) {
            loadRolePermissions();
        }
        if (roles.length > 0 && configSelect) {
            loadRolePermissions('config');
        }
    } catch (error) {
        if (select) select.innerHTML = '<option value="1">Administrador</option>';
        if (createSelect) createSelect.innerHTML = '<option value="1">Administrador</option>';
        if (configSelect) configSelect.innerHTML = '<option value="1">Administrador</option>';
        if (editUserRoleSelect) editUserRoleSelect.innerHTML = '<option value="1">Administrador</option>';
        console.error(error);
    }
}

async function crearRol() {
    const input = document.getElementById('config-new-role-name');
    const feedback = document.getElementById('config-role-feedback');
    const nombreRol = input ? input.value.trim() : '';

    if (!nombreRol) {
        if (feedback) {
            feedback.style.color = 'red';
            feedback.textContent = 'Escribe un nombre para el nuevo rol.';
        }
        return;
    }

    try {
        const response = await fetch('roles_listar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre_rol: nombreRol })
        });
        const result = await response.json();

        if (feedback) {
            feedback.style.color = result.status === 'success' ? 'green' : 'red';
            feedback.textContent = result.message || '';
        }

        if (result.status === 'success') {
            if (input) input.value = '';
            await loadRoles();
            const configSelect = document.getElementById('config-role-select');
            if (configSelect && result.id_rol) {
                configSelect.value = String(result.id_rol);
                await loadRolePermissions('config');
            }
        }
    } catch (error) {
        if (feedback) {
            feedback.style.color = 'red';
            feedback.textContent = 'No se pudo crear el rol.';
        }
        console.error(error);
    }
}

async function loadUsers() {
    try {
        const response = await fetch('usuarios_listar.php');
        const result = await response.json();
        const tbody = document.querySelector('#users-table tbody');

        if (!tbody || result.status !== 'success') {
            return;
        }

        const users = Array.isArray(result.usuarios) ? result.usuarios : [];
        window.usuariosCache = users;
        populateAssignmentSelectors();
        renderConfigUsersList();
        renderUsersPage(1);
    } catch (error) {
        renderConfigUsersList();
        console.error('No se pudieron cargar los usuarios:', error);
    }
}

async function crearUsuario() {
    const username = document.getElementById('new-user-username').value.trim();
    const email = document.getElementById('new-user-email').value.trim();
    const roleSelect = document.getElementById('new-user-role');
    const roleId = roleSelect && roleSelect.value ? roleSelect.value : '1';
    const feedback = document.getElementById('user-feedback');

    if (!username || !email) {
        feedback.style.color = 'red';
        feedback.textContent = 'Completa todos los campos.';
        return;
    }

    if (!/^\S+@\S+\.\S+$/.test(email)) {
        feedback.style.color = 'red';
        feedback.textContent = 'Ingresa un correo válido.';
        return;
    }

    try {
        const response = await fetch('usuarios_crear.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, email, id_rol: roleId })
        });

        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (error) {
            result = { status: 'error', message: 'Respuesta inválida del servidor' };
        }

        feedback.style.color = result.status === 'success' ? 'green' : 'red';
        feedback.textContent = result.message;

        if (result.status === 'success') {
            document.getElementById('new-user-username').value = '';
            document.getElementById('new-user-email').value = '';
            await loadUsers();
            await loadRoles();
        }
    } catch (error) {
        feedback.style.color = 'red';
        feedback.textContent = 'Error al crear usuario.';
        console.error(error);
    }
}

async function loadRolePermissions(target = 'default') {
    const selectId = target === 'config' ? 'config-role-select' : 'role-select';
    const containerId = target === 'config' ? 'config-permissions-list' : 'permissions-list';
    const select = document.getElementById(selectId);
    const container = document.getElementById(containerId);

    if (!select || !container) {
        return;
    }

    const roleId = select.value;

    try {
        const response = await fetch('permisos_listar.php?id_rol=' + encodeURIComponent(roleId));
        const result = await response.json();

        if (result.status !== 'success' || !Array.isArray(result.modulos)) {
            throw new Error('No se pudieron cargar los permisos.');
        }

        container.innerHTML = result.modulos.map(modulo => `
            <div style="margin-bottom:14px;">
                <p style="margin:0 0 6px; font-weight:600; color:#0f172a;">${escapeHtml(modulo.nombre_modulo)}</p>
                <ul style="margin:6px 0 0; padding-left:18px;">
                    ${modulo.vistas.map(vista => `
                        <li style="margin-bottom:6px;"><label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" data-pagina="${vista.id_pagina}" value="${vista.id_pagina}" ${vista.asignado ? 'checked' : ''}> ${escapeHtml(vista.nombre_pagina)}</label></li>
                    `).join('')}
                </ul>
            </div>
        `).join('');
    } catch (error) {
        container.innerHTML = '<p style="color:#dc2626;">No se pudieron cargar los módulos y vistas para este rol.</p>';
        console.error(error);
    }
}

async function guardarPermisos(target = 'default') {
    const selectId = target === 'config' ? 'config-role-select' : 'role-select';
    const containerId = target === 'config' ? 'config-permissions-list' : 'permissions-list';
    const feedbackId = target === 'config' ? 'config-permissions-feedback' : 'permissions-feedback';

    const roleId = document.getElementById(selectId)?.value;
    const checkboxes = Array.from(document.querySelectorAll(`#${containerId} input[type=checkbox]`));
    const paginasSeleccionadas = checkboxes.filter(chk => chk.checked).map(chk => chk.value);
    const feedback = document.getElementById(feedbackId);

    try {
        const response = await fetch('permisos_guardar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_rol: roleId, paginas: paginasSeleccionadas })
        });
        const result = await response.json();

        if (feedback) {
            feedback.style.color = result.status === 'success' ? 'green' : 'red';
            feedback.textContent = result.message;
            setTimeout(() => { feedback.textContent = ''; }, 3000);
        }

        if (result.status === 'success') {
            await restoreSession(false);
        }
    } catch (error) {
        if (feedback) {
            feedback.style.color = 'red';
            feedback.textContent = 'No se pudo guardar la asignación de vistas.';
        }
        console.error(error);
    }
}

async function login() {
    console.log('login function invoked');
    const user = document.getElementById('user').value.trim();
    const pass = document.getElementById('pass').value.trim();

    if (!user || !pass) {
        alert('Por favor ingresa usuario y contrasena.');
        return;
    }

    try {
        const response = await fetch('login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: user, password: pass })
        });

        if (!response.ok) {
            const text = await response.text();
            throw new Error('Error de servidor: ' + response.status + ' - ' + text);
        }

        const result = await response.json();
        if (result.status === 'success') {
            window.permissionContext = {
                authenticated: true,
                user: result.usuario,
                roleId: result.id_rol,
                roleName: result.rol_nombre || 'Rol',
                token: result.token || '',
                modules: result.modulos_permitidos || [],
                must_change_password: result.must_change_password === true
            };
            persistSessionContext(window.permissionContext);
            document.getElementById('login-overlay').style.display = 'none';
            document.getElementById('user').value = '';
            document.getElementById('pass').value = '';
            const label = document.getElementById('profile-name-label');
            if (label) {
                label.textContent = result.usuario || 'Usuario';
            }
            renderMenu();
            await loadRoles();
            await loadUsers();
            await loadNaps();
            await loadClientes();
            await loadServicios();
            await loadInfraestructura();
            await loadFinanzas();
            updateDashboardData();
            if (window.permissionContext.must_change_password) {
                showPage('password', null);
                const feedback = document.getElementById('password-feedback');
                if (feedback) {
                    feedback.style.color = 'orange';
                    feedback.textContent = 'Debes cambiar tu contraseña temporal antes de continuar.';
                }
            }
        } else {
            alert(result.message);
        }
    } catch (error) {
        console.error(error);
        alert('Error en el login: ' + error.message);
    }
}

async function refreshMapIfVisible() {
    if (document.getElementById('mapa')?.classList.contains('active')) {
        setMapViewCenter(calculateMapCenter(), 15);
        renderMapItems();
    }
}

async function loadInfraestructura() {
    await loadNodos();
    await loadOlts();
    await loadNaps();
    await loadEquipos();

    if (isAdminRole() && !window.nodosCache.length && !window.oltsCache.length && !window.napsCache.length) {
        await seedDefaultInfrastructure();
    }

    renderMapItems();
    updateDashboardData();
}

async function seedDefaultInfrastructure() {
    try {
        const nodePayload = new FormData();
        nodePayload.append('nombre', 'Nodo Centro');
        nodePayload.append('ubicacion', 'Sector Centro');
        nodePayload.append('latitud', '10.48060000');
        nodePayload.append('longitud', '-66.90360000');
        nodePayload.append('estado', 'activo');
        await fetch('api_nodos.php', {
            method: 'POST',
            body: nodePayload
        });
        await loadNodos();

        const nodoId = window.nodosCache[0]?.id_nodo;
        if (!nodoId) {
            return;
        }

        const oltPayload = new FormData();
        oltPayload.append('codigo', 'OLT-01');
        oltPayload.append('marca_modelo', 'Huawei MA5616');
        oltPayload.append('puertos_pon', '16');
        oltPayload.append('ip_gestion', '192.168.100.1');
        oltPayload.append('id_nodos', nodoId);
        await fetch('api_olts.php', {
            method: 'POST',
            body: oltPayload
        });
        await loadOlts();

        const oltId = window.oltsCache[0]?.id_olt;
        if (!oltId) {
            return;
        }

        const napPayload = new FormData();
        napPayload.append('codigo', 'NAP-01');
        napPayload.append('cantidad_puertos_max', '16');
        napPayload.append('ubicacion_fisica', 'Calle Principal');
        napPayload.append('latitud', '10.48200000');
        napPayload.append('longitud', '-66.90500000');
        napPayload.append('id_olts', oltId);
        await fetch('api_naps.php', {
            method: 'POST',
            body: napPayload
        });
        await loadNaps();
    } catch (error) {
        console.warn('No se pudo sembrar la infraestructura inicial:', error);
    }
}

function populateInfraSelectors() {
    const oltNodo = document.getElementById('olt-nodo');
    const napOlt = document.getElementById('nap-olt');

    const buildNodoOptions = () => ['<option value="">Selecciona un nodo</option>', ...window.nodosCache.map(nodo => `<option value="${nodo.id_nodo}">${escapeHtml(nodo.nombre)}</option>`)].join('');
    const buildOltOptions = () => ['<option value="">Selecciona una OLT</option>', ...window.oltsCache.map(olt => `<option value="${olt.id_olt}">${escapeHtml(olt.codigo || olt.marca_modelo)}</option>`)].join('');

    const refillPreservingSelection = (select, buildOptionsFn) => {
        if (!select) return;
        const previousValue = select.value;
        select.innerHTML = buildOptionsFn();
        if (previousValue && select.querySelector(`option[value="${previousValue}"]`)) {
            select.value = previousValue;
        }
    };

    refillPreservingSelection(oltNodo, buildNodoOptions);
    refillPreservingSelection(napOlt, buildOltOptions);
}

function populateEquipoSelectores() {
    const napSelect = document.getElementById('equipo-nap');
    const servicioSelect = document.getElementById('equipo-servicio');

    if (napSelect) {
        const previousValue = napSelect.value;
        napSelect.innerHTML = ['<option value="">Selecciona una NAP</option>', ...(window.napsCache || []).map(nap => `<option value="${nap.id_nap}">${escapeHtml(nap.codigo || 'NAP')}</option>`)].join('');
        if (previousValue && napSelect.querySelector(`option[value="${previousValue}"]`)) {
            napSelect.value = previousValue;
        }
    }

    if (servicioSelect) {
        const previousValue = servicioSelect.value;
        const serviciosActivos = (window.serviciosCache || []).filter(servicio => String(servicio.estado_comercial || '').toLowerCase() === 'activo');
        servicioSelect.innerHTML = ['<option value="">Sin asignar</option>', ...serviciosActivos.map(servicio => `<option value="${servicio.id_servicio}">${escapeHtml(servicio.alias || `${servicio.nombres || ''} ${servicio.apellidos || ''}`.trim() || 'Servicio')}</option>`)].join('');
        if (previousValue && servicioSelect.querySelector(`option[value="${previousValue}"]`)) {
            servicioSelect.value = previousValue;
        }
    }
}

// ---------------- NODOS ----------------

async function loadNodos() {
    try {
        const response = await fetch('api_nodos.php');
        const result = await response.json();
        const container = document.getElementById('nodos-list');
        if (!container || result.status !== 'success') {
            return;
        }
        const nodos = Array.isArray(result.nodos) ? result.nodos : [];
        window.nodosCache = nodos;
        renderNodosPage(1);
        populateInfraSelectors();
        refreshMapIfVisible();
    } catch (error) {
        console.error('No se pudieron cargar los nodos:', error);
    }
}

function renderNodosPage(page = 1) {
    const container = document.getElementById('nodos-list');
    const pagination = document.getElementById('nodos-pagination');
    if (!container) {
        return;
    }

    const nodos = Array.isArray(window.nodosCache) ? window.nodosCache : [];
    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(nodos.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentNodosPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleNodos = nodos.slice(start, start + pageSize);

    container.innerHTML = visibleNodos.length ? visibleNodos.map(nodo => `
        <tr>
            <td><strong>${escapeHtml(nodo.nombre)}</strong></td>
            <td>${escapeHtml(nodo.ubicacion || '-')}</td>
            <td>${escapeHtml(nodo.latitud)}, ${escapeHtml(nodo.longitud)}</td>
            <td><span class="status ${nodo.estado === 'activo' ? 'status-ok' : nodo.estado === 'mantenimiento' ? 'status-warning' : 'status-alert'}">${escapeHtml(nodo.estado || 'activo')}</span></td>
            <td>${escapeHtml(nodo.total_olts ?? 0)}</td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarNodo(${nodo.id_nodo})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarNodo(${nodo.id_nodo})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="6">No hay nodos registrados.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderNodosPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderNodosPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function toggleNodoForm(forceClose = false, reset = true) {
    const modal = document.getElementById('nodo-modal');
    const form = document.getElementById('nodo-form');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    } else if (form) {
        form.style.display = forceClose ? 'none' : (form.style.display === 'none' ? 'block' : 'none');
    }
    if (!forceClose && reset) {
        document.getElementById('nodo-id').value = '';
        document.getElementById('nodo-nombre').value = '';
        document.getElementById('nodo-ubicacion').value = '';
        document.getElementById('nodo-latitud').value = '';
        document.getElementById('nodo-longitud').value = '';
        document.getElementById('nodo-estado').value = 'activo';
    }
}

async function guardarNodo() {
    const id = document.getElementById('nodo-id').value;
    const latitudTexto = document.getElementById('nodo-latitud').value.trim();
    const longitudTexto = document.getElementById('nodo-longitud').value.trim();
    const latitud = normalizarCoordenada(latitudTexto);
    const longitud = normalizarCoordenada(longitudTexto);
    const payload = {
        nombre: document.getElementById('nodo-nombre').value.trim(),
        ubicacion: document.getElementById('nodo-ubicacion').value.trim(),
        latitud: latitud ?? latitudTexto,
        longitud: longitud ?? longitudTexto,
        estado: document.getElementById('nodo-estado').value
    };

    if (!payload.nombre || !payload.latitud || !payload.longitud) {
        alert('Nombre, latitud y longitud son obligatorios');
        return;
    }

    if ((latitudTexto !== '' && latitud === null) || (longitudTexto !== '' && longitud === null)) {
        alert('La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).');
        return;
    }

    const response = await fetch('api_nodos.php', {
        method: id ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(id ? { ...payload, id_nodo: id } : payload)
    });
    const result = await response.json();
    alert(result.message || 'Operación realizada');
    if (result.status === 'success') {
        toggleNodoForm(true);
        await loadNodos();
    }
}

function editarNodo(id) {
    const nodo = (window.nodosCache || []).find(item => Number(item.id_nodo) === Number(id));
    if (!nodo) return;
    document.getElementById('nodo-id').value = nodo.id_nodo;
    document.getElementById('nodo-nombre').value = nodo.nombre || '';
    document.getElementById('nodo-ubicacion').value = nodo.ubicacion || '';
    document.getElementById('nodo-latitud').value = nodo.latitud || '';
    document.getElementById('nodo-longitud').value = nodo.longitud || '';
    document.getElementById('nodo-estado').value = nodo.estado || 'activo';
    toggleNodoForm(false, false);
}

async function eliminarNodo(id) {
    if (!confirm('¿Deseas eliminar este nodo?')) return;
    const response = await fetch('api_nodos.php?id=' + id, { method: 'DELETE' });
    const result = await response.json();
    alert(result.message || 'Nodo eliminado');
    if (result.status === 'success') {
        await loadNodos();
    }
}

// ---------------- OLTs ----------------

async function loadOlts() {
    try {
        const response = await fetch('api_olts.php');
        const result = await response.json();
        const container = document.getElementById('olts-list');
        if (!container || result.status !== 'success') {
            return;
        }
        const olts = Array.isArray(result.olts) ? result.olts : [];
        window.oltsCache = olts;
        renderOltsPage(1);
        refreshMapIfVisible();
        populateInfraSelectors();
    } catch (error) {
        console.error('No se pudieron cargar las OLTs:', error);
    }
}

function renderOltsPage(page = 1) {
    const container = document.getElementById('olts-list');
    const pagination = document.getElementById('olts-pagination');
    if (!container) {
        return;
    }

    const olts = Array.isArray(window.oltsCache) ? window.oltsCache : [];
    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(olts.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentOltsPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleOlts = olts.slice(start, start + pageSize);

    container.innerHTML = visibleOlts.length ? visibleOlts.map(olt => `
        <tr>
            <td><strong>${escapeHtml(olt.codigo || 'Sin código')}</strong></td>
            <td>${escapeHtml(olt.marca_modelo)}</td>
            <td>${escapeHtml(olt.puertos_pon)}</td>
            <td>${escapeHtml(olt.ip_gestion || '-')}</td>
            <td>${escapeHtml(olt.nombre_nodo || 'Sin nodo')}</td>
            <td>${escapeHtml(olt.total_naps ?? 0)}</td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarOlt(${olt.id_olt})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarOlt(${olt.id_olt})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="7">No hay OLTs registradas.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderOltsPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderOltsPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function toggleOltForm(forceClose = false, reset = true) {
    const modal = document.getElementById('olt-modal');
    const form = document.getElementById('olt-form');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    } else if (form) {
        form.style.display = forceClose ? 'none' : (form.style.display === 'none' ? 'block' : 'none');
    }
    if (!forceClose && reset) {
        document.getElementById('olt-id').value = '';
        document.getElementById('olt-codigo').value = '';
        document.getElementById('olt-marca').value = '';
        document.getElementById('olt-puertos').value = '';
        document.getElementById('olt-ip').value = '';
        document.getElementById('olt-nodo').value = '';
    }
}

async function guardarOlt() {
    const id = document.getElementById('olt-id').value;
    const payload = {
        codigo: document.getElementById('olt-codigo').value.trim(),
        marca_modelo: document.getElementById('olt-marca').value.trim(),
        puertos_pon: document.getElementById('olt-puertos').value.trim(),
        ip_gestion: document.getElementById('olt-ip').value.trim(),
        id_nodos: document.getElementById('olt-nodo').value
    };

    if (!payload.marca_modelo || !payload.id_nodos) {
        alert('Marca/modelo y nodo son obligatorios');
        return;
    }

    const response = await fetch('api_olts.php', {
        method: id ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(id ? { ...payload, id_olt: id } : payload)
    });
    const result = await response.json();
    alert(result.message || 'Operación realizada');
    if (result.status === 'success') {
        toggleOltForm(true);
        await loadOlts();
    }
}

function editarOlt(id) {
    const olt = (window.oltsCache || []).find(item => Number(item.id_olt) === Number(id));
    if (!olt) return;
    document.getElementById('olt-id').value = olt.id_olt;
    document.getElementById('olt-codigo').value = olt.codigo || '';
    document.getElementById('olt-marca').value = olt.marca_modelo || '';
    document.getElementById('olt-puertos').value = olt.puertos_pon || '';
    document.getElementById('olt-ip').value = olt.ip_gestion || '';
    document.getElementById('olt-nodo').value = olt.id_nodos || '';
    toggleOltForm(false, false);
}

async function eliminarOlt(id) {
    if (!confirm('¿Deseas eliminar esta OLT?')) return;
    const response = await fetch('api_olts.php?id=' + id, { method: 'DELETE' });
    const result = await response.json();
    alert(result.message || 'OLT eliminada');
    if (result.status === 'success') {
        await loadOlts();
    }
}

// ---------------- NAPs ----------------

function renderNapsPage(page = 1) {
    const container = document.getElementById('naps-list');
    const pagination = document.getElementById('naps-pagination');
    if (!container) {
        return;
    }

    const naps = Array.isArray(window.napsCache) ? window.napsCache : [];
    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(naps.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentNapsPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleNaps = naps.slice(start, start + pageSize);

    container.innerHTML = visibleNaps.length ? visibleNaps.map(nap => `
        <tr data-nap-id="${nap.id_nap}">
            <td><strong>${escapeHtml(nap.codigo)}</strong></td>
            <td>${escapeHtml(nap.cantidad_puertos_max)}</td>
            <td>${escapeHtml(nap.ubicacion_fisica || '-')}</td>
            <td>${escapeHtml(nap.olt_codigo || nap.olt_nombre || 'Sin OLT')}</td>
            <td>${escapeHtml(nap.nodo_nombre || '-')}</td>
            <td>
                <button type="button" onclick='verClientesNap(${nap.id_nap})' style="width:auto; padding:6px 10px; background:#eef4ff; color:#2563eb; font-weight:600;">
                    ${escapeHtml(nap.total_clientes ?? 0)} cliente(s)
                </button>
            </td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarNap(${nap.id_nap})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarNap(${nap.id_nap})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="7">No hay NAPs registradas.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderNapsPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderNapsPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

async function loadNaps() {
    try {
        const response = await fetch('api_naps.php');
        const result = await response.json();
        const naps = Array.isArray(result.naps) ? result.naps : [];
        window.napsCache = naps;

        const container = document.getElementById('naps-list');
        if (container && result.status === 'success') {
            renderNapsPage(1);
            populateInfraSelectors();
        }
    } catch (error) {
        console.error('No se pudieron cargar las NAPs:', error);
    }
}

function verClientesNap(id) {
    const nap = (window.napsCache || []).find(item => Number(item.id_nap) === Number(id));
    if (!nap) {
        return;
    }

    const total = Number(nap.total_clientes || 0);
    if (!total) {
        alert(`La NAP ${nap.codigo} no tiene clientes conectados actualmente.`);
        return;
    }

    const lista = nap.clientes_conectados ? nap.clientes_conectados : 'Sin detalle disponible';
    alert(`Clientes conectados a la NAP ${nap.codigo} (${total}):\n\n${lista}`);
}

function toggleNapForm(forceClose = false, reset = true) {
    const modal = document.getElementById('nap-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('nap-id').value = '';
        document.getElementById('nap-codigo').value = '';
        document.getElementById('nap-puertos').value = '';
        document.getElementById('nap-ubicacion').value = '';
        document.getElementById('nap-latitud').value = '';
        document.getElementById('nap-longitud').value = '';
        document.getElementById('nap-olt').value = '';
    }
}

function toggleUserModal(forceClose = false, reset = true) {
    const modal = document.getElementById('user-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('new-user-username').value = '';
        document.getElementById('new-user-password').value = '';
        document.getElementById('new-user-role').value = '';
        const feedback = document.getElementById('user-feedback');
        if (feedback) {
            feedback.textContent = '';
        }
    }
}

async function guardarNap() {
    const id = document.getElementById('nap-id').value;
    const latitudTexto = document.getElementById('nap-latitud').value.trim();
    const longitudTexto = document.getElementById('nap-longitud').value.trim();
    const latitud = normalizarCoordenada(latitudTexto);
    const longitud = normalizarCoordenada(longitudTexto);
    const payload = {
        codigo: document.getElementById('nap-codigo').value.trim(),
        cantidad_puertos_max: document.getElementById('nap-puertos').value.trim(),
        ubicacion_fisica: document.getElementById('nap-ubicacion').value.trim(),
        latitud: latitud ?? latitudTexto,
        longitud: longitud ?? longitudTexto,
        id_olts: document.getElementById('nap-olt').value
    };

    if (!payload.codigo || !payload.latitud || !payload.longitud || !payload.id_olts) {
        alert('Código, latitud, longitud y OLT son obligatorios');
        return;
    }

    if ((latitudTexto !== '' && latitud === null) || (longitudTexto !== '' && longitud === null)) {
        alert('La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).');
        return;
    }

    const response = await fetch('api_naps.php', {
        method: id ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(id ? { ...payload, id_nap: id } : payload)
    });
    const result = await response.json();
    alert(result.message || 'Operación realizada');
    if (result.status === 'success') {
        toggleNapForm(true);
        await loadNaps();
    }
}

function editarNap(id) {
    const nap = (window.napsCache || []).find(item => Number(item.id_nap) === Number(id));
    if (!nap) return;
    document.getElementById('nap-id').value = nap.id_nap;
    document.getElementById('nap-codigo').value = nap.codigo || '';
    document.getElementById('nap-puertos').value = nap.cantidad_puertos_max || '';
    document.getElementById('nap-ubicacion').value = nap.ubicacion_fisica || '';
    document.getElementById('nap-latitud').value = nap.latitud || '';
    document.getElementById('nap-longitud').value = nap.longitud || '';
    document.getElementById('nap-olt').value = nap.id_olts || '';
    toggleNapForm(false, false);
}

async function eliminarNap(id) {
    if (!confirm('¿Deseas eliminar esta NAP?')) return;
    const response = await fetch('api_naps.php?id=' + id, { method: 'DELETE' });
    const result = await response.json();
    alert(result.message || 'NAP eliminada');
    if (result.status === 'success') {
        await loadNaps();
    }
}

// ============================================================================
// MÓDULO SERVICIOS
// ============================================================================

async function loadServicios() {
    try {
        const response = await fetch('api_servicios.php');
        const result = await response.json();
        window.serviciosCache = Array.isArray(result.servicios) ? result.servicios : [];
    } catch (error) {
        console.error('No se pudieron cargar los servicios:', error);
    }
}

function populateServicioSelectores() {
    const napSelect = document.getElementById('servicio-nap');
    const planSelect = document.getElementById('servicio-plan');

    if (napSelect) {
        const previousValue = napSelect.value;
        napSelect.innerHTML = ['<option value="">Sin NAP asignada</option>', ...(window.napsCache || []).map(nap => {
            const oltRef = nap.olt_codigo || nap.olt_nombre || '';
            const label = oltRef ? `${nap.codigo} (${oltRef})` : nap.codigo;
            return `<option value="${nap.id_nap}">${escapeHtml(label)}</option>`;
        })].join('');
        if (previousValue && napSelect.querySelector(`option[value="${previousValue}"]`)) {
            napSelect.value = previousValue;
        }
    }

    if (planSelect) {
        const previousValue = planSelect.value;
        const planes = (window.planesCache && window.planesCache.length) ? window.planesCache : [{ id_plan: 1, nombre: 'Plan Básico' }];
        planSelect.innerHTML = planes.map(plan => `<option value="${plan.id_plan}">${escapeHtml(plan.nombre)}</option>`).join('');
        if (previousValue && planSelect.querySelector(`option[value="${previousValue}"]`)) {
            planSelect.value = previousValue;
        }
    }
}

function toggleServicioForm(forceClose = false, reset = true) {
    const modal = document.getElementById('servicio-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('servicio-id').value = '';
        document.getElementById('servicio-cliente-id').value = '';
        document.getElementById('servicio-alias').value = '';
        document.getElementById('servicio-direccion').value = '';
        document.getElementById('servicio-latitud').value = '';
        document.getElementById('servicio-longitud').value = '';
        document.getElementById('servicio-nap').value = '';
        document.getElementById('servicio-plan').value = '1';
        document.getElementById('servicio-estado').value = 'pendiente';
    }
}

function abrirNuevoServicio(idCliente) {
    toggleServicioForm(false, true);
    populateServicioSelectores();
    document.getElementById('servicio-cliente-id').value = idCliente;
    const modal = document.getElementById('servicio-modal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

async function guardarServicio() {
    const id = document.getElementById('servicio-id').value;
    const idCliente = document.getElementById('servicio-cliente-id').value;
    const latitudTexto = document.getElementById('servicio-latitud').value.trim();
    const longitudTexto = document.getElementById('servicio-longitud').value.trim();
    const latitud = normalizarCoordenada(latitudTexto);
    const longitud = normalizarCoordenada(longitudTexto);
    const payload = {
        id_cliente: idCliente,
        alias: document.getElementById('servicio-alias').value.trim(),
        direccion_texto: document.getElementById('servicio-direccion').value.trim(),
        latitud: latitud ?? (latitudTexto ? latitudTexto : ''),
        longitud: longitud ?? (longitudTexto ? longitudTexto : ''),
        id_naps: document.getElementById('servicio-nap').value,
        id_plan: document.getElementById('servicio-plan').value,
        estado_comercial: document.getElementById('servicio-estado').value
    };

    if (!payload.direccion_texto) {
        alert('La dirección del servicio es obligatoria');
        return;
    }

    if ((latitudTexto !== '' && latitud === null) || (longitudTexto !== '' && longitud === null)) {
        alert('La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).');
        return;
    }

    try {
        const response = await fetch('api_servicios.php', {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(id ? { ...payload, id_servicio: id } : payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            toggleServicioForm(true);
            await loadServicios();
            await loadClientes(window.currentClienteSearch);
            populateFinanzasSelectors();
            const cliente = getClienteById(idCliente);
            if (cliente) {
                renderClientProfile(cliente);
            }
        }
    } catch (error) {
        console.error('Error guardando servicio:', error);
        alert('No se pudo guardar el servicio.');
    }
}

function editarServicio(id) {
    const servicio = (window.serviciosCache || []).find(sv => Number(sv.id_servicio) === Number(id));
    if (!servicio) return;

    toggleServicioForm(false, false);
    populateServicioSelectores();

    document.getElementById('servicio-id').value = servicio.id_servicio;
    document.getElementById('servicio-cliente-id').value = servicio.id_cliente;
    document.getElementById('servicio-alias').value = servicio.alias || '';
    document.getElementById('servicio-direccion').value = servicio.direccion_texto || '';
    document.getElementById('servicio-latitud').value = servicio.latitud_instalacion || '';
    document.getElementById('servicio-longitud').value = servicio.longitud_instalacion || '';
    document.getElementById('servicio-nap').value = servicio.id_naps || '';
    document.getElementById('servicio-plan').value = servicio.id_plan || '1';
    document.getElementById('servicio-estado').value = servicio.estado_comercial || 'pendiente';

    const modal = document.getElementById('servicio-modal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

async function eliminarServicio(id) {
    if (!confirm('¿Deseas eliminar este servicio? Solo es posible si no tiene facturas ni contratos asociados.')) return;

    const servicio = (window.serviciosCache || []).find(sv => Number(sv.id_servicio) === Number(id));

    try {
        const response = await fetch('api_servicios.php?id=' + id, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message || 'Servicio eliminado');
        if (result.status === 'success') {
            await loadServicios();
            await loadClientes(window.currentClienteSearch);
            populateFinanzasSelectors();
            if (servicio) {
                const cliente = getClienteById(servicio.id_cliente);
                if (cliente) {
                    renderClientProfile(cliente);
                }
            }
        }
    } catch (error) {
        console.error('Error eliminando servicio:', error);
        alert('No se pudo eliminar el servicio.');
    }
}

// ============================================================================
// MÓDULO EQUIPOS
// ============================================================================

async function loadEquipos() {
    try {
        const response = await fetch('api_equipos.php');
        const result = await response.json();
        const container = document.getElementById('equipos-list');
        if (!container || result.status !== 'success') {
            return;
        }
        const equipos = Array.isArray(result.equipos) ? result.equipos : [];
        window.equiposCache = equipos;
        renderEquiposPage(1);
        populateEquipoSelectores();
    } catch (error) {
        console.error('No se pudieron cargar los equipos:', error);
    }
}

function renderEquiposPage(page = 1) {
    const container = document.getElementById('equipos-list');
    const pagination = document.getElementById('equipos-pagination');
    if (!container) {
        return;
    }

    const equipos = Array.isArray(window.equiposCache) ? window.equiposCache : [];
    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(equipos.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentEquiposPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleEquipos = equipos.slice(start, start + pageSize);

    container.innerHTML = visibleEquipos.length ? visibleEquipos.map(equipo => `
        <tr>
            <td><strong>${escapeHtml(equipo.tipo || 'Sin tipo')}</strong></td>
            <td>${escapeHtml([equipo.marca, equipo.modelo].filter(Boolean).join(' / ') || '-')}</td>
            <td>${escapeHtml(equipo.direccion_mac || '-')}</td>
            <td>${escapeHtml(equipo.nap_codigo || 'Sin NAP')}</td>
            <td>${escapeHtml(equipo.num_puerto_nap || '-')}</td>
            <td><span class="status ${equipo.estado_fisico === 'operativo' ? 'status-ok' : equipo.estado_fisico === 'averiado' ? 'status-alert' : 'status-warning'}">${escapeHtml(equipo.estado_fisico || 'stock')}</span></td>
            <td>${escapeHtml(equipo.id_servicio ? `${equipo.nombres || ''} ${equipo.apellidos || ''}`.trim() || 'Cliente' : 'Sin asignar')}</td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarEquipo(${equipo.id_equipo})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarEquipo(${equipo.id_equipo})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="8">No hay equipos registrados.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderEquiposPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderEquiposPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function toggleEquipoForm(forceClose = false, reset = true) {
    const modal = document.getElementById('equipo-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('equipo-id').value = '';
        document.getElementById('equipo-tipo').value = 'ONU';
        document.getElementById('equipo-marca').value = '';
        document.getElementById('equipo-modelo').value = '';
        document.getElementById('equipo-mac').value = '';
        document.getElementById('equipo-puerto').value = '';
        document.getElementById('equipo-estado').value = 'stock';
        document.getElementById('equipo-nap').value = '';
        document.getElementById('equipo-servicio').value = '';
    }
}

async function guardarEquipo() {
    const id = document.getElementById('equipo-id').value;
    const tipo = document.getElementById('equipo-tipo').value;
    const marca = document.getElementById('equipo-marca').value.trim();
    const modelo = document.getElementById('equipo-modelo').value.trim();
    const direccionMac = document.getElementById('equipo-mac').value.trim().toUpperCase();
    const numPuertoNap = document.getElementById('equipo-puerto').value.trim();
    const estadoFisico = document.getElementById('equipo-estado').value;
    const idNaps = document.getElementById('equipo-nap').value;
    const idServicio = document.getElementById('equipo-servicio').value;
    const payload = {
        tipo,
        marca,
        modelo,
        direccion_mac: direccionMac,
        num_puerto_nap: numPuertoNap,
        estado_fisico: estadoFisico,
        id_naps: idNaps,
        id_servicio: idServicio || ''
    };

    if (!payload.tipo || !payload.direccion_mac || !payload.num_puerto_nap || !payload.id_naps) {
        alert('Tipo, MAC, puerto y NAP son obligatorios');
        return;
    }

    try {
        const response = await fetch('api_equipos.php', {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(id ? { ...payload, id_equipo: id } : payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            toggleEquipoForm(true);
            await loadEquipos();
        }
    } catch (error) {
        console.error('Error guardando equipo:', error);
        alert('No se pudo guardar el equipo.');
    }
}

function editarEquipo(id) {
    const equipo = (window.equiposCache || []).find(item => Number(item.id_equipo) === Number(id));
    if (!equipo) return;
    document.getElementById('equipo-id').value = equipo.id_equipo;
    document.getElementById('equipo-tipo').value = equipo.tipo || 'ONU';
    document.getElementById('equipo-marca').value = equipo.marca || '';
    document.getElementById('equipo-modelo').value = equipo.modelo || '';
    document.getElementById('equipo-mac').value = equipo.direccion_mac || '';
    document.getElementById('equipo-puerto').value = equipo.num_puerto_nap || '';
    document.getElementById('equipo-estado').value = equipo.estado_fisico || 'stock';
    document.getElementById('equipo-nap').value = equipo.id_naps || '';
    document.getElementById('equipo-servicio').value = equipo.id_servicio || '';
    populateEquipoSelectores();
    toggleEquipoForm(false, false);
}

async function eliminarEquipo(id) {
    if (!confirm('¿Deseas eliminar este equipo?')) return;
    try {
        const response = await fetch('api_equipos.php?id=' + id, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message || 'Equipo eliminado');
        if (result.status === 'success') {
            await loadEquipos();
        }
    } catch (error) {
        console.error('Error eliminando equipo:', error);
        alert('No se pudo eliminar el equipo.');
    }
}

// ============================================================================
// MÓDULO FINANZAS (Facturas, Pagos, Planes, Contratos)
// ============================================================================

async function loadFinanzas() {
    await loadServicios();
    await loadPlanes();
    await loadFacturas();
    await loadPagos();
    await loadContratos();
    populateFinanzasSelectors();
    updateDashboardData();
}

function getFacturaEstadoClass(estado) {
    const value = String(estado || '').toLowerCase();
    if (value === 'pagada') return 'status-ok';
    if (value === 'parcial' || value === 'pendiente') return 'status-warning';
    return 'status-alert';
}

function getPagoEstadoClass(estado) {
    const value = String(estado || '').toLowerCase();
    if (value === 'validado') return 'status-ok';
    if (value === 'pendiente') return 'status-warning';
    return 'status-alert';
}

function getContratoEstadoClass(estado) {
    const value = String(estado || '').toLowerCase();
    if (value === 'vigente') return 'status-ok';
    if (value === 'vencido') return 'status-warning';
    return 'status-alert';
}

function toMysqlDatetime(value) {
    if (!value) return '';
    const normalized = value.replace('T', ' ');
    return normalized.length === 16 ? normalized + ':00' : normalized;
}

function toDatetimeLocalValue(value) {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
}

// ---------------- Facturas ----------------

async function loadFacturas() {
    try {
        const response = await fetch('api_facturas.php');
        const result = await response.json();
        window.facturasCache = Array.isArray(result.facturas) ? result.facturas : [];
        renderFacturasPage(1);
    } catch (error) {
        console.error('No se pudieron cargar las facturas:', error);
    }
}

function renderFacturasPage(page = 1) {
    const container = document.getElementById('facturas-list');
    const pagination = document.getElementById('facturas-pagination');
    if (!container) {
        return;
    }

    const facturas = Array.isArray(window.facturasCache) ? window.facturasCache : [];
    const totalPages = Math.max(1, Math.ceil(facturas.length / window.facturaPageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentFacturasPage = safePage;

    const start = (safePage - 1) * window.facturaPageSize;
    const visibleFacturas = facturas.slice(start, start + window.facturaPageSize);

    container.innerHTML = visibleFacturas.length ? visibleFacturas.map(factura => `
        <tr>
            <td>${escapeHtml(factura.nombres ? `${factura.nombres} ${factura.apellidos || ''}`.trim() : 'Sin cliente')}</td>
            <td>${escapeHtml(factura.periodo)}</td>
            <td>$${Number(factura.monto || 0).toFixed(2)}</td>
            <td>$${Number(factura.total_pagado || 0).toFixed(2)}</td>
            <td>${escapeHtml(factura.fecha_vencimiento)}</td>
            <td><span class="status ${getFacturaEstadoClass(factura.estado)}">${escapeHtml(factura.estado)}</span></td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarFactura(${factura.id_factura})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarFactura(${factura.id_factura})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="7">No hay facturas registradas.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderFacturasPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderFacturasPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function toggleFacturaForm(forceClose = false, reset = true) {
    const modal = document.getElementById('factura-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('factura-id').value = '';
        const servicioSelect = document.getElementById('factura-servicio');
        if (servicioSelect) servicioSelect.value = '';
        document.getElementById('factura-periodo').value = '';
        document.getElementById('factura-monto').value = '';
        document.getElementById('factura-fecha-emision').value = '';
        document.getElementById('factura-fecha-vencimiento').value = '';
        document.getElementById('factura-estado').value = 'pendiente';
    }
}

async function guardarFactura() {
    const id = document.getElementById('factura-id').value;
    const payload = {
        id_servicio: document.getElementById('factura-servicio').value,
        periodo: document.getElementById('factura-periodo').value.trim(),
        monto: document.getElementById('factura-monto').value,
        fecha_emision: document.getElementById('factura-fecha-emision').value,
        fecha_vencimiento: document.getElementById('factura-fecha-vencimiento').value,
        estado: document.getElementById('factura-estado').value
    };

    if (!payload.id_servicio || !payload.periodo || !payload.monto || !payload.fecha_emision || !payload.fecha_vencimiento) {
        alert('Servicio, periodo, monto, fecha de emisión y fecha de vencimiento son obligatorios');
        return;
    }

    try {
        const response = await fetch('api_facturas.php', {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(id ? { ...payload, id_factura: id } : payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            toggleFacturaForm(true);
            await loadFacturas();
        }
    } catch (error) {
        console.error('Error guardando factura:', error);
        alert('No se pudo guardar la factura.');
    }
}

function editarFactura(id) {
    const factura = (window.facturasCache || []).find(item => Number(item.id_factura) === Number(id));
    if (!factura) return;
    document.getElementById('factura-id').value = factura.id_factura;
    const servicioSelect = document.getElementById('factura-servicio');
    if (servicioSelect) servicioSelect.value = factura.id_servicio || '';
    document.getElementById('factura-periodo').value = factura.periodo || '';
    document.getElementById('factura-monto').value = factura.monto || '';
    document.getElementById('factura-fecha-emision').value = factura.fecha_emision || '';
    document.getElementById('factura-fecha-vencimiento').value = factura.fecha_vencimiento || '';
    document.getElementById('factura-estado').value = factura.estado || 'pendiente';
    toggleFacturaForm(false, false);
}

async function eliminarFactura(id) {
    if (!confirm('¿Deseas eliminar esta factura?')) return;
    try {
        const response = await fetch('api_facturas.php?id=' + id, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message || 'Factura eliminada');
        if (result.status === 'success') {
            await loadFacturas();
        }
    } catch (error) {
        console.error('Error eliminando factura:', error);
        alert('No se pudo eliminar la factura.');
    }
}

// ---------------- Pagos ----------------

function populatePagoFacturaOptions() {
    const servicioSelect = document.getElementById('pago-servicio');
    const facturaSelect = document.getElementById('pago-factura');
    if (!servicioSelect || !facturaSelect) {
        return;
    }
    const idServicio = servicioSelect.value;
    const previousValue = facturaSelect.value;
    const facturasDelServicio = (window.facturasCache || []).filter(factura =>
        String(factura.id_servicio) === String(idServicio) &&
        factura.estado !== 'pagada' &&
        factura.estado !== 'anulada'
    );
    facturaSelect.innerHTML = ['<option value="">Sin factura asociada (a cuenta)</option>', ...facturasDelServicio.map(factura =>
        `<option value="${factura.id_factura}">${escapeHtml(factura.periodo)} — $${Number(factura.monto).toFixed(2)} (${escapeHtml(factura.estado)})</option>`
    )].join('');
    if (previousValue && facturaSelect.querySelector(`option[value="${previousValue}"]`)) {
        facturaSelect.value = previousValue;
    }
}

async function loadPagos() {
    try {
        const response = await fetch('api_pagos.php');
        const result = await response.json();
        window.pagosCache = Array.isArray(result.pagos) ? result.pagos : [];
        renderPagosPage(1);
    } catch (error) {
        console.error('No se pudieron cargar los pagos:', error);
    }
}

function renderPagosPage(page = 1) {
    const container = document.getElementById('pagos-list');
    const pagination = document.getElementById('pagos-pagination');
    if (!container) {
        return;
    }

    const pagos = Array.isArray(window.pagosCache) ? window.pagosCache : [];
    const totalPages = Math.max(1, Math.ceil(pagos.length / window.pagoPageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentPagosPage = safePage;

    const start = (safePage - 1) * window.pagoPageSize;
    const visiblePagos = pagos.slice(start, start + window.pagoPageSize);

    container.innerHTML = visiblePagos.length ? visiblePagos.map(pago => {
        const accionesValidacion = pago.estado === 'pendiente' ? `
            <button type="button" onclick='actualizarEstadoPago(${pago.id_pago}, "validado")' style="width:auto; padding:8px 10px; background:#16a34a;">Validar</button>
            <button type="button" onclick='actualizarEstadoPago(${pago.id_pago}, "rechazado")' style="width:auto; padding:8px 10px; background:#dc2626;">Rechazar</button>
        ` : '';
        return `
        <tr>
            <td>${escapeHtml(pago.nombres ? `${pago.nombres} ${pago.apellidos || ''}`.trim() : 'Sin cliente')}</td>
            <td>${escapeHtml(pago.factura_periodo || 'A cuenta')}</td>
            <td>$${Number(pago.monto || 0).toFixed(2)}</td>
            <td>${escapeHtml(pago.fecha_pago)}</td>
            <td>${escapeHtml(pago.metodo_pago || '-')}</td>
            <td><span class="status ${getPagoEstadoClass(pago.estado)}">${escapeHtml(pago.estado)}</span></td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    ${accionesValidacion}
                    <button type="button" onclick='editarPago(${pago.id_pago})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarPago(${pago.id_pago})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `;
    }).join('') : '<tr><td colspan="7">No hay pagos registrados.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderPagosPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderPagosPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function togglePagoForm(forceClose = false, reset = true) {
    const modal = document.getElementById('pago-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('pago-id').value = '';
        const servicioSelect = document.getElementById('pago-servicio');
        if (servicioSelect) servicioSelect.value = '';
        populatePagoFacturaOptions();
        document.getElementById('pago-monto').value = '';
        document.getElementById('pago-fecha').value = '';
        document.getElementById('pago-metodo').value = '';
        document.getElementById('pago-referencia').value = '';
    }
}

async function guardarPago() {
    const id = document.getElementById('pago-id').value;
    const servicioSelect = document.getElementById('pago-servicio');
    const selectedOption = servicioSelect ? servicioSelect.options[servicioSelect.selectedIndex] : null;
    const idCliente = selectedOption ? selectedOption.getAttribute('data-cliente') : '';

    const payload = {
        id_servicio: servicioSelect ? servicioSelect.value : '',
        id_cliente: idCliente,
        id_factura: document.getElementById('pago-factura').value,
        monto: document.getElementById('pago-monto').value,
        fecha_pago: toMysqlDatetime(document.getElementById('pago-fecha').value),
        metodo_pago: document.getElementById('pago-metodo').value,
        referencia_bancaria: document.getElementById('pago-referencia').value.trim(),
        origen: 'backoffice'
    };

    if (!payload.id_servicio || !payload.id_cliente || !payload.monto || !payload.fecha_pago) {
        alert('Servicio, monto y fecha de pago son obligatorios');
        return;
    }

    const stored = getStoredSession();
    try {
        const response = await fetch('api_pagos.php', {
            method: id ? 'PUT' : 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(stored && stored.token ? { 'X-Session-Token': stored.token } : {})
            },
            body: JSON.stringify(id ? { ...payload, id_pago: id } : payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            togglePagoForm(true);
            await loadFacturas();
            await loadPagos();
        }
    } catch (error) {
        console.error('Error guardando pago:', error);
        alert('No se pudo guardar el pago.');
    }
}

function editarPago(id) {
    const pago = (window.pagosCache || []).find(item => Number(item.id_pago) === Number(id));
    if (!pago) return;
    document.getElementById('pago-id').value = pago.id_pago;
    const servicioSelect = document.getElementById('pago-servicio');
    if (servicioSelect) {
        servicioSelect.value = pago.id_servicio || '';
        populatePagoFacturaOptions();
    }
    document.getElementById('pago-factura').value = pago.id_factura || '';
    document.getElementById('pago-monto').value = pago.monto || '';
    document.getElementById('pago-fecha').value = toDatetimeLocalValue(pago.fecha_pago);
    document.getElementById('pago-metodo').value = pago.metodo_pago || '';
    document.getElementById('pago-referencia').value = pago.referencia_bancaria || '';
    togglePagoForm(false, false);
}

async function actualizarEstadoPago(id, estado) {
    const label = estado === 'validado' ? 'validar' : 'rechazar';
    if (!confirm(`¿Deseas ${label} este pago?`)) return;

    const stored = getStoredSession();
    try {
        const response = await fetch('api_pagos.php', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                ...(stored && stored.token ? { 'X-Session-Token': stored.token } : {})
            },
            body: JSON.stringify({ id_pago: id, estado })
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            await loadFacturas();
            await loadPagos();
        }
    } catch (error) {
        console.error('Error actualizando el estado del pago:', error);
        alert('No se pudo actualizar el estado del pago.');
    }
}

async function eliminarPago(id) {
    if (!confirm('¿Deseas eliminar este pago?')) return;
    try {
        const response = await fetch('api_pagos.php?id=' + id, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message || 'Pago eliminado');
        if (result.status === 'success') {
            await loadFacturas();
            await loadPagos();
        }
    } catch (error) {
        console.error('Error eliminando pago:', error);
        alert('No se pudo eliminar el pago.');
    }
}

// ---------------- Planes ----------------

async function loadPlanes() {
    try {
        const response = await fetch('api_planes.php');
        const result = await response.json();
        window.planesCache = Array.isArray(result.planes) ? result.planes : [];
        renderPlanesPage(1);
    } catch (error) {
        console.error('No se pudieron cargar los planes:', error);
    }
}

function renderPlanesPage(page = 1) {
    const container = document.getElementById('planes-list');
    const pagination = document.getElementById('planes-pagination');
    if (!container) {
        return;
    }

    const planes = Array.isArray(window.planesCache) ? window.planesCache : [];
    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(planes.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentPlanesPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visiblePlanes = planes.slice(start, start + pageSize);

    container.innerHTML = visiblePlanes.length ? visiblePlanes.map(plan => `
        <tr>
            <td><strong>${escapeHtml(plan.nombre)}</strong></td>
            <td>${escapeHtml(plan.velocidad)}</td>
            <td>$${Number(plan.precio_mensual || 0).toFixed(2)}</td>
            <td>${escapeHtml(plan.moneda)}</td>
            <td>${escapeHtml(plan.total_servicios ?? 0)}</td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarPlan(${plan.id_plan})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarPlan(${plan.id_plan})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="6">No hay planes registrados.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderPlanesPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderPlanesPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function togglePlanForm(forceClose = false, reset = true) {
    const modal = document.getElementById('plan-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('plan-id').value = '';
        document.getElementById('plan-nombre').value = '';
        document.getElementById('plan-velocidad').value = '';
        document.getElementById('plan-precio').value = '';
        document.getElementById('plan-moneda').value = 'USD';
    }
}

async function guardarPlan() {
    const id = document.getElementById('plan-id').value;
    const payload = {
        nombre: document.getElementById('plan-nombre').value.trim(),
        velocidad: document.getElementById('plan-velocidad').value.trim(),
        precio_mensual: document.getElementById('plan-precio').value,
        moneda: document.getElementById('plan-moneda').value.trim() || 'USD'
    };

    if (!payload.nombre || !payload.velocidad || !payload.precio_mensual) {
        alert('Nombre, velocidad y precio mensual son obligatorios');
        return;
    }

    try {
        const response = await fetch('api_planes.php', {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(id ? { ...payload, id_plan: id } : payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            togglePlanForm(true);
            await loadPlanes();
        }
    } catch (error) {
        console.error('Error guardando plan:', error);
        alert('No se pudo guardar el plan.');
    }
}

function editarPlan(id) {
    const plan = (window.planesCache || []).find(item => Number(item.id_plan) === Number(id));
    if (!plan) return;
    document.getElementById('plan-id').value = plan.id_plan;
    document.getElementById('plan-nombre').value = plan.nombre || '';
    document.getElementById('plan-velocidad').value = plan.velocidad || '';
    document.getElementById('plan-precio').value = plan.precio_mensual || '';
    document.getElementById('plan-moneda').value = plan.moneda || 'USD';
    togglePlanForm(false, false);
}

async function eliminarPlan(id) {
    if (!confirm('¿Deseas eliminar este plan?')) return;
    try {
        const response = await fetch('api_planes.php?id=' + id, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message || 'Plan eliminado');
        if (result.status === 'success') {
            await loadPlanes();
        }
    } catch (error) {
        console.error('Error eliminando plan:', error);
        alert('No se pudo eliminar el plan.');
    }
}

// ---------------- Contratos ----------------

async function loadContratos() {
    try {
        const response = await fetch('api_contratos.php');
        const result = await response.json();
        window.contratosCache = Array.isArray(result.contratos) ? result.contratos : [];
        renderContratosPage(1);
    } catch (error) {
        console.error('No se pudieron cargar los contratos:', error);
    }
}

function renderContratosPage(page = 1) {
    const container = document.getElementById('contratos-list');
    const pagination = document.getElementById('contratos-pagination');
    if (!container) {
        return;
    }

    const contratos = Array.isArray(window.contratosCache) ? window.contratosCache : [];
    const pageSize = 8;
    const totalPages = Math.max(1, Math.ceil(contratos.length / pageSize));
    const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
    window.currentContratosPage = safePage;

    const start = (safePage - 1) * pageSize;
    const visibleContratos = contratos.slice(start, start + pageSize);

    container.innerHTML = visibleContratos.length ? visibleContratos.map(contrato => `
        <tr>
            <td>${escapeHtml(contrato.nombres ? `${contrato.nombres} ${contrato.apellidos || ''}`.trim() : 'Sin cliente')}</td>
            <td>${escapeHtml(contrato.servicio_alias || contrato.direccion_texto || 'Servicio')}</td>
            <td>${escapeHtml(contrato.fecha_inicio)}</td>
            <td>${escapeHtml(contrato.fecha_fin || 'Indefinido')}</td>
            <td>${escapeHtml(contrato.tipo_contrato)}</td>
            <td><span class="status ${getContratoEstadoClass(contrato.estado)}">${escapeHtml(contrato.estado)}</span></td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" onclick='editarContrato(${contrato.id_contrato})' style="width:auto; padding:8px 10px;">Editar</button>
                    <button type="button" onclick='eliminarContrato(${contrato.id_contrato})' style="width:auto; padding:8px 10px; background:#dc2626;">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="7">No hay contratos registrados.</td></tr>';

    if (pagination) {
        pagination.innerHTML = `
            <div class="pagination-controls">
                <button type="button" onclick="renderContratosPage(${Math.max(1, safePage - 1)})" ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
                <span>Página ${safePage} / ${totalPages}</span>
                <button type="button" onclick="renderContratosPage(${Math.min(totalPages, safePage + 1)})" ${safePage >= totalPages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;
    }
}

function refreshContratoDraft() {
    const draftPreview = document.getElementById('contrato-draft-preview');
    if (!draftPreview) {
        return;
    }

    const selectedServiceId = document.getElementById('contrato-servicio')?.value || '';
    const selectedService = (window.serviciosCache || []).find(item => String(item.id_servicio) === String(selectedServiceId));
    const clienteNombre = selectedService ? [selectedService.nombres, selectedService.apellidos].filter(Boolean).join(' ').trim() || selectedService.cedula || 'cliente' : 'cliente';
    const direccionTexto = selectedService?.direccion_texto || 'sin dirección registrada';
    const aliasServicio = selectedService?.alias ? ` (${selectedService.alias})` : '';
    const fechaInicio = document.getElementById('contrato-fecha-inicio')?.value || 'fecha por definir';
    const fechaFin = document.getElementById('contrato-fecha-fin')?.value || 'sin fecha de fin definida';
    const tipoContrato = document.getElementById('contrato-tipo')?.value || 'indefinido';
    const observaciones = document.getElementById('contrato-observaciones')?.value?.trim() || 'Sin observaciones adicionales.';
    const tipoEtiqueta = tipoContrato === 'plazo_fijo'
        ? 'a plazo fijo'
        : tipoContrato === 'promocional'
            ? 'promocional'
            : 'indefinido';

    const draftText = `Borrador de contrato de prestación de servicios.

REDYTELCA acuerda con ${clienteNombre} la prestación del servicio${aliasServicio} en ${direccionTexto}. El presente acuerdo inicia el ${fechaInicio} y, en caso de aplicarse, concluye el ${fechaFin}. La modalidad contractual corresponde a un contrato ${tipoEtiqueta}. Observaciones: ${observaciones}`;
    draftPreview.value = draftText;
}

function exportContratoPdf() {
    const draftPreview = document.getElementById('contrato-draft-preview');
    const content = draftPreview ? draftPreview.value.trim() : '';
    if (!content) {
        alert('No hay contenido para exportar.');
        return;
    }

    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
        alert('El navegador bloqueó la ventana de impresión.');
        return;
    }

    // Imprimir a PDF vía diálogo nativo; no se genera un archivo real con librería.
    const printableContent = escapeHtml(content).replace(/\n/g, '<br>');
    printWindow.document.write(`<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <title>Borrador de contrato</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #0f172a; line-height: 1.5; }
                h1 { margin-bottom: 8px; }
                .meta { color: #64748b; margin-bottom: 12px; }
                .content { white-space: pre-wrap; font-size: 13px; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body>
            <h1>Borrador de contrato</h1>
            <div class="meta">Exportado desde REDYTELCA</div>
            <div class="content">${printableContent}</div>
        </body>
        </html>`);
    printWindow.document.close();
    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 250);
}

function toggleContratoForm(forceClose = false, reset = true) {
    const modal = document.getElementById('contrato-modal');
    if (modal) {
        modal.style.display = forceClose ? 'none' : (modal.style.display === 'flex' ? 'none' : 'flex');
    }
    if (!forceClose && reset) {
        document.getElementById('contrato-id').value = '';
        const servicioSelect = document.getElementById('contrato-servicio');
        if (servicioSelect) servicioSelect.value = '';
        document.getElementById('contrato-fecha-inicio').value = '';
        document.getElementById('contrato-fecha-fin').value = '';
        document.getElementById('contrato-tipo').value = 'indefinido';
        document.getElementById('contrato-estado').value = 'vigente';
        document.getElementById('contrato-observaciones').value = '';
    }
    refreshContratoDraft();
}

async function guardarContrato() {
    const id = document.getElementById('contrato-id').value;
    const payload = {
        id_servicio: document.getElementById('contrato-servicio').value,
        fecha_inicio: document.getElementById('contrato-fecha-inicio').value,
        fecha_fin: document.getElementById('contrato-fecha-fin').value,
        tipo_contrato: document.getElementById('contrato-tipo').value,
        estado: document.getElementById('contrato-estado').value,
        observaciones: document.getElementById('contrato-observaciones').value.trim()
    };

    if (!payload.id_servicio || !payload.fecha_inicio) {
        alert('Servicio y fecha de inicio son obligatorios');
        return;
    }

    if (payload.tipo_contrato !== 'indefinido' && !payload.fecha_fin) {
        alert('Un contrato a plazo fijo o promocional requiere fecha de fin');
        return;
    }

    try {
        const response = await fetch('api_contratos.php', {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(id ? { ...payload, id_contrato: id } : payload)
        });
        const result = await response.json();
        alert(result.message || 'Operación realizada');
        if (result.status === 'success') {
            toggleContratoForm(true);
            await loadContratos();
            await loadClientes(window.currentClienteSearch);
        }
    } catch (error) {
        console.error('Error guardando contrato:', error);
        alert('No se pudo guardar el contrato.');
    }
}

function editarContrato(id) {
    const contrato = (window.contratosCache || []).find(item => Number(item.id_contrato) === Number(id));
    if (!contrato) return;
    document.getElementById('contrato-id').value = contrato.id_contrato;
    const servicioSelect = document.getElementById('contrato-servicio');
    if (servicioSelect) servicioSelect.value = contrato.id_servicio || '';
    document.getElementById('contrato-fecha-inicio').value = contrato.fecha_inicio || '';
    document.getElementById('contrato-fecha-fin').value = contrato.fecha_fin || '';
    document.getElementById('contrato-tipo').value = contrato.tipo_contrato || 'indefinido';
    document.getElementById('contrato-estado').value = contrato.estado || 'vigente';
    document.getElementById('contrato-observaciones').value = contrato.observaciones || '';
    toggleContratoForm(false, false);
    refreshContratoDraft();
}

async function eliminarContrato(id) {
    if (!confirm('¿Deseas eliminar este contrato?')) return;
    try {
        const response = await fetch('api_contratos.php?id=' + id, { method: 'DELETE' });
        const result = await response.json();
        alert(result.message || 'Contrato eliminado');
        if (result.status === 'success') {
            await loadContratos();
        }
    } catch (error) {
        console.error('Error eliminando contrato:', error);
        alert('No se pudo eliminar el contrato.');
    }
}

// ============================================================================
// FIN MÓDULO FINANZAS
// ============================================================================

window.addEventListener('DOMContentLoaded', async () => {
    updateDashboardData();
    window.addEventListener('resize', () => {
        if (window.leafletMapInstance && document.getElementById('mapa')?.classList.contains('active')) {
            window.leafletMapInstance.invalidateSize();
        }
    });
    if (typeof setTicketsFilter === 'function') {
        setTicketsFilter('Todos', document.querySelector('#ticket-filters .filter-pill'));
    }
    if (typeof setUsersFilter === 'function') {
        setUsersFilter('Todos', document.querySelector('#user-filters .filter-pill'));
    }
    if (typeof window.setMapFilter === 'function') {
        window.setMapFilter('Todos', document.querySelector('#map-filters .filter-pill'));
    }
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                overlay.style.display = 'none';
            }
        });
    });
    if (typeof setMapMode === 'function') {
        setMapMode('normal', document.querySelector('.view-toggle .toggle-btn'));
    }
    const loginButton = document.getElementById('login-button');
    if (loginButton) {
        loginButton.addEventListener('click', login);
    }
    populateAssignmentSelectors();
    try {
        await loadTickets();
        await loadTareas();
    } catch (error) {
        console.error('No se pudieron cargar las listas iniciales:', error);
    }
    renderReportes();

    const sessionActive = await restoreSession();
    if (!sessionActive) {
        clearSessionContext();
        return;
    }

    await loadRoles();
    await loadUsers();
    await loadNaps();
    await loadInfraestructura();
    await loadClientes();
    await loadServicios();
    await loadFinanzas();
    updateDashboardData();

    setInterval(() => {
        const overlay = document.getElementById('login-overlay');
        if (overlay && overlay.style.display !== 'flex' && overlay.style.display !== '') {
            loadClientes(window.currentClienteSearch);
            loadUsers();
        }
    }, 5000);

    setInterval(() => {
        const overlay = document.getElementById('login-overlay');
        if (overlay && overlay.style.display !== 'flex' && overlay.style.display !== '') {
            restoreSession(false);
        }
    }, 15000);
});

document.addEventListener('click', async (event) => {
    const profileMenu = document.getElementById('profile-menu');
    const profileButton = document.querySelector('.profile-button');
    if (profileMenu && profileButton && !profileButton.contains(event.target) && !profileMenu.contains(event.target)) {
        profileMenu.classList.remove('show');
    }

    const clientAction = event.target.closest('[data-client-action]');
    if (clientAction) {
        const id = Number(clientAction.dataset.clientId);
        const cliente = getClienteById(id);

        if (!cliente) {
            return;
        }

        if (clientAction.dataset.clientAction === 'profile') {
            renderClientProfile(cliente);
        } else if (clientAction.dataset.clientAction === 'edit') {
            editarCliente(id);
        } else if (clientAction.dataset.clientAction === 'delete') {
            await eliminarCliente(id);
        }
        return;
    }

    const clientRow = event.target.closest('#clientes-list tr[data-client-id]');
    if (clientRow && !event.target.closest('[data-client-action]')) {
        const cliente = getClienteById(Number(clientRow.dataset.clientId));
        if (cliente) {
            renderClientProfile(cliente);
        }
        return;
    }

    const userRow = event.target.closest('#users-table tbody tr[data-user-id]');
    if (userRow) {
        const userId = Number(userRow.dataset.userId);
        const user = (window.usuariosCache || []).find(item => Number(item.id_usuario) === userId);
        if (user) {
            openUserDetailModal(user);
        }
        return;
    }

    const napRow = event.target.closest('#naps-list tr[data-nap-id]');
    if (napRow) {
        const napId = Number(napRow.dataset.napId);
        const nap = (window.napsCache || []).find(item => Number(item.id_nap) === napId);
        if (nap) {
            openNapDetailModal(nap);
        }
        return;
    }

});

// Corrección del dashboard: al refrescar los datos se destruyen las instancias previas de Chart.js, se limpian los mensajes de estado vacío y se renderizan los gráficos desde los cachés reales de clientes, servicios, facturas, pagos, OLTs, NAPs y tickets.