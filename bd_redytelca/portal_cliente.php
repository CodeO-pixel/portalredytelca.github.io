<?php
// Página simple para el portal de pago de clientes.
// Puedes reemplazar este HTML por la integración real con el proveedor de pagos.
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Portal de Pago - REDYTELCA</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body { background: linear-gradient(135deg, #eef4ff 0%, #f8fafc 100%); }
        .payment-portal { max-width: 980px; margin: 36px auto; padding: 20px; }
        .payment-card { padding: 24px; border-radius: 18px; background: var(--surface-2); border: 1px solid var(--border); box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08); }
        .payment-grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 18px; margin-top: 16px; }
        .payment-summary { padding: 16px; border-radius: 14px; background: #f8fafc; }
        input[name="cedula"], input[name="monto"] { padding: 10px; margin: 8px 0 12px; width:100%; border-radius:8px; border:1px solid var(--border); }
        button[type="submit"] { padding:10px 14px; border-radius:10px; background:var(--accent); color:white; border:none; }
        @media (max-width: 768px) { .payment-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <main class="payment-portal">
        <div class="payment-card">
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                <img id="portal-logo-img" src="" alt="REDYTELCA" class="payment-logo">
                <div>
                    <h1 style="margin:0;">Portal de Pago</h1>
                    <p style="margin:4px 0 0; color:#64748b;">Consulta tu saldo y realiza pagos de forma rápida.</p>
                </div>
            </div>

            <div class="payment-grid">
                <div>
                    <form id="payment-form" action="/api/pagar.php" method="POST">
                        <label>Documento / Cédula</label>
                        <input name="cedula" placeholder="Ej. V-12345678">

                        <label>Monto</label>
                        <input name="monto" placeholder="0.00">

                        <button type="submit">Pagar ahora</button>
                    </form>
                    <p id="payment-feedback" style="margin-top:10px; color:#16a34a;"></p>
                </div>
                <div class="payment-summary">
                    <h3 style="margin-top:0;">Resumen del cliente</h3>
                    <p><strong>Saldo pendiente:</strong> $120,00</p>
                    <p><strong>Próximo corte:</strong> 25/06/2026</p>
                    <p><strong>Método disponible:</strong> transferencia, pago móvil y efectivo</p>
                    <p style="margin-top:12px; color:#64748b;">Si eres administrador ingresa por <a href="/admin/">/admin/</a></p>
                </div>
            </div>
        </div>
    </main>
    <script>
        document.getElementById('payment-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            const feedback = document.getElementById('payment-feedback');
            if (feedback) {
                feedback.textContent = 'Solicitud recibida. El equipo de REDYTELCA confirmará la transacción en breve.';
            }
        });

        (function() {
            const asset = 'Logos/logo_transparent.png';
            const candidates = ['', '../', '../../', '../../../'];
            let index = 0;
            const portalImg = document.getElementById('portal-logo-img');
            if (!portalImg) {
                return;
            }
            function tryNext() {
                if (index >= candidates.length) {
                    portalImg.src = asset;
                    return;
                }
                const path = candidates[index++] + asset;
                const img = new Image();
                img.onload = function() {
                    portalImg.src = path;
                };
                img.onerror = function() {
                    tryNext();
                };
                img.src = path;
            }

            tryNext();
        })();
    </script>
</body>
</html>
