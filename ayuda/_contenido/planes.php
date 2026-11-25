<?php /* Artículo: planes y prueba gratis. Precios en vivo de la tabla `planes`. */ ?>
<p>MediAgenda se paga por mes, por consultorio (no por médico). Todos los precios incluyen IVA y no hay costo de instalación ni contratos forzosos.</p>
<h2 id="planes">Planes</h2>
<div class="ca-cards">
<?php foreach (planes_mp() as $k => $pl): ?>
    <div class="ca-card"><i class="bi bi-stars"></i><b><?= e($pl['nombre']) ?> · $<?= number_format($pl['precio'], 0) ?>/mes</b><span><?= e($pl['descripcion']) ?></span>
    <?php if ($pl['items']): ?><ul class="small mt-2 mb-0 ps-3"><?php foreach ($pl['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php endforeach; ?>
</div>
<h2 id="prueba">Prueba gratis</h2>
<p>Cualquier plan se prueba <b><?= trial_dias_oferta() ?> días gratis</b>, sin tarjeta y con acceso completo. Al terminar eliges tu plan en <span class="ui">Mi suscripción</span> y pagas con Mercado Pago; tu información se conserva.</p>
<h2 id="extras">Timbres y mensajes de WhatsApp</h2>
<p>La facturación electrónica usa timbres y el WhatsApp incluido usa mensajes. Algunos planes traen una cantidad al mes y puedes comprar paquetes de prepago que no caducan. Ver <a href="<?= BASE_URL ?>/ayuda/facturacion-electronica">Facturación electrónica</a> y <a href="<?= BASE_URL ?>/ayuda/mensajes-whatsapp">Mensajes de WhatsApp</a>.</p>
<h2 id="fundaciones">Fundaciones</h2>
<p>Las fundaciones, asociaciones civiles e I.A.P. sin fines de lucro pueden usar MediAgenda sin costo, con las funciones del plan Clínica.</p>
