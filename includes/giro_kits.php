<?php
/**
 * Kit de inicio por giro: el contenido propio de cada tipo de consultorio.
 *
 * giros.php adapta CÓMO se habla y QUÉ herramientas van al frente; este
 * archivo pone lo que el consultorio encuentra YA ESCRITO el primer día:
 *   - Plantillas de consulta de su especialidad (vacunación y esterilización
 *     en una veterinaria; primera entrevista y nota de sesión en psicología…).
 *   - Catálogo de servicios con su duración típica (precio en 0: cada quien
 *     pone el suyo; no inventamos precios).
 *   - Consentimientos informados propios del giro (psicoterapia, cirugía de
 *     mascota, extracción dental, toxina botulínica…).
 *
 * Plantillas y servicios se SIEMBRAN en la BD (editables, por nombre, sin
 * duplicar) al registrarse y al cambiar de giro; los consentimientos se leen
 * en vivo desde consentimientos_plantillas().
 *
 * Los textos ya van en el vocabulario del giro (mascota/tutor, sesión…): se
 * guardan en la BD y no pasan por t().
 */

/**
 * Plantillas de consulta del giro. Mismo formato que plantillas_semilla():
 * [nombre, especialidad, tipo, motivo, exploracion, diagnostico, tratamiento, receta, notas].
 */
