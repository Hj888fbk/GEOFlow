<?php

$base = require __DIR__.'/../en/hengjia_content.php';

return array_replace_recursive($base, [
    'module' => 'Centro de contenido Hengjia',
    'navigation' => [
        'label' => 'Flujos de contenido Hengjia',
        'today' => 'Trabajo de hoy', 'tasks' => 'Tareas de contenido', 'evidence' => 'Evidencias',
        'studio' => 'Estudio de contenido', 'previews' => 'Vistas multicanal', 'publishing' => 'Publicación',
        'accounts' => 'Cuentas y plataformas', 'governance' => 'Prompts y fuentes',
    ],
    'pages' => [
        'today' => ['title' => 'Trabajo de hoy', 'subtitle' => 'Revisa tareas vencidas, brechas de evidencia, ejecuciones y el estado real de entrega.'],
        'tasks' => ['title' => 'Tareas de contenido', 'subtitle' => 'Define producto, audiencia, intención, función de página y cuentas antes de generar.'],
        'evidence' => ['title' => 'Área de evidencias', 'subtitle' => 'Registra afirmaciones, permisos y cualificaciones con fuentes trazables.'],
        'studio' => ['title' => 'Estudio de contenido', 'subtitle' => 'Genera paquetes gobernados, revisa bloqueos y crea borradores de Article.'],
        'previews' => ['title' => 'Vistas multicanal', 'subtitle' => 'Compara campos reales, límites y cuentas antes de aprobar.'],
        'publishing' => ['title' => 'Centro de publicación', 'subtitle' => 'Aprueba cada canal, prepara borradores seguros y recupera recibos reales.'],
        'accounts' => ['title' => 'Cuentas y plataformas', 'subtitle' => 'Administra varias cuentas sin guardar cookies ni sesiones del navegador.'],
        'governance' => ['title' => 'Gobierno de prompts y fuentes', 'subtitle' => 'Revisa versiones, fuentes aprobadas y ejecuciones sin reemplazar la versión activa.'],
    ],
    'status_guard' => [
        'title' => 'Límite de estados',
        'body' => 'Candidato, borrador y aprobado no significan publicado. Publicación, rastreo, indexación, mención de IA y leads se registran por separado.',
    ],
    'common' => [
        'status' => 'Estado', 'actions' => 'Acciones', 'source' => 'Fuente', 'scope' => 'Alcance',
        'account' => 'Cuenta', 'channel' => 'Canal', 'blockers' => 'Bloqueos', 'none' => 'Ninguno',
        'save' => 'Guardar', 'approve' => 'Aprobar', 'refresh' => 'Actualizar', 'verify' => 'Verificar', 'disable' => 'Desactivar',
        'not_available' => 'No conectado', 'not_recorded' => 'Sin registrar',
    ],
    'status' => [
        'candidate' => 'Candidato', 'blocked' => 'Bloqueado', 'ready' => 'Listo', 'in_review' => 'En revisión',
        'approved' => 'Aprobado', 'draft' => 'Borrador', 'promoted' => 'Borrador Article creado',
        'published' => 'Publicado según lectura', 'queued' => 'En cola',
        'awaiting_manual_confirmation' => 'Pendiente de confirmación humana', 'failed' => 'Fallido',
        'connected' => 'Conectado', 'verified' => 'Verificado', 'not_verified' => 'No verificado', 'disabled' => 'Desactivado',
        'rejected' => 'Rechazado',
    ],
]);
