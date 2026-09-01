<?php

$base = require __DIR__.'/../en/hengjia_content.php';

return array_replace_recursive($base, [
    'module' => 'Central de Conteúdo Hengjia',
    'navigation' => [
        'label' => 'Fluxos de conteúdo Hengjia',
        'today' => 'Trabalho de hoje', 'tasks' => 'Tarefas de conteúdo', 'evidence' => 'Evidências',
        'studio' => 'Estúdio de conteúdo', 'previews' => 'Pré-visualizações', 'publishing' => 'Publicação',
        'accounts' => 'Contas e plataformas', 'governance' => 'Prompts e fontes',
    ],
    'pages' => [
        'today' => ['title' => 'Trabalho de hoje', 'subtitle' => 'Revise tarefas vencidas, lacunas de evidência, execuções e o estado real da entrega.'],
        'tasks' => ['title' => 'Tarefas de conteúdo', 'subtitle' => 'Defina produto, público, intenção, função da página e contas antes de gerar.'],
        'evidence' => ['title' => 'Área de evidências', 'subtitle' => 'Registre alegações, permissões e qualificações com fontes rastreáveis.'],
        'studio' => ['title' => 'Estúdio de conteúdo', 'subtitle' => 'Gere pacotes governados, revise bloqueios e crie rascunhos de Article.'],
        'previews' => ['title' => 'Pré-visualizações multicanal', 'subtitle' => 'Compare campos reais, limites e contas antes da aprovação.'],
        'publishing' => ['title' => 'Central de publicação', 'subtitle' => 'Aprove cada canal, prepare rascunhos seguros e leia recibos reais.'],
        'accounts' => ['title' => 'Contas e plataformas', 'subtitle' => 'Gerencie várias contas sem armazenar cookies ou sessões do navegador.'],
        'governance' => ['title' => 'Governança de prompts e fontes', 'subtitle' => 'Revise versões, fontes aprovadas e execuções sem trocar a receita ativa.'],
    ],
    'status_guard' => [
        'title' => 'Limite de estados',
        'body' => 'Candidato, rascunho e aprovado não significam publicado. Publicação, rastreamento, indexação, menção por IA e leads são registrados separadamente.',
    ],
    'common' => [
        'status' => 'Status', 'actions' => 'Ações', 'source' => 'Fonte', 'scope' => 'Escopo',
        'account' => 'Conta', 'channel' => 'Canal', 'blockers' => 'Bloqueios', 'none' => 'Nenhum',
        'save' => 'Salvar', 'approve' => 'Aprovar', 'refresh' => 'Atualizar', 'verify' => 'Verificar', 'disable' => 'Desativar',
        'not_available' => 'Não conectado', 'not_recorded' => 'Não registrado',
    ],
    'status' => [
        'candidate' => 'Candidato', 'blocked' => 'Bloqueado', 'ready' => 'Pronto', 'in_review' => 'Em revisão',
        'approved' => 'Aprovado', 'draft' => 'Rascunho', 'promoted' => 'Rascunho Article criado',
        'published' => 'Publicado por leitura', 'queued' => 'Na fila',
        'awaiting_manual_confirmation' => 'Aguardando confirmação humana', 'failed' => 'Falhou',
        'connected' => 'Conectado', 'verified' => 'Verificado', 'not_verified' => 'Não verificado', 'disabled' => 'Desativado',
        'rejected' => 'Rejeitado',
    ],
]);
