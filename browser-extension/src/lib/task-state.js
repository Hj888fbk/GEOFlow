export function activeTaskId(currentTask) {
    if (! ['in_progress', 'draft_filled'].includes(currentTask?.publication?.status)) return null;

    const id = Number(currentTask.publication.id);

    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

export function hasConflictingActiveTask(currentTask, publication) {
    const activeId = activeTaskId(currentTask);
    const candidateId = Number(publication?.id);

    return activeId !== null && activeId !== candidateId;
}

export function resumeClaimedTask(currentTask, publication, nowIso = new Date().toISOString()) {
    if (! ['in_progress', 'draft_filled'].includes(publication?.status)) return currentTask;
    if (hasConflictingActiveTask(currentTask, publication)) return currentTask;

    if (activeTaskId(currentTask) === Number(publication.id)) {
        return { ...currentTask, publication, accountVerified: Boolean(publication.account_verified) || Boolean(currentTask.accountVerified) };
    }

    return {
        publication,
        tabId: null,
        startedAt: publication.claim?.claimed_at || nowIso,
        accountVerified: Boolean(publication.account_verified),
    };
}