function giro_kit_plantillas(string $giro): array
{
    $g = 'general';
    switch ($giro) {
    case 'veterinaria':
        $e = 'Veterinaria';
        return [
            ['Consulta general (mascota)', $e, $g,
                'Motivo de consulta referido por el tutor:',
                "Peso: ___ kg  T°: ___ °C  FC: ___ lpm  FR: ___ rpm\nCondición corporal (1-9): ___  Hidratación: ___  Mucosas/TLLC: ___\nLinfonodos, piel y pelaje, oídos, ojos, cavidad oral, abdomen, auscultación:",
                'Diagnóstico presuntivo / diferenciales:', 'Plan diagnóstico y terapéutico:', '',
                'Se explica al tutor el diagnóstico, el plan y los signos de alarma.'],
            ['Vacunación', $e, $g,
                'Aplicación de vacuna según calendario',
                "Peso: ___ kg  T°: ___ °C\nExamen físico previo sin alteraciones / hallazgos:",
                'Mascota clínicamente sana, apta para vacunación', "Vacuna aplicada: ___  Lote: ___\nVía: SC / IM", '',
                'Observar 24 h por reacción (inflamación, decaimiento, vómito). Próxima dosis: ___'],
            ['Desparasitación', $e, $g,
                'Desparasitación interna / externa',
                "Peso: ___ kg\nPresencia de pulgas/garrapatas: ___  Heces: ___",
                'Control antiparasitario preventivo', "Producto: ___  Dosis según peso: ___\nVía: oral / tópica", '',
                'Repetir en ___ semanas. Desparasitar también a los demás animales de la casa.'],
            ['Valoración prequirúrgica', $e, $g,
                'Valoración antes de cirugía / anestesia',
                "Peso: ___ kg  T°: ___  FC: ___  FR: ___\nAuscultación cardiopulmonar, mucosas, hidratación.\nLaboratorios: BH ___  Química ___",
                'Riesgo anestésico ASA: ___', 'Ayuno de sólidos ___ h. Protocolo anestésico: ___', '',
                'Se entregan indicaciones de ayuno y se firma consentimiento con el tutor.'],
            ['Esterilización (OVH / orquiectomía)', $e, $g,
                'Esterilización programada',
                "Peso: ___ kg. Valoración prequirúrgica: ___\nProcedimiento y hallazgos transquirúrgicos:",
                'Mascota esterilizada sin complicaciones', "Analgesia: ___  Antibiótico (si aplica): ___\nCollar isabelino. Reposo 10 días.", '',
                'Revisión de herida a los 3 días y retiro de puntos a los 10.'],
            ['Gastroenteritis', $e, $g,
                'Vómito / diarrea',
                "Tiempo de evolución: ___  Frecuencia: ___\nHidratación, dolor abdominal, T°, características de las heces:",
                'Gastroenteritis aguda (descartar parvovirus / cuerpo extraño)', 'Ayuno corto, dieta blanda, hidratación. Antiemético según el caso.', '',
                'Regresar de inmediato si hay sangre en heces, decaimiento o deja de beber agua.'],
            ['Dermatitis / otitis', $e, $g,
                'Prurito, lesiones en piel u oídos',
                "Distribución de lesiones, alopecia, eritema, olor. Otoscopia OD/OI:\nCitología / raspado: ___",
                'Dermatitis ___ / otitis externa ___', 'Tratamiento tópico / sistémico. Control de pulgas.', '',
                'Revisar en 2 semanas.'],
            ['Control geriátrico', $e, $g,
                'Revisión de mascota senior',
                "Peso y condición corporal. Movilidad / dolor articular. Dentadura. Auscultación.\nLaboratorios sugeridos: BH, química, urianálisis.",
                'Mascota geriátrica', 'Ajuste de dieta y suplementos. Manejo del dolor si aplica.', '',
                'Chequeo cada 6 meses.'],
        ];

    case 'psicologia':
        $e = 'Psicología';
        return [
            ['Primera entrevista', $e, $g,
                'Motivo de consulta (en palabras del paciente):',
                "Historia del problema actual. Antecedentes personales, familiares y de tratamientos previos.\nRed de apoyo. Consumo de sustancias.\nExamen mental: apariencia, conducta, afecto, pensamiento, juicio, ideación.",
                'Impresión clínica inicial:', "Objetivos terapéuticos acordados:\nEnfoque y frecuencia de sesiones:", '',
                'Se explican encuadre, confidencialidad y sus límites. Se firma consentimiento.'],
            ['Nota de sesión (SOAP)', $e, $g,
                'Sesión de seguimiento',
                "S (subjetivo): lo que refiere el paciente desde la sesión anterior.\nO (objetivo): afecto, conducta, escalas aplicadas.",
                'A (análisis): avance respecto a los objetivos.', 'P (plan): intervenciones y tareas para la siguiente sesión.', '',
                ''],
            ['Valoración de riesgo', $e, $g,
                'Evaluación de riesgo suicida / autolesión',
                "Ideación: pasiva / activa. Plan: ___  Medios: ___  Intentos previos: ___\nFactores de riesgo y de protección:",
                'Nivel de riesgo: bajo / moderado / alto', "Plan de seguridad acordado.\nContactos de emergencia. Referencia a psiquiatría / urgencias si aplica.", '',
                'Se informa al paciente que la confidencialidad tiene límites ante riesgo para su vida.'],
            ['Ansiedad', $e, $g,
                'Síntomas de ansiedad',
                "GAD-7: ___ / 21. Situaciones detonantes. Síntomas físicos. Evitación.",
                'Impresión clínica: ___', 'Psicoeducación, respiración diafragmática, reestructuración cognitiva, exposición gradual.', '',
                'Registro de pensamientos como tarea.'],
            ['Depresión', $e, $g,
                'Estado de ánimo bajo',
                "PHQ-9: ___ / 27. Sueño, apetito, energía, anhedonia. Ideación (ver valoración de riesgo).",
                'Impresión clínica: ___', 'Activación conductual. Valorar referencia a psiquiatría.', '',
                'Agenda de actividades placenteras como tarea.'],
            ['Terapia de pareja', $e, $g,
                'Sesión de pareja',
                "Motivo según cada miembro. Patrón de comunicación. Áreas de conflicto y de fortaleza.",
                'Impresión del sistema de pareja:', 'Objetivos compartidos. Ejercicios de comunicación.', '',
                ''],
            ['Sesión infantil', $e, $g,
                'Consulta para niño(a) / adolescente',
                "Motivo según madre/padre/tutor y según el menor. Desarrollo, escuela, convivencia familiar.\nObservación en juego.",
                'Impresión clínica:', 'Plan con el menor y orientación a padres.', '',
                'Consentimiento firmado por madre, padre o tutor.'],
            ['Cierre del proceso', $e, $g,
                'Alta terapéutica',
                "Resumen del proceso. Objetivos alcanzados. Escalas al cierre:",
                'Estado al cierre:', 'Plan de prevención de recaídas. Seguimiento opcional.', '',
                ''],
        ];

    case 'dental':
        $e = 'Odontología';
        $d = 'dental';
        return [
            ['Valoración dental de primera vez', $e, $d,
                'Revisión general / motivo:',
                "Tejidos blandos, oclusión, ATM. Higiene oral. Odontograma actualizado.\nRadiografías: ___",
                'Diagnóstico:', 'Plan de tratamiento por fases (presupuesto entregado).', '',
                'Se explican opciones y costos al paciente.'],
            ['Profilaxis / limpieza', $e, $d,
                'Limpieza dental',
                "Índice de placa: ___  Sangrado al sondeo: ___  Cálculo: ___",
                'Gingivitis / encías sanas', 'Profilaxis, ultrasonido y pulido. Fluoruro (si aplica).', '',
                'Técnica de cepillado e hilo dental. Próxima limpieza en 6 meses.'],
            ['Resina (obturación)', $e, $d,
                'Caries / restauración',
                "Pieza: ___  Superficies: ___  Profundidad: ___",
                'Caries ___ en pieza ___', 'Anestesia local. Remoción de caries, aislamiento, resina ___.', '',
                'Evitar alimentos muy duros las primeras horas.'],
            ['Endodoncia', $e, $d,
                'Dolor dental / tratamiento de conductos',
                "Pieza: ___  Pruebas de vitalidad: ___  Percusión: ___  Rx periapical:",
                'Pulpitis irreversible / necrosis pulpar en pieza ___', "Conductometría: ___ mm. Instrumentación y obturación.\nRestauración definitiva pendiente.", '',
                'Molestia leve 2-3 días es normal. Programar corona/restauración.'],
            ['Extracción', $e, $d,
                'Extracción dental',
                "Pieza: ___  Rx: ___  Antecedentes (anticoagulantes, diabetes, alergias):",
                'Indicación de extracción de pieza ___', 'Anestesia local. Extracción. Hemostasia con gasa.', '',
                'No escupir ni usar popote 24 h. Dieta blanda y fría. Analgésico según receta.'],
            ['Urgencia dental', $e, $d,
                'Dolor / inflamación / traumatismo',
                "Pieza involucrada, inflamación, fístula, movilidad. Rx:",
                'Diagnóstico de urgencia:', 'Manejo paliativo / drenaje / analgesia. Cita para tratamiento definitivo.', '',
                ''],
            ['Control de ortodoncia', $e, $d,
                'Ajuste de ortodoncia',
                "Arco actual: ___  Ligas / elásticos: ___  Higiene: ___",
                'Tratamiento de ortodoncia en curso', 'Cambio de arco / activación.', '',
                'Próximo ajuste en 4 semanas.'],
        ];

    case 'fisioterapia':
        $e = 'Rehabilitación y Fisioterapia';
        return [
            ['Valoración fisioterapéutica inicial', $e, $g,
                'Motivo de consulta y diagnóstico médico de referencia:',
                "EVA: ___/10  Inicio: ___  Mecanismo:\nInspección / postura:\nPalpación:\nArcos de movimiento (activo/pasivo):\nFuerza (Daniels 0-5):\nPruebas especiales:\nMarcha y funcionalidad:",
                'Diagnóstico fisioterapéutico:', "Objetivos a corto y largo plazo:\nPlan: terapia manual / agentes físicos / ejercicio terapéutico / educación.\nSesiones estimadas: ___  Frecuencia: ___", '',
                'Banderas rojas descartadas. Se explica el diagnóstico y el plan; el paciente acepta el tratamiento.'],
            ['Sesión de fisioterapia (SOAP)', $e, $g,
                'S: Cómo llega, cambios desde la última sesión. EVA al llegar: ___/10',
                "O: Hallazgos y mediciones de hoy:\nIntervenciones y parámetros:",
                'A: Respuesta al tratamiento y avance hacia los objetivos:', 'P: Próxima sesión / progresión / tarea en casa:', '',
                'EVA al salir: ___/10. Tolerancia: buena / regular / mala.'],
            ['Reevaluación', $e, $g,
                'Reevaluación tras ___ sesiones',
                "EVA: ___/10 (inicial ___)\nArcos de movimiento comparados:\nFuerza comparada:\nEscala funcional: ___ (inicial ___)",
                'Evolución: favorable / estacionaria / desfavorable', 'Ajuste del plan:', '',
                'Se comentan los avances con el paciente y se ajustan objetivos.'],
            ['Lumbalgia mecánica', $e, $g,
                'Dolor lumbar',
                "EVA: ___  Irradiación: ___  Banderas rojas: negativas\nFlexión / extensión lumbar:\nLasègue / Slump:\nOswestry: ___%",
                'Lumbalgia mecánica inespecífica', 'Educación en dolor, terapia manual, control motor y fortalecimiento del core. Plan en casa.', '',
                'Mantenerse activo; evitar reposo prolongado.'],
            ['Cervicalgia', $e, $g,
                'Dolor cervical',
                "EVA: ___  Rotaciones e inclinaciones:\nSpurling / ULTT1:\nNDI: ___%  Postura:",
                'Cervicalgia mecánica', 'Terapia manual, ejercicio de control craneocervical y escapular, ergonomía.', '',
                'Pausas activas cada hora frente a la computadora.'],
            ['Hombro doloroso', $e, $g,
                'Dolor de hombro',
                "EVA: ___  Flexión / abducción / rotaciones:\nNeer / Hawkins / Jobe / arco doloroso:\nSPADI: ___%",
                'Síndrome de dolor subacromial / tendinopatía del manguito rotador', 'Ejercicio progresivo de manguito y escapulares, terapia manual, control del dolor.', '',
                'Evitar cargas por encima de la cabeza mientras haya dolor.'],
            ['Esguince de tobillo', $e, $g,
                'Esguince de tobillo',
                "Grado: ___  Edema: ___  Equimosis: ___\nCajón anterior / inclinación talar:\nApoyo y marcha:",
                'Esguince de tobillo grado ___', 'Control de edema, movilidad, fortalecimiento de peroneos y propiocepción progresiva.', '',
                'Regreso gradual a la actividad según tolerancia.'],
            ['Rehabilitación postquirúrgica de rodilla', $e, $g,
                'Postquirúrgico de rodilla (cirugía: ___ fecha: ___)',
                "Herida: ___  Edema: ___\nFlexión: ___°  Extensión: ___°\nActivación de cuádriceps: ___  Marcha / apoyo:",
                'Postoperatorio de ___, semana ___', 'Protocolo del cirujano: control de edema, movilidad, activación de cuádriceps, marcha.', '',
                'Respetar las restricciones de carga indicadas por el cirujano.'],
            ['Alta de fisioterapia', $e, $g,
                'Alta del programa de rehabilitación',
                "EVA final: ___ (inicial ___)\nArcos y fuerza finales:\nEscala funcional final: ___ (inicial ___)",
                'Objetivos cumplidos / mejoría parcial', 'Programa de ejercicio en casa para mantenimiento.', '',
                'Se entrega informe de alta y plan de mantenimiento.'],
        ];

    case 'nutricion':
        $e = 'Nutrición';
        return [
            ['Primera valoración nutricional', $e, $g,
                'Objetivo del paciente:',
                "Peso: ___  Talla: ___  IMC: ___  Cintura: ___  % grasa: ___\nRecordatorio de 24 h. Frecuencia de consumo. Actividad física. Hidratación.",
                'Diagnóstico nutricional:', "Requerimiento energético: ___ kcal\nPlan de alimentación y metas.", '',
                'Seguimiento en 2 semanas.'],
            ['Seguimiento nutricional', $e, $g,
                'Control de avance',
                "Peso: ___ (cambio: ___)  Cintura: ___\nApego al plan, hambre/saciedad, dificultades:",
                'Evolución:', 'Ajustes al plan de alimentación.', '',
                ''],
            ['Nutrición deportiva', $e, $g,
                'Plan para rendimiento deportivo',
                "Deporte, volumen de entrenamiento, composición corporal:",
                'Diagnóstico nutricional:', 'Distribución de macronutrientes y timing alrededor del entrenamiento.', '',
                ''],
            ['Control de diabetes / resistencia a la insulina', $e, $g,
                'Manejo nutricional de glucosa',
                "Glucosa / HbA1c recientes: ___. Peso, cintura, patrón de comidas:",
                'Diagnóstico nutricional:', 'Plan con control de carbohidratos y conteo de porciones.', '',
                'Coordinar con su médico tratante.'],
        ];

    case 'pediatria':
        $e = 'Pediatría';
        return [
            ['Control del niño sano', $e, $g,
                'Control de niño sano',
                "Peso: ___ kg  Talla: ___ cm  PC: ___ cm\nPercentiles. Desarrollo psicomotor. Esquema de vacunación:",
                'Niño sano / desarrollo adecuado para la edad', 'Alimentación según edad. Vacunas según cartilla.', '',
                'Curva de crecimiento actualizada. Próximo control según edad.'],
            ['Recién nacido', $e, $g,
                'Primera revisión del recién nacido',
                "Peso al nacer / actual. Ictericia. Cordón. Reflejos. Caderas. Tamiz neonatal y auditivo:",
                'Recién nacido sano', 'Lactancia materna exclusiva. Cuidados del cordón. Signos de alarma.', '',
                ''],
            ['Infección respiratoria', $e, $g,
                'Tos / fiebre / congestión',
                "T°: ___  FR: ___  SatO2: ___\nOtoscopia, faringe, auscultación, datos de dificultad respiratoria:",
                'Infección respiratoria ___', 'Manejo sintomático. Hidratación.', '',
                'Signos de alarma explicados a madre/padre: respiración rápida, hundimiento de costillas, fiebre persistente.'],
            ['Diarrea aguda', $e, $g,
                'Evacuaciones disminuidas de consistencia',
                "Número de evacuaciones, vómito, diuresis. Estado de hidratación:",
                'Enfermedad diarreica aguda, hidratación: ___', 'Vida Suero Oral. Continuar alimentación.', '',
                'Regresar si hay sangre en heces, no tolera líquidos o deja de orinar.'],
        ];

    case 'oftalmologia':
        $e = 'Oftalmología';
        return [
            ['Examen de la vista', $e, $g,
                'Revisión visual / graduación',
                "AV sin corrección OD/OI: ___  Con corrección: ___\nRefracción, queratometría, segmento anterior:",
                'Ametropía: ___', 'Graduación indicada (ver fórmula).', '',
                'Revisión anual.'],
            ['Ojo rojo', $e, $g,
                'Ojo rojo / irritación',
                "AV, secreción, inyección conjuntival/ciliar, córnea con fluoresceína, PIO:",
                'Conjuntivitis ___ / otro', 'Tratamiento tópico.', '',
                'Regresar si baja la visión o hay dolor intenso.'],
            ['Control de glaucoma', $e, $g,
                'Seguimiento de glaucoma',
                "PIO OD/OI: ___  Excavación: ___  Campo visual: ___",
                'Glaucoma ___ en control / descontrol', 'Ajuste de gotas hipotensoras.', '',
                ''],
            ['Fondo de ojo (diabetes)', $e, $g,
                'Revisión de retina en paciente con diabetes',
                "AV, fondo de ojo bajo midriasis OD/OI:",
                'Retinopatía diabética: sin / con ___', 'Control metabólico con su médico. Referencia si aplica.', '',
                'Revisión anual o antes según hallazgos.'],
        ];

    case 'estetica':
        $e = 'Dermatología y estética';
        return [
            ['Valoración estética', $e, $g,
                'Área a tratar y expectativas:',
                "Fototipo: ___  Envejecimiento, manchas, flacidez, cicatrices. Fotografías clínicas tomadas.",
                'Diagnóstico:', 'Plan de tratamiento por sesiones (presupuesto entregado).', '',
                'Se explican resultados esperados y cuidados. Consentimiento firmado.'],
            ['Toxina botulínica', $e, $g,
                'Aplicación de toxina botulínica',
                "Zonas: ___  Unidades por zona: ___  Lote: ___",
                'Líneas de expresión ___', 'Aplicación según mapa de puntos.', '',
                'No acostarse ni hacer ejercicio 4 h. No masajear. Revisión a los 15 días.'],
            ['Relleno con ácido hialurónico', $e, $g,
                'Aplicación de relleno',
                "Zona: ___  Producto / lote: ___  Volumen: ___ ml",
                'Pérdida de volumen ___', 'Aplicación con aguja / cánula.', '',
                'Frío local. Evitar calor intenso 48 h. Revisión a los 15 días.'],
            ['Acné', $e, $g,
                'Acné',
                "Tipo de lesiones, distribución, grado. Tratamientos previos:",
                'Acné ___ grado ___', 'Tratamiento tópico / sistémico. Rutina de cuidado.', '',
                'Control en 6-8 semanas.'],
            ['Peeling químico', $e, $g,
                'Sesión de peeling',
                "Agente: ___  Concentración: ___  Tiempo: ___  Reacción:",
                'Indicación: ___', 'Neutralización y calmante.', '',
                'Protector solar estricto. No exponer al sol 7 días.'],
        ];

    case 'medico':
    case 'clinica':
        // Medicina general y clínicas: el paquete multiespecialidad de siempre.
        return plantillas_semilla();
    }
    return [];
}

