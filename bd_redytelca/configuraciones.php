<?php
// Página de configuraciones mínima: valores se guardan localmente en el cliente (ejemplo).
// Integrar con DB/endpoint según necesidad.
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Configuraciones - REDYTELCA</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="config-shell card" style="max-width:800px; margin:20px auto;">
        <h2>Configuraciones de la empresa</h2>
        <p>Estos ajustes se almacenan en LocalStorage hasta que se implementen endpoints de guardado en servidor.</p>
        <label>Nombre de la empresa</label>
        <input id="cfg-nombre" type="text" placeholder="Nombre de la empresa">

        <label>Color primario (hex)</label>
        <input id="cfg-color" type="text" placeholder="#0275d8">

        <label>URL del logo</label>
        <input id="cfg-logo" type="text" placeholder="/assets/logo.png">

        <div style="margin-top:12px; display:flex; gap:8px;">
            <button onclick="guardarConfig()">Guardar</button>
            <button onclick="cargarConfig()">Cargar</button>
            <button onclick="limpiarConfig()" style="background:#dc2626;">Limpiar</button>
        </div>

        <div id="config-feedback" style="margin-top:12px; color:green;"></div>
    </div>

    <script>
        function guardarConfig() {
            const nombre = document.getElementById('cfg-nombre').value.trim();
            const color = document.getElementById('cfg-color').value.trim();
            const logo = document.getElementById('cfg-logo').value.trim();
            const cfg = { nombre, color, logo };
            try {
                localStorage.setItem('app_config', JSON.stringify(cfg));
                document.getElementById('config-feedback').textContent = 'Configuración guardada localmente.';
            } catch (e) {
                document.getElementById('config-feedback').textContent = 'Error guardando configuración.';
            }
        }
        function cargarConfig() {
            try {
                const raw = localStorage.getItem('app_config');
                if (!raw) { document.getElementById('config-feedback').textContent = 'No hay configuración guardada.'; return; }
                const cfg = JSON.parse(raw);
                document.getElementById('cfg-nombre').value = cfg.nombre || '';
                document.getElementById('cfg-color').value = cfg.color || '';
                document.getElementById('cfg-logo').value = cfg.logo || '';
                document.getElementById('config-feedback').textContent = 'Configuración cargada.';
            } catch (e) {
                document.getElementById('config-feedback').textContent = 'Error cargando configuración.';
            }
        }
        function limpiarConfig() {
            localStorage.removeItem('app_config');
            document.getElementById('cfg-nombre').value = '';
            document.getElementById('cfg-color').value = '';
            document.getElementById('cfg-logo').value = '';
            document.getElementById('config-feedback').textContent = 'Configuración eliminada.';
        }

        document.addEventListener('DOMContentLoaded', cargarConfig);
    </script>
</body>
</html>
