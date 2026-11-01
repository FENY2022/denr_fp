<?php
declare(strict_types=1);

/** DENR username format; distinct from applicant email addresses. */
function staff_username_valid(string $username): bool
{
    return preg_match('/^[a-z][a-z0-9._]{2,39}$/D', $username) === 1;
}

/**
 * Stable ID suffix guarantees that equally named personnel get distinct
 * identifiers, including imported Pending Setup records. Existing values stay.
 */
function staff_username_generated(string $name, int $id): string
{
    $base = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $name));
    $base = substr($base, 0, 25);
    if ($base === '' || !preg_match('/^[a-z]/',$base)) $base = 'denr' . $base;
    return $base . (string)$id;
}

function staff_username_taken(string $username, int $exceptId = 0): bool
{
    return query_one(
        'SELECT id FROM users WHERE LOWER(username)=? AND id<>? LIMIT 1',
        [strtolower($username),$exceptId]
    ) !== null;
}

/**
 * Called only inside the administrator user-management route to give every
 * legacy/Pending Setup DENR record its own username. Uses a database unique
 * index plus locks, never changes status, password, user ID, email or roles.
 */
function staff_username_backfill(): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rows = query_all(
            "SELECT id,name FROM users WHERE user_kind='internal'
             AND (username IS NULL OR TRIM(username)='') ORDER BY id FOR UPDATE"
        );
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $base = staff_username_generated((string)$row['name'], $id);
            $candidate = $base;
            $suffix = 2;
            while (staff_username_taken($candidate,$id)) {
                $tail = (string)$suffix++;
                $candidate = substr($base,0,40-strlen($tail)) . $tail;
            }
            $pdo->prepare("UPDATE users SET username=? WHERE id=? AND user_kind='internal'
                           AND (username IS NULL OR TRIM(username)='')")
                ->execute([$candidate,$id]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Existing account username remains stable unless an admin changes it. */
function staff_username_for_account(string $requested, string $name, int $id): string
{
    $requested = strtolower(trim($requested));
    return $requested !== '' ? $requested : staff_username_generated($name,$id);
}
