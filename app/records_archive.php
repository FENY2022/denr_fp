<?php
declare(strict_types=1);

/**
 * Records archiving of a completed Step 3 handoff only.
 * This is NOT the final Step 11 permit archive; never delete or close an FP case.
 */
function records_archive_schema_ready(): bool
{
    try {
        db()->query('SELECT application_id,archived_at,archived_by_user_id FROM permit_records_archives LIMIT 0');
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS Records Archiving migration 023 missing: '.$e->getMessage());
        return false;
    }
}
function records_archive_require_officer(): array
{
    $user=step3_require_officer();
    if (!records_archive_schema_ready()) {
        show_error('Records Archiving is unavailable. Back up the database and apply migration 023.',503);
    }
    return $user;
}
/** Only completed, forwarded Step 3 handoffs may be archived by Records. */
function records_archive_eligible(string $status,bool $forwarded,bool $archived): bool
{
    return $status==='forwarded_to_lpdd' && $forwarded && !$archived;
}
function records_archive_entry(int $applicationId): ?array
{
    if (!records_archive_schema_ready()) return null;
    return query_one(
        'SELECT ra.*,u.name AS archived_by_name
         FROM permit_records_archives ra
         LEFT JOIN users u ON u.id=ra.archived_by_user_id
         WHERE ra.application_id=?',[$applicationId]);
}
function records_archive_action(array $user,int $applicationId,string $remarks): array
{
    if (($user['user_kind'] ?? '')!=='internal' || ($user['status'] ?? '')!=='active'
        || !has_role($user,'ro_receiving')) {
        return ['Only active Records/RO Receiving personnel may archive a record.'];
    }
    if ($applicationId<1) return ['Invalid application ID.'];
    if (!records_archive_schema_ready() || !step3_schema_ready()) {
        return ['Apply database migration 023 after migration 022 first.'];
    }
    $remarks=trim($remarks);
    if (strlen($remarks)>2000) return ['Archive remarks cannot exceed 2,000 bytes.'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one('SELECT a.* FROM permit_step2_applications a WHERE a.id=? FOR UPDATE',
            [$applicationId]);
        $movement=query_one('SELECT forwarded_at FROM permit_step3_movements
            WHERE application_id=?',[$applicationId]);
        $archived=query_one('SELECT application_id FROM permit_records_archives
            WHERE application_id=?',[$applicationId]);
        if (!$app || !records_archive_eligible((string)$app['status'],
            !empty($movement['forwarded_at']),$archived!==null)) {
            $pdo->rollBack();
            return ['Only completed, unarchived RO Step 3 handoffs can be archived.'];
        }
        $pdo->prepare('INSERT INTO permit_records_archives
            (application_id,archived_at,archived_by_user_id,archive_remarks)
            VALUES (?,NOW(),?,?)')->execute([
                $applicationId,(int)$user['id'],$remarks!==''?$remarks:null
            ]);
        $pdo->prepare("INSERT INTO permit_step2_events
            (application_id,actor_user_id,action,revision,remarks)
            VALUES (?,?,'ro_archived',?,?)")->execute([
                $applicationId,(int)$user['id'],(int)$app['revision'],
                $remarks!==''?$remarks:'Records archived the completed Step 3 handoff.'
            ]);
        audit((int)$user['id'],'permit.records.archived','permit_step2_application',
            $applicationId,['revision'=>(int)$app['revision'],'remarks'=>$remarks]);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Records archive action failed: '.$e->getMessage());
        return ['Archiving could not be saved. Confirm migration 023 and try again.'];
    }
}
