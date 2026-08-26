<?php
/**
 * Lienzo de la videollamada, compartido por el médico (sala.php) y el paciente
 * (consulta.php). Espera:
 *   $sala      nombre de la sala        $nombre   nombre a mostrar
 *   $asunto    título de la llamada     $volver   URL del botón "Salir"
 *   $moderador true para el médico (puede silenciar y expulsar)
 *
 * Se embebe Jitsi con su external_api.js. No hay servidor de video propio: el
 * dominio sale de cfg('jitsi_dominio') y por omisión es la instancia pública.
 */
$dominio = jitsi_dominio();
?>
<div id="videollamada" style="width:100%;height:78vh;background:#040711;border-radius:12px;overflow:hidden"></div>

<div id="vc-caido" class="alert alert-warning mt-3 d-none">
    <i class="bi bi-exclamation-triangle"></i>
    <?= et('No se pudo cargar la videollamada. Revisa tu conexión y vuelve a entrar.') ?>
</div>

<script src="https://<?= e($dominio) ?>/external_api.js"
        onerror="document.getElementById('vc-caido').classList.remove('d-none')"></script>
<script>
(function () {
    if (typeof JitsiMeetExternalAPI !== 'function') {
        document.getElementById('vc-caido').classList.remove('d-none');
        return;
    }
    var api = new JitsiMeetExternalAPI(<?= json_encode($dominio) ?>, {
        roomName:   <?= json_encode($sala) ?>,
        parentNode: document.getElementById('videollamada'),
        userInfo:   { displayName: <?= json_encode($nombre) ?> },
        configOverwrite: {
            prejoinPageEnabled:  false,   // el paciente ya sabe a qué entra
            disableDeepLinking:  true,    // no empujar la app móvil
            startWithAudioMuted: false,
            subject:             <?= json_encode($asunto) ?>
        },
        interfaceConfigOverwrite: {
            SHOW_JITSI_WATERMARK:      false,
            SHOW_BRAND_WATERMARK:      false,
            DEFAULT_BACKGROUND:        '#040711',
            TOOLBAR_BUTTONS: [
                'microphone', 'camera', 'desktop', 'chat', 'raisehand',
                'tileview', 'fullscreen', 'settings', 'hangup'
                <?= $moderador ? ", 'participants-pane', 'mute-everyone'" : '' ?>
            ]
        }
    });

    // Colgar devuelve a donde corresponda en lugar de dejar una pantalla muerta.
    api.addListener('readyToClose', function () {
        window.location = <?= json_encode($volver) ?>;
    });
})();
</script>
