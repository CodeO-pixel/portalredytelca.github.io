<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Roles y Permisos - REDYTELCA</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="card" style="max-width:900px; margin:20px auto;">
        <h2>Roles y permisos</h2>
        <div style="display:flex; gap:12px;">
            <div style="flex:1">
                <h3>Roles</h3>
                <div id="roles-list"></div>
                <h4>Crear rol</h4>
                <input id="new-role-name" placeholder="Nombre del rol">
                <input id="new-role-desc" placeholder="Descripción">
                <button onclick="createRole()">Crear</button>
            </div>
            <div style="flex:2">
                <h3>Permisos del rol</h3>
                <select id="role-for-perms"></select>
                <div id="available-perms" style="margin-top:8px;"></div>
                <div style="margin-top:8px;"><button onclick="saveRolePerms()">Guardar permisos</button></div>
                <div id="perm-feedback" style="margin-top:8px;color:green;"></div>
            </div>
        </div>
    </div>

    <script>
        async function loadRoles() {
            const r = await fetch('api/roles.php');
            const data = await r.json();
            const list = document.getElementById('roles-list');
            const select = document.getElementById('role-for-perms');
            if (data.status === 'success') {
                list.innerHTML = data.roles.map(r => `<div><strong>${r.nombre_rol}</strong> - ${r.descripcion || ''}</div>`).join('');
                select.innerHTML = data.roles.map(r => `<option value="${r.id_rol}">${r.nombre_rol}</option>`).join('');
                loadPermissions();
            }
        }
        async function loadPermissions() {
            const r = await fetch('api/permissions.php');
            const data = await r.json();
            if (data.status === 'success') {
                const container = document.getElementById('available-perms');
                container.innerHTML = data.permisos.map(p => `<label style="display:block"><input type=checkbox value="${p.id_permiso}"> ${p.nombre_permiso} - ${p.descripcion || ''}</label>`).join('');
                loadRolePermissions();
            }
        }
        async function loadRolePermissions() {
            const roleId = document.getElementById('role-for-perms').value;
            if (!roleId) return;
            const r = await fetch('api/role_permissions.php?id_rol=' + encodeURIComponent(roleId));
            const data = await r.json();
            if (data.status === 'success') {
                const assigned = (data.permisos || []).map(p => p.id_permiso);
                document.querySelectorAll('#available-perms input[type=checkbox]').forEach(chk => {
                    chk.checked = assigned.includes(Number(chk.value));
                });
            }
        }
        async function createRole() {
            const name = document.getElementById('new-role-name').value.trim();
            const desc = document.getElementById('new-role-desc').value.trim();
            if (!name) return alert('Nombre requerido');
            const r = await fetch('api/roles.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ nombre_rol: name, descripcion: desc })});
            const data = await r.json();
            alert(data.message || 'OK');
            await loadRoles();
        }
        async function saveRolePerms() {
            const roleId = document.getElementById('role-for-perms').value;
            const checks = Array.from(document.querySelectorAll('#available-perms input[type=checkbox]')).filter(c=>c.checked).map(c=>c.value);
            const r = await fetch('api/role_permissions.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id_rol: roleId, permisos: checks })});
            const data = await r.json();
            const fb = document.getElementById('perm-feedback');
            fb.textContent = data.message || '';
            setTimeout(()=> fb.textContent = '', 3000);
        }
        document.getElementById('role-for-perms')?.addEventListener('change', loadRolePermissions);
        window.addEventListener('DOMContentLoaded', loadRoles);
    </script>
</body>
</html>