/** Servicios del giro: [nombre, categoria, duracion_min, aplica_diente]. Precio 0: lo pone el consultorio. */
function giro_kit_servicios(string $giro): array
{
    switch ($giro) {
    case 'veterinaria':
        return [
            ['Consulta general', 'Consulta', 30, 0], ['Consulta de urgencia', 'Consulta', 30, 0],
            ['Vacuna múltiple (perro)', 'Medicina preventiva', 15, 0], ['Vacuna antirrábica', 'Medicina preventiva', 15, 0],
            ['Vacuna triple felina', 'Medicina preventiva', 15, 0], ['Desparasitación interna', 'Medicina preventiva', 15, 0],
            ['Desparasitación externa', 'Medicina preventiva', 15, 0],
            ['Esterilización hembra (OVH)', 'Cirugía', 90, 0], ['Castración macho', 'Cirugía', 60, 0],
            ['Limpieza dental (profilaxis)', 'Cirugía', 60, 0],
            ['Biometría hemática', 'Laboratorio', 15, 0], ['Química sanguínea', 'Laboratorio', 15, 0],
            ['Radiografía', 'Imagen', 30, 0], ['Ultrasonido', 'Imagen', 30, 0],
            ['Hospitalización (día)', 'Hospitalización', 30, 0], ['Microchip', 'Identificación', 15, 0],
            ['Certificado de salud', 'Documentos', 15, 0],
            ['Baño', 'Estética', 60, 0], ['Corte de pelo', 'Estética', 60, 0], ['Corte de uñas', 'Estética', 15, 0],
        ];
    case 'psicologia':
        return [
            ['Primera entrevista', 'Evaluación', 60, 0], ['Sesión individual', 'Terapia', 50, 0],
            ['Sesión en línea', 'Terapia', 50, 0], ['Terapia de pareja', 'Terapia', 60, 0],
            ['Terapia familiar', 'Terapia', 75, 0], ['Sesión infantil', 'Terapia', 50, 0],
            ['Sesión adolescente', 'Terapia', 50, 0], ['Evaluación psicométrica', 'Evaluación', 90, 0],
            ['Informe psicológico', 'Documentos', 30, 0], ['Taller grupal', 'Grupos', 120, 0],
        ];
    case 'dental':
        return [
            ['Consulta / valoración', 'Diagnóstico', 30, 0], ['Radiografía periapical', 'Diagnóstico', 15, 1],
            ['Profilaxis (limpieza)', 'Preventivo', 45, 0], ['Aplicación de flúor', 'Preventivo', 15, 0],
            ['Sellador de fosetas', 'Preventivo', 20, 1], ['Resina', 'Operatoria', 45, 1],
            ['Endodoncia unirradicular', 'Endodoncia', 90, 1], ['Endodoncia multirradicular', 'Endodoncia', 120, 1],
            ['Extracción simple', 'Cirugía', 45, 1], ['Extracción de tercer molar', 'Cirugía', 90, 1],
            ['Corona', 'Prótesis', 60, 1], ['Blanqueamiento', 'Estética dental', 60, 0],
            ['Ajuste de ortodoncia', 'Ortodoncia', 30, 0], ['Curetaje / raspado', 'Periodoncia', 60, 1],
        ];
    case 'fisioterapia':
        return [
            ['Valoración inicial', 'Valoración', 60, 0], ['Sesión de fisioterapia', 'Sesión', 50, 0],
            ['Reevaluación', 'Valoración', 45, 0], ['Terapia manual', 'Sesión', 45, 0],
            ['Electroterapia', 'Agentes físicos', 30, 0], ['Ultrasonido terapéutico', 'Agentes físicos', 15, 0],
            ['Láser terapéutico', 'Agentes físicos', 15, 0], ['Punción seca', 'Procedimientos', 30, 0],
            ['Vendaje neuromuscular', 'Procedimientos', 20, 0], ['Ondas de choque', 'Agentes físicos', 20, 0],
            ['Rehabilitación postquirúrgica', 'Sesión', 60, 0], ['Rehabilitación neurológica', 'Sesión', 60, 0],
            ['Fisioterapia deportiva', 'Sesión', 50, 0], ['Fisioterapia de suelo pélvico', 'Sesión', 50, 0],
            ['Sesión a domicilio', 'Sesión', 60, 0], ['Paquete de 10 sesiones', 'Paquetes', 50, 0],
            ['Informe de evolución', 'Documentos', 15, 0],
        ];
    case 'nutricion':
        return [
            ['Primera consulta nutricional', 'Consulta', 60, 0], ['Consulta de seguimiento', 'Consulta', 30, 0],
            ['Consulta en línea', 'Consulta', 30, 0], ['Análisis de composición corporal', 'Evaluación', 20, 0],
            ['Plan de alimentación personalizado', 'Planes', 30, 0], ['Nutrición deportiva', 'Consulta', 45, 0],
        ];
    case 'pediatria':
        return [
            ['Consulta pediátrica', 'Consulta', 30, 0], ['Control del niño sano', 'Preventivo', 30, 0],
            ['Revisión de recién nacido', 'Preventivo', 45, 0], ['Aplicación de vacuna', 'Vacunación', 15, 0],
            ['Nebulización', 'Procedimientos', 20, 0], ['Certificado médico escolar', 'Documentos', 15, 0],
        ];
    case 'oftalmologia':
        return [
            ['Consulta oftalmológica', 'Consulta', 30, 0], ['Examen de la vista', 'Optometría', 30, 0],
            ['Adaptación de lentes de contacto', 'Optometría', 45, 0], ['Toma de presión intraocular', 'Estudios', 15, 0],
            ['Fondo de ojo', 'Estudios', 20, 0], ['Campimetría', 'Estudios', 30, 0],
        ];
    case 'estetica':
        return [
            ['Valoración estética', 'Consulta', 30, 0], ['Consulta dermatológica', 'Consulta', 30, 0],
            ['Toxina botulínica (por zona)', 'Inyectables', 30, 0], ['Ácido hialurónico (por ml)', 'Inyectables', 45, 0],
            ['Peeling químico', 'Faciales', 45, 0], ['Limpieza facial profunda', 'Faciales', 60, 0],
            ['Mesoterapia', 'Inyectables', 45, 0], ['Retiro de lesiones (electrocirugía)', 'Procedimientos', 30, 0],
        ];
    case 'medico':
    case 'clinica':
        return [
            ['Consulta general', 'Consulta', 30, 0], ['Consulta de seguimiento', 'Consulta', 20, 0],
            ['Consulta en línea', 'Consulta', 20, 0], ['Certificado médico', 'Documentos', 15, 0],
            ['Curación', 'Procedimientos', 20, 0], ['Aplicación de inyección', 'Procedimientos', 10, 0],
            ['Retiro de puntos', 'Procedimientos', 15, 0], ['Electrocardiograma', 'Estudios', 20, 0],
        ];
    }
    return [];
}

/** Consentimientos propios del giro: título => texto ({paciente}, {consultorio}). */
function giro_kit_consentimientos(string $giro): array
{
    switch ($giro) {
    case 'veterinaria':
        $tutor = 'Yo, en mi calidad de tutor(a) responsable de la mascota {paciente}, declaro que el médico veterinario de {consultorio} me explicó en lenguaje claro ';
        return [
            'Consentimiento para cirugía y anestesia (mascota)' =>
                $tutor . 'el procedimiento propuesto, sus beneficios, riesgos (incluidos los propios de la anestesia, que '
                . 'pueden llegar a ser graves) y alternativas. Informé su historial, medicamentos y si cumplió el ayuno. '
                . 'Entiendo que no se garantizan resultados y autorizo el procedimiento y las maniobras necesarias ante una '
                . 'complicación. Me comprometo a seguir los cuidados posoperatorios indicados.',
            'Consentimiento de hospitalización (mascota)' =>
                $tutor . 'los motivos para hospitalizar a mi mascota, los tratamientos que se le aplicarán y los costos '
                . 'estimados. Autorizo su internamiento y me comprometo a mantener mis datos de contacto actualizados para '
                . 'ser informado(a) de su evolución.',
            'Consentimiento para eutanasia' =>
                $tutor . 'el estado de salud y el pronóstico de mi mascota, así como las alternativas existentes. De forma '
                . 'libre y después de haberlo meditado, solicito y autorizo la eutanasia humanitaria de {paciente}. '
                . 'Declaro que la mascota es de mi propiedad y que no ha mordido a ninguna persona en los últimos 10 días.',
            'Responsiva de vacunación (mascota)' =>
                $tutor . 'la vacuna a aplicar y sus posibles reacciones (dolor local, decaimiento, fiebre leve y, en casos '
                . 'raros, reacción alérgica). Declaro que mi mascota no presenta signos de enfermedad y autorizo su aplicación.',
        ];
    case 'psicologia':
        return [
            'Consentimiento informado para psicoterapia' =>
                'Yo, {paciente}, acepto iniciar un proceso psicoterapéutico en {consultorio}. Se me explicaron el enfoque de '
                . 'trabajo, la duración y frecuencia de las sesiones, los honorarios y la política de cancelación. Entiendo '
                . 'que lo que comparta es confidencial y que esa confidencialidad tiene límites: cuando exista riesgo para mi '
                . 'vida o la de terceros, sospecha de maltrato a menores o personas vulnerables, o mandato de autoridad '
                . 'judicial. Sé que puedo terminar el proceso cuando lo decida.',
            'Consentimiento para atención de menores' =>
                'Como madre, padre o tutor(a) de {paciente}, autorizo su atención psicológica en {consultorio}. Entiendo que, '
                . 'para favorecer el proceso, el contenido de las sesiones del menor es confidencial y se me compartirán '
                . 'avances y recomendaciones generales, salvo situaciones de riesgo, que se me informarán de inmediato.',
            'Consentimiento para terapia en línea' =>
                'Yo, {paciente}, acepto recibir atención psicológica a distancia por videollamada. Me comprometo a conectarme '
                . 'desde un lugar privado, a no grabar las sesiones sin acuerdo y a compartir un contacto de emergencia. '
                . 'Entiendo los riesgos propios de la comunicación por internet.',
            'Consentimiento para aplicación de pruebas psicológicas' =>
                'Yo, {paciente}, autorizo la aplicación de pruebas psicológicas con fines de evaluación. Se me explicó su '
                . 'propósito, que los resultados son confidenciales y que se me entregará una devolución.',
        ];
    case 'dental':
        $b = 'Yo, {paciente}, declaro que en {consultorio} se me explicó en lenguaje claro ';
        return [
            'Consentimiento para tratamiento dental' =>
                $b . 'el diagnóstico, el plan de tratamiento propuesto, su costo, alternativas y riesgos (sensibilidad, '
                . 'inflamación, molestias temporales). Autorizo el uso de anestesia local y la toma de radiografías necesarias.',
            'Consentimiento para extracción dental' =>
                $b . 'la necesidad de la extracción, sus riesgos (sangrado, inflamación, infección, alveolitis, lesión de '
                . 'nervio o de dientes vecinos) y los cuidados posteriores. Informé mis enfermedades y medicamentos. Autorizo el procedimiento.',
            'Consentimiento para endodoncia' =>
                $b . 'en qué consiste el tratamiento de conductos, que su pronóstico no es del 100%, que puede requerir '
                . 'retratamiento o extracción, y que el diente necesitará una restauración definitiva. Autorizo el tratamiento.',
            'Consentimiento para ortodoncia' =>
                $b . 'la duración estimada, la importancia de la higiene y de asistir a los ajustes, y los riesgos '
                . '(descalcificación, reabsorción radicular, recidiva sin retenedores). Acepto el tratamiento.',
        ];
    case 'estetica':
        $b = 'Yo, {paciente}, declaro que en {consultorio} se me explicó en lenguaje claro ';
        return [
            'Consentimiento para toxina botulínica' =>
                $b . 'el procedimiento, su efecto temporal, y los posibles efectos adversos (moretones, dolor de cabeza, '
                . 'asimetría, caída temporal del párpado). Declaro no estar embarazada ni en lactancia. Autorizo su aplicación.',
            'Consentimiento para rellenos dérmicos' =>
                $b . 'el material a aplicar, su duración aproximada y los riesgos (inflamación, moretones, nódulos, '
                . 'infección y, de forma rara, compromiso vascular). Autorizo el procedimiento y la toma de fotografías clínicas.',
            'Consentimiento para peeling químico' =>
                $b . 'el procedimiento y sus riesgos (enrojecimiento, descamación, manchas). Me comprometo a usar protector '
                . 'solar y a evitar el sol según las indicaciones.',
        ];
    case 'fisioterapia':
        $b = 'Yo, {paciente}, declaro que en {consultorio} se me explicó en lenguaje claro ';
        return [
            'Consentimiento informado para tratamiento de fisioterapia' =>
                $b . 'mi diagnóstico fisioterapéutico, los objetivos y el plan de tratamiento (terapia manual, agentes físicos, '
                . 'ejercicio terapéutico y educación), su duración estimada y sus posibles molestias (dolor o rigidez temporal '
                . 'después de la sesión). Informé mis enfermedades, cirugías, medicamentos y si estoy embarazada o uso marcapasos. '
                . 'Entiendo que puedo suspender el tratamiento cuando lo decida. Autorizo el tratamiento.',
            'Consentimiento para punción seca' =>
                $b . 'que la punción seca consiste en introducir una aguja fina y estéril en puntos gatillo musculares. Sus posibles '
                . 'efectos son dolor durante y después de la técnica, moretones y, de forma rara, mareo o desmayo; de forma muy rara, '
                . 'neumotórax en punciones del tórax. Declaro no tener miedo extremo a las agujas, trastornos de coagulación ni tomar '
                . 'anticoagulantes, o haberlo informado. Autorizo su aplicación.',
            'Consentimiento para electroterapia y agentes físicos' =>
                $b . 'el uso de corrientes, ultrasonido, láser, calor o frío, sus beneficios y riesgos (irritación o quemadura de la '
                . 'piel). Declaro no tener marcapasos, implantes metálicos en la zona, cáncer activo ni embarazo, o haberlo informado. '
                . 'Avisaré de inmediato si siento ardor o molestia intensa.',
            'Consentimiento para terapia manual y manipulación' =>
                $b . 'que las técnicas de movilización y manipulación articular pueden causar dolor o rigidez temporales y, de forma '
                . 'muy rara, complicaciones más serias. Se descartaron las contraindicaciones conocidas. Autorizo su aplicación.',
            'Consentimiento para fotografía y video clínico' =>
                'Yo, {paciente}, autorizo a {consultorio} a tomar fotografías y videos de mi postura, marcha y ejercicios con fines '
                . 'clínicos y de seguimiento. Se guardan en mi expediente y no se publican sin mi autorización por escrito.',
        ];
    case 'pediatria':
        return [
            'Consentimiento de atención pediátrica' =>
                'Como madre, padre o tutor(a) de {paciente}, declaro que en {consultorio} se me explicó el estado de salud '
                . 'del menor y el plan de atención. Autorizo la atención médica, la aplicación de vacunas y los procedimientos '
                . 'que su pediatra considere necesarios.',
        ];
    case 'nutricion':
        return [
            'Consentimiento para atención nutricional' =>
                'Yo, {paciente}, acepto iniciar un plan de atención nutricional en {consultorio}. Entiendo que los resultados '
                . 'dependen de mi apego al plan, que debo informar enfermedades y medicamentos, y que el plan no sustituye la '
                . 'atención de mi médico tratante. Autorizo la toma de medidas antropométricas.',
        ];
    }
    return [];
}

/**
 * Siembra plantillas y servicios del giro en el consultorio. Es aditivo e
 * idempotente: por nombre, nunca duplica ni pisa lo que el consultorio editó.
 * Devuelve ['plantillas' => n, 'servicios' => n].
 */
function giro_kit_aplicar(int $tid, string $giro, ?int $uid = null): array
{
    $n = ['plantillas' => 0, 'servicios' => 0];
    $pdo = db();

    try {
        $ya = $pdo->prepare('SELECT LOWER(nombre) FROM plantillas_consulta WHERE consultorio_id = ?');
        $ya->execute([$tid]);
        $existen = array_flip($ya->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare('INSERT INTO plantillas_consulta (consultorio_id, nombre, especialidad, tipo, motivo, exploracion, diagnostico, tratamiento, receta, notas, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach (giro_kit_plantillas($giro) as [$nombre, $esp, $tipo, $mot, $exp, $dx, $tx, $rx, $notas]) {
            if (isset($existen[mb_strtolower($nombre)])) continue;
            $ins->execute([$tid, $nombre, $esp, $tipo, $mot ?: null, $exp ?: null, $dx ?: null, $tx ?: null, $rx ?: null, $notas ?: null, $uid]);
            $n['plantillas']++;
        }
    } catch (Throwable $e) { /* tabla de plantillas aún no migrada */ }

    try {
        $ya = $pdo->prepare('SELECT LOWER(nombre) FROM servicios WHERE consultorio_id = ?');
        $ya->execute([$tid]);
        $existen = array_flip($ya->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare('INSERT INTO servicios (consultorio_id, nombre, categoria, precio, duracion_min, aplica_diente, activo) VALUES (?,?,?,0,?,?,1)');
        foreach (giro_kit_servicios($giro) as [$nombre, $cat, $min, $diente]) {
            if (isset($existen[mb_strtolower($nombre)])) continue;
            $ins->execute([$tid, $nombre, $cat, $min, $diente]);
            $n['servicios']++;
        }
    } catch (Throwable $e) { /* catálogo de servicios aún no migrado */ }

    return $n;
}
